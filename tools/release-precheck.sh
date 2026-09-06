#!/usr/bin/env bash
# ============================================================
# Yikai CMS - 发版前预检（release-precheck）
#
# 用法：
#   bash tools/release-precheck.sh 1.7.0
#   bash tools/release-precheck.sh 1.7.0 --candidate
#   bash tools/release-precheck.sh 1.7.0 --candidate --baseline=v1.6.9
#   bash tools/release-precheck.sh 1.7.0 --post-release
#
# 任一红灯（FAIL）退出码非 0；黄灯（WARN）只提醒不阻塞。
# 设计目标：本地一行命令验证 release-process.md 全部硬性要求。
# ============================================================

set -u
set -o pipefail

# ───── 颜色 ─────
if [ -t 1 ]; then
    R=$'\033[31m'; G=$'\033[32m'; Y=$'\033[33m'; B=$'\033[34m'; D=$'\033[2m'; X=$'\033[0m'
else
    R=''; G=''; Y=''; B=''; D=''; X=''
fi

# ───── 参数 ─────
VERSION=""
MODE="release"
BASELINE="${YK_RELEASE_BASELINE:-}"
REMOTE_NAME="${YK_RELEASE_REMOTE:-origin}"
for arg in "$@"; do
    case "$arg" in
        --candidate) MODE="candidate" ;;
        --release) MODE="release" ;;
        --post-release) MODE="post-release" ;;
        --baseline=*) BASELINE="${arg#--baseline=}" ;;
        --remote=*) REMOTE_NAME="${arg#--remote=}" ;;
        -*)
            echo "${R}未知参数: $arg${X}"
            exit 2
            ;;
        *)
            if [ -n "$VERSION" ]; then
                echo "${R}只能指定一个版本号${X}"
                exit 2
            fi
            VERSION="$arg"
            ;;
    esac
done
if [ -z "$VERSION" ]; then
    echo "${R}用法: bash tools/release-precheck.sh <version> [--candidate|--post-release] [--baseline=<git-ref>] [--remote=<name>]${X}"
    echo "示例: bash tools/release-precheck.sh 1.7.0"
    exit 2
fi

# 标准化：去掉可能的 v 前缀
VERSION="${VERSION#v}"
if [ -n "$BASELINE" ] && ! [[ "$BASELINE" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]]; then
    echo "${R}无效的 schema 比较基线: $BASELINE${X}"
    exit 2
fi
if ! [[ "$REMOTE_NAME" =~ ^[A-Za-z0-9][A-Za-z0-9._/-]*$ ]]; then
    echo "${R}无效的 Git remote: $REMOTE_NAME${X}"
    exit 2
fi

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
WORKSPACE_DIR="$(cd "$ROOT_DIR/.." && pwd)"
cd "$ROOT_DIR"

# Windows-created worktrees store a Windows path in .git. Native WSL Git cannot
# resolve it, while git.exe can when the root is converted to a Windows path.
GIT_ROOT="$ROOT_DIR"
GIT_BIN=""
if git -C "$GIT_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
    GIT_BIN="git"
elif command -v git.exe >/dev/null 2>&1 && command -v wslpath >/dev/null 2>&1; then
    GIT_ROOT="$(wslpath -m "$ROOT_DIR")"
    if git.exe -C "$GIT_ROOT" rev-parse --git-dir >/dev/null 2>&1; then
        GIT_BIN="git.exe"
    fi
fi
repo_git() {
    [ -n "$GIT_BIN" ] || return 127
    "$GIT_BIN" -C "$GIT_ROOT" "$@"
}

echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
if [ "$MODE" = "candidate" ]; then
    echo "${B}  Yikai CMS 候选版预检 — 目标版本: v${VERSION}${X}"
elif [ "$MODE" = "post-release" ]; then
    echo "${B}  Yikai CMS 发布后同步门禁 — 目标版本: v${VERSION}${X}"
