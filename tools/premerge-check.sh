#!/usr/bin/env bash
# ============================================================
# YikaiCMS —— 并入 main 前的本地预检（AGENTS.md §14 红线五项）
#
#   bash tools/premerge-check.sh            # 自动判断要不要跑后台冒烟
#   bash tools/premerge-check.sh --full     # 强制全跑（含后台冒烟与升级 e2e）
#   bash tools/premerge-check.sh --quick    # 只跑单测 + Psalm + i18n（不起服务器）
#
# 设计目标：把「每次手工重建命令序列」变成一行。脚本只负责**可靠地执行**，
# 判断（Psalm 幻影、要不要人工过前台）交给人/skill。
#
# 为什么值得有这个脚本 —— 今天踩到的都是执行层面的坑，不是判断层面的：
#   · 工作目录漂移导致 "Could not open input file"（跑了两次才发现）
#   · 8080 端口残留三个 php -S，旧进程拿旧库应答，整轮结果都是假的
#   · setup.php 装机后忘了 --restore，工作树被留在冒烟配置上
# ============================================================

set -u
set -o pipefail

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR" || exit 2

if [ -t 1 ]; then
    R=$'\033[31m'; G=$'\033[32m'; Y=$'\033[33m'; D=$'\033[2m'; X=$'\033[0m'
else
    R=''; G=''; Y=''; D=''; X=''
fi

MODE="auto"
case "${1:-}" in
    --full)  MODE="full" ;;
    --quick) MODE="quick" ;;
    "")      MODE="auto" ;;
    *)       echo "未知参数：$1"; exit 2 ;;
esac

FAILED=()
pass() { echo "  ${G}✓${X} $1"; }
fail() { echo "  ${R}✗${X} $1"; FAILED+=("$1"); }
note() { echo "  ${D}· $1${X}"; }

# ───── 端口清场：残留的 php -S 会用旧库应答，让整轮结果失真 ─────
kill_stale_server() {
    command -v cmd.exe >/dev/null 2>&1 || return 0
    local pids
    pids=$(cmd.exe /c "netstat -ano | findstr :8080 | findstr LISTENING" 2>/dev/null \
           | awk '{print $NF}' | tr -d '\r' | sort -u)
    for p in $pids; do
        cmd.exe /c "taskkill /F /PID $p" >/dev/null 2>&1
    done
    [ -n "$pids" ] && note "清掉 8080 端口上的残留进程：$pids"
    return 0
}

echo "YikaiCMS 并 main 前预检（模式：$MODE）"
echo "============================================================"

# ───── 1. 单元测试全量 ─────
echo ""
echo "[1/5] PHPUnit 全量"
if [ ! -d vendor/phpunit ]; then
    fail "vendor 未安装，先跑 composer install"
else
    OUT=$(php vendor/phpunit/phpunit/phpunit 2>&1 | tail -3)
    if echo "$OUT" | grep -q "^OK "; then
        pass "$(echo "$OUT" | grep '^OK ')"
    else
        fail "PHPUnit 未通过"
        echo "$OUT" | sed 's/^/      /'
    fi
fi

# ───── 2. Psalm 全量（过滤 config.php 幻影 + gitignored 的本机开发文件） ─────
echo ""
echo "[2/5] Psalm 静态分析"
# 过滤口径与 release-process.md 一致。除 config.php（CI 上不存在）外，还要排除
# **gitignored 的本机开发文件**：blox_editor*、plugins/*/admin-local.php 不入库，
# CI 根本扫不到它们，本地却会报错——不排除的话每台开发机结果都不一样。
PSALM_IGNORE='blox_editor|admin-local|config\.php'
psalm_errors() {
    php vendor/vimeo/psalm/psalm --no-progress 2>&1 | grep ERROR | grep -vcE "$PSALM_IGNORE"
}
N=$(psalm_errors)
if [ "$N" != "0" ]; then
    # 已知模式：Psalm 缓存会对存在的类误报 UndefinedClass。清缓存后仍在才算真错。
    note "首轮 $N 个错误，清缓存复验（本项目已知的缓存幻影模式）…"
    php vendor/vimeo/psalm/psalm --clear-cache >/dev/null 2>&1
    N=$(psalm_errors)
fi
if [ "$N" = "0" ]; then
    pass "跟踪源码 0 ERROR"
else
    fail "Psalm 有 $N 个错误"
    php vendor/vimeo/psalm/psalm --no-progress 2>&1 | grep -A3 ERROR | grep -vE "$PSALM_IGNORE" | head -12 | sed 's/^/      /'
