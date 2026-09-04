#!/usr/bin/env bash
# ============================================================
# 发行包装机冒烟（package-install-test）
#
#   CI 只验证「源码树」能装、以及 zip 的文件清单对不对，
#   **从来没有把 zip 真的装起来过**——v1.19.1 的坏包事故正是这个盲区。
#   本脚本补的就是这一层：解包 → 真实 HTTP 安装器 → 后台 CRUD/全页冒烟。
#
# 用法：
#   bash tools/package-install-test.sh --php=8.2 --db=sqlite
#   bash tools/package-install-test.sh --php=8.0 --db=mysql
#   bash tools/package-install-test.sh --matrix            # 跑预设三腿
#   bash tools/package-install-test.sh --php=8.5 --db=mysql --keep   # 失败后保留现场
#   bash tools/package-install-test.sh --php=8.0 --db=mysql --base=http://ykpkg80.yikai
#       ↑ vhost 模式：不起 php -S，改用 phpStudy 的 Apache（能顺带测 .htaccess 伪静态）
#
# 约定：
#   · 解包目录固定 D:\phpstudy_pro\WWW\ykpkgtest（同时可直接当 vhost docroot）
#   · MySQL 用独立库 ykpkgtest，跑完 DROP；绝不碰其它库
#   · 端口 8099（premerge-check 用 8080，避免互相踩）
#   · 跑 smoke 脚本的 CLI php 固定用默认 php（它们只是 HTTP 客户端）；
#     被测的 PHP 版本由 php -S / vhost 决定
# ============================================================

set -u
set -o pipefail

if [ -t 1 ]; then R=$'\033[31m'; G=$'\033[32m'; Y=$'\033[33m'; B=$'\033[34m'; D=$'\033[2m'; X=$'\033[0m'
else R=''; G=''; Y=''; B=''; D=''; X=''; fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

PHP_ROOT="/mnt/d/phpstudy_pro/Extensions/php"
UNPACK_WSL="/mnt/d/phpstudy_pro/WWW/ykpkgtest"
UNPACK_WIN="D:/phpstudy_pro/WWW/ykpkgtest"
PORT=8099
DB_NAME="ykpkgtest"
MYSQL_HOST="127.0.0.1"
MYSQL_PORT="3306"
MYSQL_USER="root"
MYSQL_PASS="123456"
ADMIN_USER="admin"
ADMIN_PASS="smoke@Test123"

PHP_LEG=""; DB_KIND="sqlite"; ZIP=""; BASE=""; KEEP=0; MATRIX=0; SITE_LANG="zh-CN"
for arg in "$@"; do
    case "$arg" in
        --php=*)  PHP_LEG="${arg#*=}" ;;
        --db=*)   DB_KIND="${arg#*=}" ;;
        --zip=*)  ZIP="${arg#*=}" ;;
        --base=*) BASE="${arg#*=}" ;;
        --lang=*) SITE_LANG="${arg#*=}" ;;
        --keep)   KEEP=1 ;;
        --matrix) MATRIX=1 ;;
        *) echo "${R}未知参数: $arg${X}"; exit 2 ;;
    esac
done

php_dir_for() {
    case "$1" in
        8.0) echo "$PHP_ROOT/php8.0.2nts" ;;
        8.2) echo "$PHP_ROOT/php8.2.9nts" ;;
        8.4) echo "$PHP_ROOT/php8.4.24nts" ;;
        8.5) echo "$PHP_ROOT/php8.5.9nts" ;;
        *)   echo "" ;;
    esac
}

# ───── 预设矩阵：一次跑完三腿 ─────
if [ "$MATRIX" = "1" ]; then
    rc=0
    # P0=8.2/sqlite（最小闭环）  P1=8.0/mysql（客户真实组合）  P2=8.5/mysql（已有真实用户）
    for leg in "8.2:sqlite" "8.0:mysql" "8.5:mysql"; do
        echo
        echo "${B}════════ 矩阵腿：PHP ${leg%%:*} / ${leg##*:} ════════${X}"
        bash "$0" --php="${leg%%:*}" --db="${leg##*:}" ${ZIP:+--zip="$ZIP"} || rc=1
    done
    echo
    [ "$rc" = "0" ] && echo "${G}✓ 矩阵全绿${X}" || echo "${R}✗ 矩阵存在失败腿${X}"
    exit $rc