else
    echo "${B}  Yikai CMS 发版预检 — 目标版本: v${VERSION}${X}"
fi
echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
echo

FAIL=0
WARN=0
PRECHECK_TEMP_FILES=()

# A FAIL must never be masked by a later successful command or a future early exit.
precheck_exit_guard() {
    local status=$?
    local file
    trap - EXIT
    for file in "${PRECHECK_TEMP_FILES[@]}"; do
        [ -n "$file" ] && rm -f "$file"
    done
    if [ "$FAIL" -gt 0 ] && [ "$status" -eq 0 ]; then
        status=1
    fi
    exit "$status"
}
trap precheck_exit_guard EXIT

pass() { echo "  ${G}✓${X} $1"; }
fail() { echo "  ${R}✗${X} $1"; FAIL=$((FAIL+1)); }
warn() { echo "  ${Y}⚠${X} $1"; WARN=$((WARN+1)); }
info() { echo "  ${D}·${X} $1"; }
section() { echo; echo "${B}[$1]${X}"; }

if [ "$MODE" = "post-release" ]; then
    section "发布标签与 main 同步"
    remote_url=$(repo_git config --get "remote.${REMOTE_NAME}.url" 2>/dev/null || true)
    if [ -z "$remote_url" ]; then
        fail "Git remote '$REMOTE_NAME' 不存在"
    else
        if repo_git ls-remote --exit-code "$REMOTE_NAME" "refs/tags/v${VERSION}" >/dev/null 2>&1 \
            || repo_git ls-remote --exit-code "$REMOTE_NAME" "refs/tags/v${VERSION}^{}" >/dev/null 2>&1; then
            pass "$REMOTE_NAME 已存在 v${VERSION} 标签"
        else
            fail "$REMOTE_NAME 缺少 v${VERSION} 标签"
        fi
        if repo_git fetch --quiet --no-tags "$REMOTE_NAME" \
            "refs/heads/main:refs/remotes/${REMOTE_NAME}/main" \
            "refs/tags/v${VERSION}:refs/tags/v${VERSION}"; then
            pass "已读取远端 main 与 v${VERSION} 的提交对象"
        else
            fail "无法读取远端 main 或 v${VERSION}"
        fi
    fi

    tag_ref="refs/tags/v${VERSION}"
    main_ref="refs/remotes/${REMOTE_NAME}/main"
    if repo_git rev-parse --verify "${tag_ref}^{commit}" >/dev/null 2>&1 \
        && repo_git rev-parse --verify "${main_ref}^{commit}" >/dev/null 2>&1; then
        tag_commit=$(repo_git rev-parse "${tag_ref}^{commit}")
        main_commit=$(repo_git rev-parse "${main_ref}^{commit}")
        if repo_git merge-base --is-ancestor "$tag_commit" "$main_ref"; then
            pass "v${VERSION} 已合入远端 main（tag ${tag_commit:0:12} → main ${main_commit:0:12}）"
        else
            fail "v${VERSION} 尚未合入远端 main（tag ${tag_commit:0:12}，main ${main_commit:0:12}）"
        fi

        main_readme=$(repo_git show "${main_ref}:README.md" 2>/dev/null \
            | head -1 | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1 || true)
        if [ "$main_readme" = "$VERSION" ]; then
            pass "远端 main README 版本 = v${VERSION}"
        else
            fail "远端 main README 版本 = '${main_readme:-未找到}'（期望 v${VERSION}）"
        fi

        main_config=$(repo_git show "${main_ref}:config/version.php" 2>/dev/null \
            | grep -oE "CMS_VERSION',\s*'[0-9.]+'" \
            | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1 || true)
        if [ "$main_config" = "$VERSION" ]; then
            pass "远端 main config/version.php = v${VERSION}"
        else
            fail "远端 main config/version.php = '${main_config:-未找到}'（期望 v${VERSION}）"
        fi
    else
        fail "无法解析远端 v${VERSION} 或 main 提交，跳过祖先关系检查"
    fi

    echo
    if [ "$FAIL" -eq 0 ]; then
        echo "${G}✓ 发布后同步门禁通过：默认分支已跟上 v${VERSION}${X}"
        exit 0
    fi
    echo "${R}✗ 发布后同步门禁未通过：$FAIL 个 FAIL${X}"
    echo "${R}  先合并 release 分支到 main，再重新运行本检查。${X}"
    exit 1
