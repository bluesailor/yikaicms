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
#   · 多人同时预检时固定 8080 端口互相抢占，结果都是假的
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

# ───── HTTP 冒烟只管理本次启动的服务器 ─────
# 绝不按端口杀进程：并行预检可能正在使用同一个固定端口。
SRV=''
stop_smoke_server() {
    if [ -n "$SRV" ]; then
        kill "$SRV" >/dev/null 2>&1 || true
        wait "$SRV" 2>/dev/null || true
        SRV=''
    fi
}
trap 'stop_smoke_server' EXIT

# ───── OPENSSL_CONF 自动探测（外审 P2-7）─────
# Windows 便携 PHP 不带默认 openssl.cnf，未设置时 RSA 相关测试
# （DefaultThemeUpdate / LicenseCache）必挂。优先探测 PHP 自带的 extras/ssl。
if [ -z "${OPENSSL_CONF:-}" ]; then
    OPENSSL_CANDIDATE="$(php -r '$d = dirname(PHP_BINARY); foreach (["/extras/ssl/openssl.cnf", "/ssl/openssl.cnf"] as $p) { if (is_file($d . $p)) { echo $d . $p; break; } }' 2>/dev/null || true)"
    if [ -n "$OPENSSL_CANDIDATE" ]; then
        export OPENSSL_CONF="$OPENSSL_CANDIDATE"
    fi
fi

echo "YikaiCMS 并 main 前预检（模式：$MODE）"
echo "============================================================"
[ -n "${OPENSSL_CONF:-}" ] && note "OPENSSL_CONF=$OPENSSL_CONF"

# ───── 1. 单元测试全量 ─────
echo ""
echo "[1/5] PHPUnit 全量"
if [ ! -d vendor/phpunit ]; then
    fail "vendor 未安装，先跑 composer install"
else
    PHPUNIT_LOG="${TMPDIR:-/tmp}/yikaicms-premerge-phpunit-$$.log"
    if php vendor/phpunit/phpunit/phpunit --colors=never >"$PHPUNIT_LOG" 2>&1; then
        OUT=$(tail -3 "$PHPUNIT_LOG")
        if echo "$OUT" | grep -q "^OK"; then
            pass "$(echo "$OUT" | grep '^OK' | head -1)"
            if echo "$OUT" | grep -qi 'tests were skipped'; then
                note "PHPUnit 报告跳过项；完整输出：$PHPUNIT_LOG"
            else
                rm -f "$PHPUNIT_LOG"
            fi
        else
            fail "PHPUnit 退出成功但未找到通过摘要；完整输出：$PHPUNIT_LOG"
            tail -20 "$PHPUNIT_LOG" | sed 's/^/      /'
        fi
    else
        fail "PHPUnit 未通过；完整输出：$PHPUNIT_LOG"
        tail -20 "$PHPUNIT_LOG" | sed 's/^/      /'
    fi
fi

# ───── 1b. 前端单测（与 CI 同一调用方式） ─────
# CI 里跑的是 `cd tests/js && node --test`，但本仓库的发布流程只推私有备份库、
# 不开 PR，CI 实际从不触发——预检就是 CI 的唯一替身，漏掉这一项等于没人跑前端测试。
# 2026-09-22 因此漏掉过 6 个失败用例：方法被抽进 partial 后测试取不到，一直红着没人知道。
echo ""
echo "[1b] 前端单测（node --test）"
if ! command -v node >/dev/null 2>&1; then
    note "未找到 node，跳过前端单测（CI 仍会跑）"
else
    OUT=$( (cd tests/js && node --test) 2>&1 | grep -E '^# (tests|pass|fail)|^ℹ (tests|pass|fail)' )
    if echo "$OUT" | grep -qE '(^# fail 0$|^ℹ fail 0$)'; then
        pass "$(echo "$OUT" | grep -E '(^# pass|^ℹ pass)' | head -1)"
    else
        fail "前端单测未通过"
        (cd tests/js && node --test) 2>&1 | grep -E '^(✖|not ok)' | head -20 | sed 's/^/      /'
    fi
fi