fi

[ -n "$PHP_LEG" ] || { echo "${R}必须指定 --php=8.0|8.2|8.4|8.5（或 --matrix）${X}"; exit 2; }
PHP_DIR="$(php_dir_for "$PHP_LEG")"
[ -n "$PHP_DIR" ] && [ -x "$PHP_DIR/php.exe" ] || { echo "${R}找不到 PHP $PHP_LEG：$PHP_DIR/php.exe${X}"; exit 2; }
case "$DB_KIND" in sqlite|mysql) ;; *) echo "${R}--db 只能是 sqlite 或 mysql${X}"; exit 2 ;; esac

# 必须用相对路径：WSL 里的 php 是 Windows php.exe，它把 /mnt/d/... 当成
# 当前盘符下的 D:\mnt\d\...，绝对路径必然找不到文件（见 yikai-precheck skill）。
VERSION="$(php -r "require 'config/version.php'; echo CMS_VERSION;" 2>/dev/null)"
[ -n "$ZIP" ] || ZIP="releases/yikaicms-v${VERSION}.zip"

echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
echo "${B}  发行包装机冒烟 — v${VERSION} / PHP ${PHP_LEG} / ${DB_KIND}${X}"
echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
echo "${D}  包    : $ZIP${X}"
echo "${D}  PHP   : $PHP_DIR/php.exe${X}"
echo "${D}  解包到: $UNPACK_WSL${X}"

FAIL=0
ok()   { echo "  ${G}✓${X} $1"; }
bad()  { echo "  ${R}✗${X} $1"; FAIL=1; }
note() { echo "  ${D}· $1${X}"; }

# ───── SQLite 腿的前置：目标 PHP 必须有 pdo_sqlite ─────
if [ "$DB_KIND" = "sqlite" ]; then
    if ! "$PHP_DIR/php.exe" -m 2>/dev/null | grep -qi '^pdo_sqlite$'; then
        echo "${R}PHP $PHP_LEG 未启用 pdo_sqlite${X}"
        echo "${Y}修复：在 $PHP_DIR/php.ini 里去掉 ;extension=pdo_sqlite 与 ;extension=sqlite3 的分号${X}"
        exit 2
    fi
fi

kill_port() {
    local pids
    pids=$(cmd.exe /c "netstat -ano | findstr :$PORT | findstr LISTENING" 2>/dev/null \
           | awk '{print $NF}' | tr -d '\r' | sort -u)
    for p in $pids; do cmd.exe /c "taskkill /F /PID $p" >/dev/null 2>&1; done
}

mysql_exec() {
    php -r '
      $sql = $argv[1];
      try {
          $p = new PDO("mysql:host='"$MYSQL_HOST"';port='"$MYSQL_PORT"'", "'"$MYSQL_USER"'", "'"$MYSQL_PASS"'");
          $p->exec($sql);
          exit(0);
      } catch (Throwable $e) { fwrite(STDERR, $e->getMessage()); exit(1); }
    ' "$1"
}

cleanup() {
    [ -z "$BASE_INTERNAL" ] || kill_port
    if [ "$KEEP" = "1" ]; then
        note "--keep：保留解包目录 $UNPACK_WSL 与库 $DB_NAME"
        return
    fi
    [ "$DB_KIND" = "mysql" ] && mysql_exec "DROP DATABASE IF EXISTS \`$DB_NAME\`" >/dev/null 2>&1
    rm -rf "$UNPACK_WSL" "$ROOT_DIR/.pkgtest" 2>/dev/null
}
BASE_INTERNAL=""
trap cleanup EXIT

