#!/usr/bin/env bash
#
# 后台 i18n HTTP 响应态扫描 —— 一条命令跑完（备份状态 → 英文扫描 → 完整还原）。
#
#   bash tools/scan_admin_i18n.sh              # 摘要
#   bash tools/scan_admin_i18n.sh -v           # 每页完整片段清单
#   bash tools/scan_admin_i18n.sh -v > /tmp/scan.txt
#
# 原理与修复模式见 yikaicms-docs/admin-i18n-audit-methodology-2026-08-09.md。
#
# 为什么要有这个脚本：扫描需要 smoke 环境（临时 SQLite 库）+ 英文后台语言 + 起站。
# setup 在任何覆盖动作前备份配置、SQLite 库、installed.lock 与 fixture；trap 保证
# 成功、失败或 Ctrl-C 后都调用恢复。i18n 模式不启用或导入 Blox。
#
# ⚠️ 运行期间（约 1-2 分钟）本地站不可用——smoke 环境会替换 config.php。
#    别在别人正用站点时跑。

set -uo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

PORT="${SCAN_PORT:-8080}"
SERVER_PID=""
SETUP_STARTED=0

cleanup() {
    local code=$?
    if [ -n "$SERVER_PID" ]; then
        kill "$SERVER_PID" 2>/dev/null || true
        wait "$SERVER_PID" 2>/dev/null || true
        # WSL 下 php 是 Windows 的 php.exe：上面的 kill 只结束了 WSL 侧的壳，
        # Windows 进程会活下来继续占端口（不收的话每跑一次积一个）。按命令行里
        # 的端口精确匹配，只杀本脚本起的那个。
        if command -v powershell.exe >/dev/null 2>&1; then
            powershell.exe -NoProfile -Command \
                "Get-CimInstance Win32_Process -Filter \"Name='php.exe'\" |
                 Where-Object { \$_.CommandLine -like '*-S 127.0.0.1:$PORT*' } |
                 ForEach-Object { Stop-Process -Id \$_.ProcessId -Force -ErrorAction SilentlyContinue }" \
                >/dev/null 2>&1 || true
        fi
    fi
    if [ "$SETUP_STARTED" = "1" ]; then
        if ! php tests/smoke/setup.php --restore >/dev/null; then
            echo "!! smoke 状态还原失败，请立即手动执行 php tests/smoke/setup.php --restore" >&2
            code=2
        fi
    fi
    exit $code
}
trap cleanup EXIT INT TERM

echo ">> 准备 smoke 环境（会临时替换 config.php）"
SETUP_STARTED=1
SMOKE_SITE_URL="http://127.0.0.1:$PORT" php tests/smoke/setup.php --admin-i18n >/dev/null || { echo "!! smoke setup 失败" >&2; exit 2; }

# ⚠️ 探测一律用 php，不能用 WSL 的 curl：这里的 php 是 Windows 的 php.exe，
# 起的站绑在 Windows 的 127.0.0.1 上，WSL 的 curl 在另一个网络命名空间里连不到
# （扫描器本身也是 php.exe，所以它连得到）。用 curl 探测会永远判定「未就绪」。
probe() { php -r 'exit(@file_get_contents("http://127.0.0.1:" . $argv[1] . $argv[2]) === false ? 1 : 0);' "$PORT" "$1"; }

# 端口被占时 php -S 会静默失败，扫描就跑到别人的服务上去了（结果看着正常、
# 实际不是本仓库的代码）——先探一下，占用就直接停，不猜。
if probe "/"; then
    echo "!! 127.0.0.1:$PORT 已被占用。换端口重跑：SCAN_PORT=8099 bash tools/scan_admin_i18n.sh" >&2
    exit 1
fi

echo ">> 起站 127.0.0.1:$PORT"
php -S "127.0.0.1:$PORT" >/dev/null 2>&1 &
SERVER_PID=$!

# 等站点就绪，最多 15 秒
READY=0
for _ in $(seq 1 30); do
    if probe "/admin/login.php"; then READY=1; break; fi
    sleep 0.5
done
if [ "$READY" != "1" ]; then
    echo "!! 站点未能在 15 秒内就绪" >&2
    exit 1
fi

echo ">> 扫描"
OVERALL=0
for LANG in ${SCAN_LANGS:-en}; do
    case "$LANG" in
        en|ja) ;;
        *) echo "!! 不支持的扫描语言：$LANG（仅支持 en/ja）" >&2; OVERALL=2; continue ;;
    esac
    if ! php tests/e2e/set-lang.php "$LANG" >/dev/null; then
        echo "!! 切换后台语言失败：$LANG" >&2
        OVERALL=2
        continue
    fi
    echo ">> language=$LANG"
    SCAN_PORT="$PORT" php tools/scan_admin_i18n.php --lang="$LANG" "$@"
    STATUS=$?
    if [ "$STATUS" -gt "$OVERALL" ]; then OVERALL=$STATUS; fi
done
exit "$OVERALL"