# ───── 2. Psalm 全量（过滤 config.php 幻影 + gitignored 的本机开发文件） ─────
echo ""
echo "[2/5] Psalm 静态分析"
# 过滤口径与 release-process.md 一致。除 config.php（CI 上不存在）外，还要排除
# **gitignored 的本机开发文件**：blox_editor*、plugins/*/admin-local.php 不入库，
# CI 根本扫不到它们，本地却会报错——不排除的话每台开发机结果都不一样。
PSALM_IGNORE='blox_editor|admin-local|config\.php'
PSALM_OUTPUT=''
PSALM_EXIT=0
run_psalm() {
    PSALM_OUTPUT=$(php vendor/vimeo/psalm/psalm --no-progress 2>&1)
    PSALM_EXIT=$?
}
psalm_errors() {
    printf '%s\n' "$PSALM_OUTPUT" | grep ERROR | grep -vcE "$PSALM_IGNORE"
}
run_psalm
N=$(psalm_errors)
if [ "$N" != "0" ]; then
    # 已知模式：Psalm 缓存会对存在的类误报 UndefinedClass。清缓存后仍在才算真错。
    note "首轮 $N 个错误，清缓存复验（本项目已知的缓存幻影模式）…"
    php vendor/vimeo/psalm/psalm --clear-cache >/dev/null 2>&1
    run_psalm
    N=$(psalm_errors)
fi
if [ "$N" = "0" ] && { [ "$PSALM_EXIT" = "0" ] || printf '%s\n' "$PSALM_OUTPUT" | grep -q ERROR; }; then
    pass "跟踪源码 0 ERROR"
elif [ "$N" = "0" ]; then
    fail "Psalm 执行失败（退出码 $PSALM_EXIT，未产生可过滤的 ERROR）"
    printf '%s\n' "$PSALM_OUTPUT" | tail -12 | sed 's/^/      /'
else
    fail "Psalm 有 $N 个错误"
    printf '%s\n' "$PSALM_OUTPUT" | grep -A3 ERROR | grep -vE "$PSALM_IGNORE" | head -12 | sed 's/^/      /'
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
    HTTP_PORT="${PREMERGE_HTTP_PORT:-$(php -r '$s = @stream_socket_server("tcp://127.0.0.1:0", $errno, $error); if (!$s) { exit(1); } $name = stream_socket_get_name($s, false); fclose($s); echo substr(strrchr($name, ":"), 1);')}"
    if ! [[ "$HTTP_PORT" =~ ^[0-9]+$ ]] || [ "$HTTP_PORT" -lt 1024 ] || [ "$HTTP_PORT" -gt 65535 ]; then
        fail "无法选取有效冒烟端口（PREMERGE_HTTP_PORT 可显式指定）"
        HTTP_PORT=''
    fi
    if [ -n "$HTTP_PORT" ]; then
        export SMOKE_BASE="http://127.0.0.1:$HTTP_PORT"
        export SMOKE_SITE_URL="$SMOKE_BASE"
    fi
    php tests/smoke/setup.php >/dev/null 2>&1 || { fail "冒烟装机失败"; }
    HTTP_READY=0
    if [ -n "$HTTP_PORT" ]; then
        php -S "127.0.0.1:$HTTP_PORT" -t . >/dev/null 2>&1 &
        SRV=$!
        # 等就绪：用 PHP 探测而不是 curl（WSL 的 curl 连不上 Windows php.exe 的回环）
        for _ in $(seq 1 20); do
            kill -0 "$SRV" 2>/dev/null || break
            if php -r 'exit(@file_get_contents("http://127.0.0.1:" . (int) $argv[1] . "/admin/login.php") !== false ? 0 : 1);' "$HTTP_PORT" 2>/dev/null; then
                kill -0 "$SRV" 2>/dev/null && HTTP_READY=1
                break
            fi
            sleep 1
        done
        [ "$HTTP_READY" = "1" ] || fail "本次启动的冒烟服务器未就绪（端口 $HTTP_PORT）"
    fi

    run_smoke() {
        local script="$1" label="$2"
        [ "$HTTP_READY" = "1" ] || { note "$label 因冒烟服务器未就绪而跳过"; return; }
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
    stop_smoke_server
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