# ───── L1：产物自检 ─────
echo
echo "${B}[L1] 产物自检${X}"
if [ ! -f "$ZIP" ]; then
    bad "找不到发行包：$ZIP（先跑 bash build.sh）"
    exit 1
fi
ok "包存在（$(du -h "$ZIP" | cut -f1)）"
if [ -f "${ZIP%.zip}.sha256" ]; then
    (cd "$(dirname "$ZIP")" && sha256sum -c "$(basename "${ZIP%.zip}.sha256")" >/dev/null 2>&1) \
      && ok "sha256 校验通过" || bad "sha256 校验失败"
fi
if php tools/release-artifact-smoke.php "$ZIP" >/tmp/pkgsmoke.log 2>&1; then
    ok "release-artifact-smoke 通过"
else
    bad "release-artifact-smoke 失败"; sed 's/^/      /' /tmp/pkgsmoke.log | head -20
fi
for forbidden in "tests" "config/config.php" ".git" "docs"; do
    if unzip -l "$ZIP" 2>/dev/null | grep -qE "/${forbidden}(/|$)"; then
        bad "包内混入了不该有的路径：$forbidden"
    fi
done
ok "包内无 tests/config.php/.git/docs 残留"
[ "$FAIL" = "0" ] || { echo; echo "${R}L1 未通过，不继续装机${X}"; exit 1; }

