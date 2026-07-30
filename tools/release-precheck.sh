#!/usr/bin/env bash
# ============================================================
# Yikai CMS - 发版前预检（release-precheck）
#
# 用法：
#   bash tools/release-precheck.sh 1.7.0
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
VERSION="${1:-}"
if [ -z "$VERSION" ]; then
    echo "${R}用法: bash tools/release-precheck.sh <version>${X}"
    echo "示例: bash tools/release-precheck.sh 1.7.0"
    exit 2
fi

# 标准化：去掉可能的 v 前缀
VERSION="${VERSION#v}"

ROOT_DIR="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT_DIR"

echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
echo "${B}  Yikai CMS 发版预检 — 目标版本: v${VERSION}${X}"
echo "${B}━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━${X}"
echo

FAIL=0
WARN=0

pass() { echo "  ${G}✓${X} $1"; }
fail() { echo "  ${R}✗${X} $1"; FAIL=$((FAIL+1)); }
warn() { echo "  ${Y}⚠${X} $1"; WARN=$((WARN+1)); }
info() { echo "  ${D}·${X} $1"; }
section() { echo; echo "${B}[$1]${X}"; }

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
                tmp="/mnt/d/phpstudy_pro/WWW/.precheck_dbcount.php"
                cat > "$tmp" << PHPEOF
<?php
try {
    \$pdo = new PDO('mysql:host=${db_host};dbname=${db_name};charset=utf8mb4', '${db_user}', '${db_pass}');
    \$rs = \$pdo->query("SHOW TABLES LIKE '${db_pre}%'");
    echo count(\$rs->fetchAll(PDO::FETCH_COLUMN));
} catch (Throwable \$e) { echo "ERROR: " . \$e->getMessage(); }
PHPEOF
                # WSL → Windows 路径转换（仅当 PHP 是 .exe 时需要）
                if [[ "$php_exe" == *.exe ]]; then
                    # /mnt/d/phpstudy_pro/Extensions/php/php8.2.9nts/php.exe → D:\phpstudy_pro\Extensions\php\php8.2.9nts\php.exe
                    php_win=$(echo "$php_exe" | sed -E 's|^/mnt/([a-z])|\U\1:|' | tr '/' '\\')
                    tmp_win=$(echo "$tmp" | sed -E 's|^/mnt/([a-z])|\U\1:|' | tr '/' '\\')
                    actual=$(cmd.exe /c "$php_win $tmp_win" 2>/dev/null | tr -d '\r' | head -1)
                else
                    actual=$("$php_exe" "$tmp" 2>&1 | head -1)
                fi
                rm -f "$tmp"
                if [[ "$actual" =~ ^[0-9]+$ ]]; then
                    info "实际数据库 (${db_pre}*) 表数: $actual"
                    if [ "$actual" = "$mysql_count" ]; then
                        pass "数据库表数与 mysql.sql 一致 ($actual)"
                    else
                        fail "数据库表数 ($actual) ≠ mysql.sql ($mysql_count)（schema 可能漂移）"
                    fi
                else
                    warn "数据库连接失败，跳过实际表数对比：$actual"
                fi
            else
                warn "config.php 解析 DB 配置失败，跳过实际表数对比"
            fi
        else
            warn "config/config.php 不存在，跳过实际表数对比"
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
section "5. install/upgrade.php 升级项提醒"
# ─────────────────────────────────────────────────────────────

# 这一项仅做提醒（schema 自动 diff 太复杂），让人脑确认
upgrade_count=$(grep -cE "'id'\s*=>\s*'[0-9]+_" admin/upgrade.php 2>/dev/null || echo 0)
info "admin/upgrade.php 当前共 $upgrade_count 条 upgrade 定义"
if git rev-parse --verify HEAD~1 > /dev/null 2>&1; then
    # `grep -c` 在 0 匹配时退出码非 0 + set -u 触发，需带 || true 兜底
    schema_changed=$(git diff HEAD~1 -- install/sql/mysql.sql 2>/dev/null \
        | grep -cE "^[+-]CREATE TABLE|^[+-]ALTER TABLE|^[+-].*ADD (COLUMN|KEY|INDEX|UNIQUE|FOREIGN)" || true)
    schema_changed=${schema_changed:-0}
    if [ "${schema_changed}" -gt 0 ] 2>/dev/null; then
        warn "近一次 commit 改动了 install/sql/mysql.sql (${schema_changed} 行 schema 关键字)，请确认 admin/upgrade.php 已加对应升级项"
    else
        pass "近一次 commit 未改动 schema，无需新增 upgrade 项"
    fi
fi

# ─────────────────────────────────────────────────────────────
section "6. update.yikaicms 升级服务器（releases.json）"
# ─────────────────────────────────────────────────────────────

releases_json="/mnt/d/phpstudy_pro/WWW/update.yikaicms/data/releases.json"
if [ -f "$releases_json" ]; then
    latest=$(python3 -c "import json; print(json.load(open('$releases_json'))['latest'])" 2>/dev/null)
    if [ "$latest" = "$VERSION" ]; then
        pass "releases.json  latest = '$latest'"
    else
        fail "releases.json  latest = '${latest:-解析失败}'  (期望: '$VERSION')"
    fi
    has_entry=$(python3 -c "import json; d=json.load(open('$releases_json')); print('yes' if any(r['version']=='$VERSION' for r in d['releases']) else 'no')" 2>/dev/null)
    if [ "$has_entry" = "yes" ]; then
        pass "releases.json  含 v${VERSION} 条目"
    else
        fail "releases.json  无 v${VERSION} 条目"
    fi
else
    warn "$releases_json 不存在（跳过升级服务器校验）"
fi

# ─────────────────────────────────────────────────────────────
section "7. 官网 yikaicms.com（index.html / changelog.html）"
# ─────────────────────────────────────────────────────────────

site="/mnt/d/phpstudy_pro/WWW/yikaicms.com.yikai"
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

# ─────────────────────────────────────────────────────────────
section "8. 演示站 demo.yikaicms config"
# ─────────────────────────────────────────────────────────────

demo_cfg="/mnt/d/phpstudy_pro/WWW/demo.yikaicms/config/config.php"
if [ -f "$demo_cfg" ]; then
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
    warn "$demo_cfg 不存在（跳过演示站校验）"
fi

# ─────────────────────────────────────────────────────────────
section "9. release zip 是否已 build"
# ─────────────────────────────────────────────────────────────

zip="releases/yikaicms-v${VERSION}.zip"
if [ -f "$zip" ]; then
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
    echo "${G}✓ 全部预检通过，可以发版 v${VERSION}${X}"
    exit 0
elif [ "$FAIL" -eq 0 ]; then
    echo "${Y}⚠ 通过，但有 $WARN 个警告（不阻塞，但建议看一眼）${X}"
    exit 0
else
    echo "${R}✗ 预检未通过：$FAIL 个 FAIL / $WARN 个 WARN${X}"
    echo "${R}  请修复 FAIL 项后重跑：bash tools/release-precheck.sh ${VERSION}${X}"
    exit 1
fi