fi
note "本地永远比 CI 宽松：CI 无 config/config.php。新增独立入口（自带 define ROOT_PATH +"
note "require config.php 的 admin/*.php、plugins/*/xxx_api.php）必须加进 psalm.xml 的"
note "MissingFile 豁免，否则只有 CI 会红——2026-08-22 这坑踩了两次。"

# ───── 3. i18n 三道门禁 ─────
echo ""
echo "[3/5] i18n 门禁"
for g in check_lang_keys check_blox_i18n check_frontend_i18n; do
    if [ ! -f "tools/$g.php" ]; then
        note "tools/$g.php 不存在，跳过"
        continue
    fi
    if php "tools/$g.php" >/dev/null 2>&1; then
        pass "$g"
    else
        fail "$g 未通过"
        php "tools/$g.php" 2>&1 | tail -6 | sed 's/^/      /'
    fi
done

# ───── 4/5. 需要跑起服务器的冒烟 ─────
NEED_HTTP=0
if [ "$MODE" = "full" ]; then
    NEED_HTTP=1
elif [ "$MODE" = "auto" ]; then
    # 改过 admin/ 或升级链路就必须跑——页面渲染期错误只有真渲染才发现
    if git diff --name-only HEAD 2>/dev/null | grep -qE '^(admin/|includes/(Upgrade|AutoUpgrade))'; then
        NEED_HTTP=1
    elif git diff --cached --name-only 2>/dev/null | grep -qE '^(admin/|includes/(Upgrade|AutoUpgrade))'; then
        NEED_HTTP=1
    fi
fi

if [ "$MODE" = "quick" ] || [ "$NEED_HTTP" = "0" ]; then
    echo ""
    echo "[4/5] 后台页面冒烟 —— ${D}跳过（未改 admin/ 与升级链路；--full 可强制）${X}"
    echo "[5/5] 升级链路 e2e —— ${D}跳过（同上）${X}"
else
    echo ""
    echo "[4/5] 后台页面冒烟 + [5/5] 升级链路 e2e"
    kill_stale_server
    php tests/smoke/setup.php >/dev/null 2>&1 || { fail "冒烟装机失败"; }
    php -S 127.0.0.1:8080 -t . >/dev/null 2>&1 &
    SRV=$!
    # 等就绪：用 PHP 探测而不是 curl（WSL 的 curl 连不上 Windows php.exe 的回环）
    for _ in $(seq 1 20); do
        php -r 'exit(@file_get_contents("http://127.0.0.1:8080/admin/login.php") !== false ? 0 : 1);' 2>/dev/null && break
        sleep 1
    done

    run_smoke() {
        local script="$1" label="$2"
        [ -f "$script" ] || { note "$script 不存在，跳过"; return; }
        if php "$script" >/tmp/premerge_smoke.log 2>&1; then
            pass "$label"
        else
            fail "$label"
            tail -8 /tmp/premerge_smoke.log | sed 's/^/      /'
        fi
    }
    run_smoke tests/smoke/admin_pages.php       "后台页面渲染冒烟"
    run_smoke tests/smoke/upgrade_rollback.php  "升级回滚 e2e"
    run_smoke tests/smoke/auto_upgrade_faults.php "自动升级故障注入"
    run_smoke tests/smoke/permission_matrix.php "权限矩阵"

    # 还原**必须**执行，哪怕上面失败了：否则工作树被留在冒烟配置/库上
    kill_stale_server
    sleep 1
    php tests/smoke/setup.php --restore >/dev/null 2>&1 \
        && note "已还原冒烟前的配置、数据库与安装锁" \
        || fail "冒烟状态还原失败（工作树可能仍是冒烟配置，务必人工检查）"
fi

# ───── 汇总 ─────
echo ""
echo "============================================================"
if [ ${#FAILED[@]} -gt 0 ]; then
    echo "${R}✗ 预检未通过（${#FAILED[@]} 项）${X}"
    for f in "${FAILED[@]}"; do echo "  - $f"; done
    echo ""
    echo "${Y}红线：五项全绿才允许并入 main。CI 是兜底，不是第一道测试。${X}"
    exit 1
fi
echo "${G}✓ 预检通过${X}"
echo ""
echo "${Y}脚本测不了、仍需人工确认的：${X}"
echo "  · 改过前台/主题输出 → 在本树 vhost 里人工过一遍受影响页面"
echo "  · 改过 Tailwind class → 重编 CSS 并 grep 产物确认新类进去了"
echo "  · 提交时逐文件 git add（禁 -A），先看 git status 有没有别人的在建改动"