# ───── L2：解包 + 真实安装器 ─────
echo
echo "${B}[L2] 装机冒烟${X}"
rm -rf "$UNPACK_WSL"; mkdir -p "$UNPACK_WSL"
unzip -q "$ZIP" -d "$UNPACK_WSL" || { bad "解包失败"; exit 1; }
INNER="$(find "$UNPACK_WSL" -maxdepth 1 -mindepth 1 -type d | head -1)"
[ -n "$INNER" ] && [ -f "$INNER/install/index.php" ] || { bad "解包目录里没有 install/index.php"; exit 1; }
# 把内层目录提到 docroot，vhost 模式下 DocumentRoot 才能直接指向它
mv "$INNER"/* "$INNER"/.[!.]* "$UNPACK_WSL"/ 2>/dev/null; rmdir "$INNER" 2>/dev/null
ok "已解包到 docroot（$(find "$UNPACK_WSL" -type f | wc -l) 个文件）"

if [ -n "$BASE" ]; then
    note "vhost 模式：使用 $BASE（请确认其 DocumentRoot 指向 $UNPACK_WIN 且绑定 PHP $PHP_LEG）"
else
    # WSL 下必须绑 0.0.0.0 并用网关 IP 访问：php.exe 是 Windows 进程，
    # 它的 127.0.0.1 与 WSL 的回环不是同一个，绑 127.0.0.1 则 curl/Playwright 全都够不着。
    HOST_IP="127.0.0.1"
    if grep -qi microsoft /proc/version 2>/dev/null; then
        HOST_IP="$(ip route show default | awk '{print $3}' | head -1)"
        [ -n "$HOST_IP" ] || HOST_IP="127.0.0.1"
    fi
    BASE="http://$HOST_IP:$PORT"; BASE_INTERNAL=1
    kill_port
    ( cd "$UNPACK_WSL" && "$PHP_DIR/php.exe" -S "0.0.0.0:$PORT" -t "$UNPACK_WIN" >/tmp/pkgserver.log 2>&1 & )
    for i in $(seq 1 40); do
        curl -sf "$BASE/install/index.php" >/dev/null 2>&1 && break
        sleep 0.5
    done
    curl -sf "$BASE/install/index.php" >/dev/null 2>&1 \
      && ok "php -S 已就绪（PHP $PHP_LEG，端口 $PORT）" \
      || { bad "服务器起不来（$BASE）"; sed 's/^/      /' /tmp/pkgserver.log | head -10; exit 1; }
fi

# 真实 PHP 版本自证（不是猜，是问服务器要）
SERVED_PHP="$(curl -sf "$BASE/install/index.php" -o /dev/null -w '%{http_code}' 2>/dev/null)"
note "install/index.php 响应码 $SERVED_PHP"

INSTALL_ARGS=(--data-urlencode "action=install"
    --data-urlencode "db_prefix=yikai_"
    --data-urlencode "admin_user=$ADMIN_USER"
    --data-urlencode "admin_pass=$ADMIN_PASS"
    --data-urlencode "admin_email=pkgtest@example.test"
    --data-urlencode "site_name=YikaiCMS PkgTest"
    --data-urlencode "site_url=$BASE"
    --data-urlencode "site_lang=$SITE_LANG"
    --data-urlencode "admin_lang=$SITE_LANG"
    --data-urlencode "install_demo=1")

if [ "$DB_KIND" = "mysql" ]; then
    mysql_exec "DROP DATABASE IF EXISTS \`$DB_NAME\`" || { bad "无法连接本机 MySQL"; exit 1; }
    mysql_exec "CREATE DATABASE \`$DB_NAME\` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci" \
      && ok "已建临时库 $DB_NAME（结束时 DROP）" || { bad "建库失败"; exit 1; }
    INSTALL_ARGS+=(--data-urlencode "db_driver=mysql"
        --data-urlencode "db_host=$MYSQL_HOST" --data-urlencode "db_port=$MYSQL_PORT"
        --data-urlencode "db_name=$DB_NAME"
        --data-urlencode "db_user=$MYSQL_USER" --data-urlencode "db_pass=$MYSQL_PASS")
else
    INSTALL_ARGS+=(--data-urlencode "db_driver=sqlite")
fi

RESP="$(curl -fsS -X POST "$BASE/install/index.php" "${INSTALL_ARGS[@]}" 2>/tmp/pkginstall.log)" || true
# 经文件而非环境变量传给 php：WSL 里的 php 是 Windows php.exe，它不继承 WSL 的环境变量，
# getenv() 恒为 false，会把成功的安装误判成失败。文件路径也必须是相对的（同上）。
mkdir -p .pkgtest && printf '%s' "$RESP" > .pkgtest/install-response.json
if php -r '
    $p = json_decode((string) @file_get_contents(".pkgtest/install-response.json"), true);
    exit(is_array($p) && !empty($p["success"]) ? 0 : 1);
'; then
    ok "真实 HTTP 安装器安装成功（$DB_KIND / $SITE_LANG / 含演示数据）"
else
    bad "安装失败"
    echo "      响应: $(echo "$RESP" | head -c 400)"
    sed 's/^/      /' /tmp/pkginstall.log | head -5
    exit 1
fi

# ───── L2b：后台冒烟（跑在包站上，不是源码树）─────
echo
echo "${B}[L2b] 后台冒烟（对象 = 解包站）${X}"
if php tests/smoke/admin_crud.php --base="$BASE" --root="$UNPACK_WIN" >/tmp/pkgcrud.log 2>&1; then
    ok "admin_crud 通过"
else
    bad "admin_crud 失败"; tail -25 /tmp/pkgcrud.log | sed 's/^/      /'
fi
if php tests/smoke/admin_pages.php --base="$BASE" --root="$UNPACK_WIN" >/tmp/pkgpages.log 2>&1; then
    ok "admin_pages 通过（$(grep -oE '[0-9]+ 页' /tmp/pkgpages.log | tail -1)）"
else
    bad "admin_pages 失败"; tail -25 /tmp/pkgpages.log | sed 's/^/      /'
fi

# ───── L2c：前台可达 ─────
FRONT="$(curl -sf -o /dev/null -w '%{http_code}' "$BASE/" 2>/dev/null)"
[ "$FRONT" = "200" ] && ok "前台首页 200" || bad "前台首页返回 $FRONT"

echo
if [ "$FAIL" = "0" ]; then
    echo "${G}✓ PHP $PHP_LEG / $DB_KIND：发行包装机冒烟通过${X}"
else
    echo "${R}✗ PHP $PHP_LEG / $DB_KIND：存在失败项${X}"
fi
exit $FAIL