fi

# ─────────────────────────────────────────────────────────────
section "1. 版本号一致性"
# ─────────────────────────────────────────────────────────────

# config/version.php —— 版本号唯一可信来源
v=$(grep -oE "CMS_VERSION',\s*'[0-9.]+'" config/version.php 2>/dev/null | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1)
if [ "$v" = "$VERSION" ]; then
    pass "config/version.php  CMS_VERSION = '$v'（唯一可信来源）"
else
    fail "config/version.php  CMS_VERSION = '${v:-未找到}'  (期望: '$VERSION')"
fi

# install SQL 内的版本号副本（-- Version 注释 + cms_version 种子），发版易漏，务必校验
for sql in install/sql/mysql.sql install/sql/sqlite.sql; do
    v=$(grep -E '^-- Version:' "$sql" 2>/dev/null | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1)
    [ "$v" = "$VERSION" ] && pass "$sql  -- Version = '$v'" || fail "$sql  -- Version = '${v:-未找到}'  (期望: '$VERSION')"
    v=$(grep -E 'cms_version' "$sql" 2>/dev/null | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1)
    [ "$v" = "$VERSION" ] && pass "$sql  cms_version 种子 = '$v'" || fail "$sql  cms_version 种子 = '${v:-未找到}'  (期望: '$VERSION')"
done

# README.md 顶部
v=$(head -1 README.md 2>/dev/null | grep -oE "v?[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1 | tr -d v)
if [ "$v" = "$VERSION" ]; then
    pass "README.md  第 1 行 = 'v$v'"
else
    fail "README.md  第 1 行版本 = '${v:-未找到}'  (期望: 'v$VERSION')"
fi

# composer.json：不要硬编码 version 字段（Packagist 靠 git tag）
if grep -qE '"version"\s*:\s*"' composer.json 2>/dev/null; then
    warn "composer.json  含 version 字段（建议删除，让 Packagist 用 git tag 推断）"
else
    pass "composer.json  无 version 硬编码（符合 Packagist 推荐）"
fi

# ─────────────────────────────────────────────────────────────
section "2. 改动盘点文档"
# ─────────────────────────────────────────────────────────────

# 盘点文档在仓库外的 yikaicms-docs/（docs/ 自 2026-05-16 起就是 gitignore 的）；
# 兼容旧位置，两处任一存在即可。
doc="../yikaicms-docs/improvements-v${VERSION}.md"
[ -f "$doc" ] || doc="docs/improvements-v${VERSION}.md"
if [ -f "$doc" ]; then
    pass "$doc 存在  ($(wc -l < "$doc") 行)"
    if grep -qE "v${VERSION//./\\.}" "$doc"; then
        pass "$doc 内文含 v${VERSION} 引用"
    else
        warn "$doc 内文未提及 v${VERSION}（建议在标题/正文显式标版本）"
    fi
else
    fail "$doc 不存在"
fi

# ─────────────────────────────────────────────────────────────
section "3. SQL 表数对齐"
# ─────────────────────────────────────────────────────────────

mysql_count=$(grep -c "^CREATE TABLE" install/sql/mysql.sql 2>/dev/null || echo 0)
sqlite_count=$(grep -c "^CREATE TABLE" install/sql/sqlite.sql 2>/dev/null || echo 0)

info "mysql.sql  CREATE TABLE 数: $mysql_count"
info "sqlite.sql CREATE TABLE 数: $sqlite_count"

if [ "$mysql_count" = "$sqlite_count" ]; then
    pass "mysql.sql 与 sqlite.sql 表数一致 ($mysql_count)"
else
    fail "mysql.sql ($mysql_count) ≠ sqlite.sql ($sqlite_count) 表数不一致"
fi

# 实际数据库（用 PHP 查询，避开 mysql.exe 路径依赖）
if php_exe="$(command -v php 2>/dev/null)" || php_exe="/mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe"; then
    if [ -x "$php_exe" ] || [ -f "$php_exe" ]; then
        # 解析 config.php 中的 DB_HOST / DB_NAME / DB_USER / DB_PASS / DB_PREFIX
        cfg=config/config.php
        if [ -f "$cfg" ]; then
            db_host=$(grep -oE "DB_HOST',\s*'[^']+'" "$cfg" | sed -E "s/.*'([^']+)'/\1/" | head -1)
            db_name=$(grep -oE "DB_NAME',\s*'[^']+'" "$cfg" | sed -E "s/.*'([^']+)'/\1/" | head -1)
            db_user=$(grep -oE "DB_USER',\s*'[^']+'" "$cfg" | sed -E "s/.*'([^']+)'/\1/" | head -1)
            db_pass=$(grep -oE "DB_PASS',\s*'[^']*'" "$cfg" | sed -E "s/.*'([^']*)'/\1/" | head -1)
            db_pre=$(grep -oE "DB_PREFIX',\s*'[^']+'" "$cfg" | sed -E "s/.*'([^']+)'/\1/" | head -1)
            if [ -n "${db_name:-}" ]; then
                # 临时脚本写在**当前项目目录**里、用相对路径调用。
                # 原来写的是 /mnt/... 绝对路径：这台机器上的 php 是 Windows 的 php.exe，
                # 它看不见 /mnt，报「Could not open input file」，于是这项检查长期静默跳过。
                # 相对路径两种 PHP 都能跑（php.exe 按 cwd 解析）。
                tmp=".precheck_dbcount.php"
                cat > "$tmp" << PHPEOF
<?php
// 只报「install SQL 有、库里没有」——那才是真漂移。
// 反方向（库里多出来的）一律无害：插件自建表、运行时建表、以及 v1.7.5 起
// 有意保留不 DROP 的历史表，都会让库比 install SQL 多，按数量比必然误报。
try {
    \$pdo = new PDO('mysql:host=${db_host};dbname=${db_name};charset=utf8mb4', '${db_user}', '${db_pass}');
    \$live = \$pdo->query("SHOW TABLES LIKE '${db_pre}%'")->fetchAll(PDO::FETCH_COLUMN);
    preg_match_all('/CREATE TABLE[^\`]*\`([a-z0-9_]+)\`/i', file_get_contents('install/sql/mysql.sql'), \$m);
    \$missing = array_diff(\$m[1], \$live);
    \$extra   = array_diff(\$live, \$m[1]);
    echo 'MISSING:' . implode(',', \$missing) . '|EXTRA:' . count(\$extra);
} catch (Throwable \$e) { echo "ERROR: " . \$e->getMessage(); }
PHPEOF
                actual=$("$php_exe" "$tmp" 2>&1 | tr -d '\r' | head -1)
                rm -f "$tmp"
                if [[ "$actual" == MISSING:* ]]; then
                    miss="${actual#MISSING:}"; miss="${miss%%|*}"
                    extra="${actual##*EXTRA:}"
                    if [ -z "$miss" ]; then
                        pass "本地库已含 mysql.sql 的全部 ${mysql_count} 张表（另有 ${extra} 张插件/历史表，正常）"
                    else
                        fail "本地库缺少 mysql.sql 里的表：${miss}"
                    fi
                else
                    warn "数据库连接失败，跳过实际表对比：$actual"
                fi
            else
                warn "config.php 解析 DB 配置失败，跳过实际表数对比"
            fi
        else
            if [ "$MODE" = "candidate" ]; then
                info "候选工作树无 config/config.php，跳过本地运行库对比"
            else
                warn "config/config.php 不存在，跳过实际表数对比"
            fi
        fi
    else
        warn "未找到 PHP 可执行，跳过实际表数对比"
    fi
fi

# ─────────────────────────────────────────────────────────────
section "4. 多语言 key 缺失"
# ─────────────────────────────────────────────────────────────

# 收集代码中所有 __('key') 引用
mapfile -t code_keys < <(grep -rohE "__\('[a-z_]+'\)" admin/ themes/ includes/ 2>/dev/null \
    | sed -E "s/__\('([a-z_]+)'\)/\1/" | sort -u)
total=${#code_keys[@]}
info "代码引用 lang key 总数: $total"

for lang in zh-CN en ja; do
    file="lang/${lang}.php"
    if [ ! -f "$file" ]; then
        warn "$file 不存在，跳过"
        continue
    fi
    miss=0
    for k in "${code_keys[@]}"; do
        grep -qE "['\"]${k}['\"]\s*=>" "$file" 2>/dev/null || miss=$((miss+1))
    done
    if [ "$miss" -eq 0 ]; then
        pass "$file  缺失 0"
    elif [ "$miss" -le 5 ]; then
        warn "$file  缺失 $miss（少量，建议补全；详情用：tools/release-precheck.sh 加 --verbose）"
    else
        warn "$file  缺失 $miss（数量较多，建议至少 zh-CN/en 0 缺失才发版；ja 缺失允许累积补登）"
    fi
done

# ─────────────────────────────────────────────────────────────
section "4a. 演示数据 UTF-8 完整性"
# 安装 SQL、测试夹具和已配置的本地三语演示库统一检查 U+FFFD。
# 扫描器只读数据库；候选树没有 config.php 时只检查文件并明确跳过数据库。
replacement_scan_php="$(command -v php 2>/dev/null || true)"
if [ -z "$replacement_scan_php" ] && [ -f /mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe ]; then
    replacement_scan_php=/mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe
fi
if [ -z "$replacement_scan_php" ]; then
    fail "未找到 PHP 可执行，无法执行演示数据 U+FFFD 扫描"
else
    replacement_scan_log="${TMPDIR:-/tmp}/yikai-release-mojibake-$$.log"
    PRECHECK_TEMP_FILES+=("$replacement_scan_log")
    replacement_scan_status=0
    "$replacement_scan_php" tools/scan_demo_mojibake.php >"$replacement_scan_log" 2>&1 || replacement_scan_status=$?
    if [ "$replacement_scan_status" -eq 0 ]; then
        pass "安装/测试夹具及可用本地三语演示库未发现 U+FFFD"
        sed -n '1,3p' "$replacement_scan_log" | sed 's/^/      /'
    else
        fail "演示数据 U+FFFD 扫描未通过"
        cat "$replacement_scan_log" | sed 's/^/      /'
    fi
fi

# ─────────────────────────────────────────────────────────────
section "5. migrations/ 升级项提醒"
# ─────────────────────────────────────────────────────────────

# 这一项仅做提醒（schema 自动 diff 太复杂），让人脑确认。迁移唯一来源是
# Migrator::loadAll()，新增项只能放 migrations/*.php。
upgrade_count=$(find migrations -maxdepth 1 -type f -name '20*.php' 2>/dev/null | wc -l | tr -d ' ')
info "migrations/ 当前共 $upgrade_count 条独立迁移"
schema_baseline="$BASELINE"
if [ -z "$schema_baseline" ]; then
    # HEAD^ 避免在正式 tag 本身执行时把该 tag 当作自己的比较基线。
    schema_baseline=$(repo_git describe --tags --abbrev=0 --match 'v[0-9]*' HEAD^ 2>/dev/null || true)
    [ -n "$schema_baseline" ] || schema_baseline=$(repo_git describe --tags --abbrev=0 --match 'v[0-9]*' HEAD 2>/dev/null || true)
fi
if [ -n "$schema_baseline" ] && repo_git rev-parse --verify "${schema_baseline}^{commit}" > /dev/null 2>&1; then
    info "schema 比较基线: $schema_baseline"
    schema_before=".release-precheck-schema-before-$$.sql"
    PRECHECK_TEMP_FILES+=("$schema_before")
    if repo_git show "${schema_baseline}:install/sql/mysql.sql" > "$schema_before" 2>/dev/null \
        && schema_result=$(php tools/release-schema-diff.php "$schema_before" install/sql/mysql.sql 2>/dev/null); then
        schema_changed=$(printf '%s\n' "$schema_result" | sed -n 's/^COUNT=//p')
        schema_tables=$(printf '%s\n' "$schema_result" | sed -n 's/^TABLES=//p')
    else
        schema_changed=""
        schema_tables=""
    fi
    if [ -z "$schema_changed" ]; then
        warn "无法解析 $schema_baseline 与当前 install SQL 的 schema 差异"
    elif [ "$schema_changed" -gt 0 ] 2>/dev/null; then
        warn "自 $schema_baseline 起 ${schema_changed} 张表的结构有变化（${schema_tables}），请确认 migrations/ 已加对应独立迁移"
    else
        pass "自 $schema_baseline 起未改动 schema，无需新增 upgrade 项"
    fi
elif [ -n "$schema_baseline" ]; then
    warn "schema 比较基线无效：$schema_baseline"
else
    warn "未找到可用的正式版本标签，请用 --baseline=<git-ref> 指定 schema 比较基线"
fi

# ─────────────────────────────────────────────────────────────
section "6. update.yikaicms 升级服务器（releases.json）"
# ─────────────────────────────────────────────────────────────

releases_json="$WORKSPACE_DIR/update.yikaicms/data/releases.json"
if [ "$MODE" = "candidate" ]; then
    info "候选阶段不修改升级服务器，跳过渠道版本校验"
    if [ "$VERSION" = "1.19.6" ]; then
        info "v1.19.6 发布条目必须设置 min_php >= 8.2（v1.19.5 的 PHP 8.0 升级器无法自更新）"
    fi
elif [ -f "$releases_json" ]; then
    releases_native="$releases_json"
    if command -v cygpath >/dev/null 2>&1; then
        releases_native=$(cygpath -w "$releases_json")
    elif command -v wslpath >/dev/null 2>&1 && php -r 'exit(DIRECTORY_SEPARATOR === "\\" ? 0 : 1);' 2>/dev/null; then
        releases_native=$(wslpath -w "$releases_json")
    fi
    latest=$(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); echo is_array($d)?($d["latest"]??""):"";' "$releases_native" 2>/dev/null)
    if [ "$latest" = "$VERSION" ]; then
        pass "releases.json  latest = '$latest'"
    else
        fail "releases.json  latest = '${latest:-解析失败}'  (期望: '$VERSION')"
    fi
    has_entry=$(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); foreach((array)($d["releases"]??[]) as $r){if(($r["version"]??"")===$argv[2]){echo "yes";exit;}} echo "no";' "$releases_native" "$VERSION" 2>/dev/null)
    if [ "$has_entry" = "yes" ]; then
        pass "releases.json  含 v${VERSION} 条目"
    else
        fail "releases.json  无 v${VERSION} 条目"
    fi
    if [ "$VERSION" = "1.19.6" ] && [ "$has_entry" = "yes" ]; then
        entry_min_php=$(php -r '$d=json_decode((string)file_get_contents($argv[1]),true); foreach((array)($d["releases"]??[]) as $r){if(($r["version"]??"")===$argv[2]){echo (string)($r["min_php"]??"");exit;}}' "$releases_native" "$VERSION" 2>/dev/null)
        if [ -n "$entry_min_php" ] && php -r 'exit(version_compare($argv[1], "8.2", ">=") ? 0 : 1);' "$entry_min_php" 2>/dev/null; then
            pass "v1.19.6 在线升级过程门槛 min_php = '$entry_min_php'（可绕开 v1.19.5/PHP 8.0 的 T_ENUM 致命错误）"
        else
            fail "v1.19.6 releases.json 必须设置 min_php >= 8.2（当前: '${entry_min_php:-未设置}'）"
        fi
    fi
else
    warn "$releases_json 不存在（跳过升级服务器校验）"
fi

# ─────────────────────────────────────────────────────────────
section "6a. 模板市场封面资源"
# ─────────────────────────────────────────────────────────────

# 封面既是升级服务器注册表的一部分，也是后台官方模板卡片的展示依赖。
# 候选版检查本地源文件；正式发版再用 GET 复核线上资源，避免 HEAD 被服务器策略误判。
template_cover_check="$WORKSPACE_DIR/update.yikaicms/bin/check-template-covers.sh"
if [ ! -f "$template_cover_check" ]; then
    fail "$template_cover_check 不存在，无法校验模板封面"
elif [ "$MODE" = "candidate" ]; then
    if bash "$template_cover_check"; then
        pass "模板注册表、封面路径和本地封面文件校验通过"
    else
        fail "模板封面本地校验失败"
    fi
else
    if bash "$template_cover_check" --remote; then
        pass "模板注册表、封面路径和线上 GET 资源校验通过"
    else
        fail "模板封面线上校验失败"
    fi
fi

# ─────────────────────────────────────────────────────────────
section "7. 官网 yikaicms.com（index.html / changelog.html）"
# ─────────────────────────────────────────────────────────────

site="$WORKSPACE_DIR/yikaicms.com.yikai"
if [ "$MODE" = "candidate" ]; then
    info "候选阶段不更新官网，跳过渠道版本校验"
else
for f in index.html changelog.html; do
    fp="$site/$f"
    if [ ! -f "$fp" ]; then
        warn "$fp 不存在"
        continue
    fi
    if grep -qE "v${VERSION//./\\.}" "$fp"; then
        pass "$f  含 v${VERSION} 字符串"
    else
        fail "$f  未发现 v${VERSION}（首页下载区/changelog 卡片可能未更新）"
    fi
    # 反向检查：是否还有"最新版"标签贴在旧版本上
    old_latest=$(grep -B1 "最新版" "$fp" 2>/dev/null | grep -oE "v[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1)
    if [ -n "$old_latest" ] && [ "$old_latest" != "v${VERSION}" ]; then
        fail "$f  「最新版」徽章贴在 $old_latest 上（期望: v${VERSION}）"
    fi
done
fi

# ─────────────────────────────────────────────────────────────
section "8. 演示站 demo.yikaicms config"
# ─────────────────────────────────────────────────────────────

demo_cfg="$WORKSPACE_DIR/demo.yikaicms/config/config.php"
if [ "$MODE" = "candidate" ]; then
    info "候选阶段不升级演示站，跳过版本校验"
elif [ -f "$demo_cfg" ]; then
    v=$(grep -oE "CMS_VERSION', '[0-9.]+'" "$demo_cfg" | grep -oE "[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" | head -1)
    if [ "$v" = "$VERSION" ]; then
        pass "demo.yikaicms/config/config.php  CMS_VERSION = '$v'"
    else
        warn "demo.yikaicms/config/config.php  CMS_VERSION = '${v:-未找到}'  (期望: '$VERSION')"
    fi
    if grep -qE "DEMO_MODE',\s*true" "$demo_cfg"; then
        pass "demo.yikaicms  DEMO_MODE = true"
    else
        warn "demo.yikaicms  DEMO_MODE 未启用（演示站应为 true）"
    fi
else
    # 本地 demo 副本已于 2026-07-30 移除：线上 demo.yikaicms.com 改走在线更新，
    # 本地那份不再是部署源，长期停在旧版反而每次预检都报噪音。
    # 演示站的版本改为发版后核对（见下方提示），不在预检里当问题。
    info "本地 demo 副本已移除（线上走在线更新），跳过本地校验"
    info "发版后记得把 demo.yikaicms.com 升到 v${VERSION} 并跑一次数据库升级"
fi

# ─────────────────────────────────────────────────────────────
section "9. 前台多语言真实路由"
# 单元测试能校验翻译选择，却覆盖不到 Web 服务器把 /en/...、/ja/... 交给
# index.php 时的初始化顺序。候选版和正式发版都必须走一次真实 HTTP catch-all。
route_spec="tests/e2e/frontend-language-prefix.spec.js"
if [ ! -f "$route_spec" ]; then
    fail "$route_spec 不存在"
elif ! command -v node >/dev/null 2>&1; then
    fail "未找到 Node.js，无法执行前台多语言真实路由门禁"
elif [ ! -d node_modules/@playwright/test ]; then
    fail "Playwright 依赖未安装（先运行 npm ci）"
else
    route_log="${TMPDIR:-/tmp}/yikai-release-language-routes-$$.log"
    route_cmd_status=0
    if command -v wslpath >/dev/null 2>&1 && command -v powershell.exe >/dev/null 2>&1; then
        route_ps1=$(wslpath -w "$ROOT_DIR/tools/run-frontend-language-routes.ps1")
        route_root=$(wslpath -w "$ROOT_DIR")
        powershell.exe -NoProfile -NonInteractive -ExecutionPolicy Bypass \
            -File "$route_ps1" -Root "$route_root" >"$route_log" 2>&1 || route_cmd_status=$?
    else
        node tests/e2e/run-local.js "$route_spec" --project=desktop-1440 >"$route_log" 2>&1 || route_cmd_status=$?
    fi
    if [ "$route_cmd_status" -eq 0 ]; then
        pass "英文/日文下载页：语言前缀、记录与分类均未回退中文"
    else
        fail "前台多语言真实路由未通过"
        tail -12 "$route_log" | sed 's/^/      /'
    fi
    rm -f "$route_log"
fi

# ─────────────────────────────────────────────────────────────
section "10. release zip 是否已 build"
# ─────────────────────────────────────────────────────────────

zip="releases/yikaicms-v${VERSION}.zip"
if [ "$MODE" = "candidate" ]; then
    info "候选阶段不生成安装包，跳过 zip 校验"
elif [ -f "$zip" ]; then
    size=$(du -h "$zip" | awk '{print $1}')
    pass "$zip 已存在 ($size)"
else
    warn "$zip 不存在（请运行: bash build.sh $VERSION）"
fi

# ─────────────────────────────────────────────────────────────
# 总结
# ─────────────────────────────────────────────────────────────
echo
echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
if [ "$FAIL" -eq 0 ] && [ "$WARN" -eq 0 ]; then
    if [ "$MODE" = "candidate" ]; then
        echo "${G}✓ 候选版预检通过，可继续独立复查 v${VERSION}${X}"
    else
        echo "${G}✓ 全部预检通过，可以发版 v${VERSION}${X}"
    fi
    exit 0
elif [ "$FAIL" -eq 0 ]; then
    echo "${Y}⚠ 通过，但有 $WARN 个警告（不阻塞，但建议看一眼）${X}"
    exit 0
else
    echo "${R}✗ 预检未通过：$FAIL 个 FAIL / $WARN 个 WARN${X}"
    if [ "$MODE" = "candidate" ]; then
        echo "${R}  请修复 FAIL 项后重跑：bash tools/release-precheck.sh ${VERSION} --candidate${X}"
    else
        echo "${R}  请修复 FAIL 项后重跑：bash tools/release-precheck.sh ${VERSION}${X}"
    fi
    exit 1
fi
