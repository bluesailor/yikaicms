#!/bin/bash
# ============================================================
# Yikai CMS - 发布打包脚本
#
# 用法：
#   bash build.sh          # 自动从 config/version.php 读取版本号
#   bash build.sh 1.2.0    # 手动指定版本号
#
# 输出：
#   releases/yikaicms-v{版本}.zip
#   releases/yikaicms-v{版本}.sha256
# ============================================================

set -e

# 项目根目录（脚本所在目录）
ROOT_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$ROOT_DIR"

# Worktrees created on Windows store a Windows gitdir path. WSL Git cannot resolve that
# path, so prefer native Git and fall back to git.exe with a Windows-form root.
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
if [ -z "$GIT_BIN" ]; then
    echo "Error: 无法验证 Git 仓库状态，正式构建已中止。"
    echo "       Windows worktree 请确认 WSL 可调用 git.exe。"
    exit 1
fi
repo_git() {
    "$GIT_BIN" -C "$GIT_ROOT" "$@"
}

# 正式构建只能来自可追溯的干净提交。未提交和未跟踪文件都会进入当前工作树包，
# 因而不能仅在 provenance 中标记 source_dirty 后继续生成发行物。
WORKTREE_STATUS="$(repo_git status --porcelain --untracked-files=normal)"
if [ -n "$WORKTREE_STATUS" ]; then
    echo "Error: 工作树存在未提交或未跟踪文件，正式构建已中止。"
    echo "       请先提交本轮修改，并确认 git status 为空。"
    exit 1
fi

# 版本号：优先使用参数，否则从 config/version.php 提取（版本号单一可信来源）
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep -oP "CMS_VERSION',\s*'\\K[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?" config/version.php 2>/dev/null || echo "")
    if [ -z "$VERSION" ]; then
        echo "Error: 无法从 config/version.php 读取版本号，请手动指定: bash build.sh 1.2.0"
        exit 1
    fi
fi

if [[ ! "$VERSION" =~ ^[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
    echo "Error: 非法版本号 '$VERSION'"
    exit 1
fi

PACKAGE_NAME="yikaicms-v${VERSION}"
RELEASE_DIR="$ROOT_DIR/releases"
TMP_DIR="/tmp/yikaicms-build-$$"
PKG_DIR="$TMP_DIR/$PACKAGE_NAME"

echo "=========================================="
echo " Yikai CMS 打包脚本"
echo " 版本: v${VERSION}"
echo "=========================================="

# ---- 版本号一致性硬关卡 ----
# config/version.php 是版本号唯一可信来源；下列文件含硬编码副本，必须与之一致。
# 任一漂移即中止打包 —— 这一步不可跳过，杜绝「发版忘了同步 README/SQL 版本号」类错误。
echo "[0/5] 校验版本号一致性 (v${VERSION})..."
VER_ERRORS=0
check_ver() {  # $1=说明  $2=实际值
    if [ "$2" != "$VERSION" ]; then
        echo "  ✗ 版本不一致: $1 = '${2:-未找到}' (期望 '$VERSION')"
        VER_ERRORS=$((VER_ERRORS + 1))
    fi
}
verpat='[0-9]+\.[0-9]+\.[0-9]+(\.[0-9]+)?'
check_ver "README.md 第1行"                 "$(head -1 README.md 2>/dev/null | grep -oE "$verpat" | head -1)"
check_ver "install/sql/mysql.sql  -- Version" "$(grep -E '^-- Version:' install/sql/mysql.sql 2>/dev/null | grep -oE "$verpat" | head -1)"
check_ver "install/sql/mysql.sql  cms_version" "$(grep -E 'cms_version' install/sql/mysql.sql 2>/dev/null | grep -oE "$verpat" | head -1)"
check_ver "install/sql/sqlite.sql -- Version" "$(grep -E '^-- Version:' install/sql/sqlite.sql 2>/dev/null | grep -oE "$verpat" | head -1)"
check_ver "install/sql/sqlite.sql cms_version" "$(grep -E 'cms_version' install/sql/sqlite.sql 2>/dev/null | grep -oE "$verpat" | head -1)"
if [ $VER_ERRORS -gt 0 ]; then
    echo ""
    echo "Error: 版本号一致性校验失败（${VER_ERRORS} 处）。"
    echo "       请把上述文件同步到 v${VERSION}（以 config/version.php 为准）后重新打包。"
    exit 1
fi
echo "  ✓ 版本号一致（README / install SQL 均为 v${VERSION}）"

# ---- 首页多语言默认值硬关卡 ----
# 直接发布到 update 时可能不经过 GitHub CI，因此打包本身也必须阻止：
# 缺语言键、英日站回落中文、或新文案未登记语言属性。
echo "[0b/5] 校验首页多语言默认值..."
php tools/check_lang_keys.php
php tools/check_home_language_defaults.php

# ---- 清理临时目录 ----
rm -rf "$TMP_DIR"
mkdir -p "$PKG_DIR"
mkdir -p "$RELEASE_DIR"

# ---- 复制文件（当前工作树源码）----
# tracked + 未忽略的新文件构成当前源码；再过滤已从工作树移走但索引尚未提交删除的路径。
# 后续 EXCLUDES 仍负责剔除 tests、marketplace、开发工具等，不会把市场源码带进运行包。
echo "[1/5] 复制项目文件（当前工作树源码）..."
FILE_LIST="$TMP_DIR/worktree-files.list"
: > "$FILE_LIST"
while IFS= read -r -d '' item; do
    item="${item%$'\r'}"
    [ -e "$ROOT_DIR/$item" ] && printf '%s\0' "$item" >> "$FILE_LIST"
done < <(repo_git ls-files --cached --others --exclude-standard -z)
tar -C "$ROOT_DIR" --null -T "$FILE_LIST" -cf - | tar -xf - -C "$PKG_DIR"
# 基础单页编辑器与前台运行时属于免费能力，但源码可由私库在发版工作树中注入，
# 未必出现在公开仓库的 git ls-files 结果里。按策略显式复制，缺任一项直接中止。
for scope in core runtime; do
    while IFS= read -r item; do
        item="${item%$'\r'}"
        [ -z "$item" ] && continue
        source_path="$ROOT_DIR/$item"
        target_path="$PKG_DIR/$item"
        if [ ! -e "$source_path" ]; then
            echo "Error: 缺少 Blox 免费 ${scope} 资产: $item"
            exit 1
        fi
        mkdir -p "$(dirname "$target_path")"
        if [ -d "$source_path" ]; then
            mkdir -p "$target_path"
            cp -a "$source_path/." "$target_path/"
        else
            cp "$source_path" "$target_path"
        fi
    done < <(php "bin/blox-assets.php" list "$scope")
done

# 缓存命名空间随每个全量/增量发行包变化。HtmlCache 将它纳入缓存键，部署覆盖后
# 自动绕开上一版 HTML；同版本手工热修仍需执行 php bin/yikai.php cache:clear。
BUILD_ID="${VERSION}-$(date -u +%Y%m%d%H%M%S)"
printf "<?php\n\ndeclare(strict_types=1);\n\nreturn '%s';\n" "$BUILD_ID" > "$PKG_DIR/config/build.php"

# ---- 排除文件 ----
echo "[2/5] 排除不需要的文件..."

# 排除列表（相对于项目根目录）
EXCLUDES=(
    # 打包产物自身
    "releases"

    # 版本控制 / CI / 开发工具配置
    ".git"
    ".gitignore"
    ".gitattributes"
    ".github"
    ".agents"

    # 安装锁（用户安装后才应生成）
    "installed.lock"
    "config/installed.lock"

    # 真实配置（包中只保留模板）
    "config/config.php"

    # 开发工具
    "build.sh"
    "build.bat"
    "reset_test.sh"
    "_tools"
    "psalm.xml"
    "psalm-baseline.xml"
    "phpunit.xml"
    "composer.lock"
    "composer.json"
    "composer.phar"
    ".editorconfig"
    ".claude"

    # deploy/ 随发行包交付。共享主机安装者需要在拿到程序时就能使用服务器层
    # 敏感目录拦截规则，不能要求其另行前往源码仓库查找。

    # 容器化文件（共享主机发行包用不到；镜像走 docs/docker.md）
    "Dockerfile"
    ".dockerignore"
    "docker-compose.yml"

    # 开发文档（内部说明，不发布给用户）
    "docs"
    "AGENTS.md"
    "CLAUDE.md"

    # 跨站共享的前端 UI 参考库（dev 参考，非产品运行时代码）
    "ui-library"

    # 第三方导入工具（代码从不解压使用）
    "admin/bigdump-2.29.zip"

    # 测试 / 工具脚本（dev only）
    "tests"
    "tools"
    "vendor"

    # 试验/中间产物目录：不是运行时目录（代码零引用）。v1.18.5 的包里带进了 640KB
    # PDF 试验残留——因为它们既未被 git 跟踪、又未被 .gitignore 忽略，恰好落进
    # `ls-files --others --exclude-standard` 的采集范围。.gitignore 里也有一份。
    "tmp"
    "output"

    # CSS 源码（编译产物 tailwind.css 已包含）
    "assets/css/src"

    # 前端构建依赖（dev only，运行时不需要）
    "node_modules"
    "package.json"
    "package-lock.json"
    "playwright.config.js"
    "playwright-report"
    "test-results"
    "package-lock.json"
    "tailwind.config.js"
    "postcss.config.js"

    # 插件：包内预装核心体验（back-to-top 返回顶部、cookie-consent Cookie 同意），
    # 其余走插件市场按需安装（update.yikaicms.com/api/plugins/）。源码保留在仓库供开发与市场打包。
    #
    # logo-maker（LOGO 制作）2026-08-22 移出核心包：它的图标库有 7618 个 SVG，
    # 占全包 91% 的文件数、13% 体积，而多数站点用不到——共享主机上升级要逐个写
    # 文件，文件数直接决定升级耗时与「写失败」概率。改为市场按需装，登录后由控制
    # 台推荐卡引导（includes/RecommendedPlugins.php）。
    # ⚠ 存量站不受影响：源码仍在 git 里，增量包的删除清单只收录 git 中被删的文件，
    #   已装的插件不会被升级卸载。
    # 注：blox-example 由 config/blox-assets.json 的 pro 域排除（Blox 资产单一登记），不在此重复。
    "plugins/_example"
    "plugins/announcement"
    "plugins/menu-sort"
    "plugins/search-replace"
    "plugins/stats"
    "plugins/product-carousel"
    "plugins/logo-maker"
    # seo（SEO 助手）2026-08-22 同样移出核心包：Pro 闸 + 三张自建表，属"要用才装"
    # 的增强件；免费层 llms.txt / 实时分析 / SERP 预览 / 手动推送 装上即得。
    "plugins/seo"

    # 主题：运行包只内置 default。aurora/business/minimal/trade 的源码集中在
    # marketplace/themes/，由 update.yikaicms.com 主题市场签名分发，不进入 CMS 包。
    "marketplace"

    # Blox 资产由 config/blox-assets.json 单一登记。core/runtime 随免费包，pro 排除。
    "config/blox-assets.json"
    "bin/blox-assets.php"
    # 双仓工作流脚本——纯内部工具
    "bin/blox-git"

    # 临时测试文件（如本地 dev 时手写的）
    "recipe_test.php"
    "_i18n_test.php"

    # 运行时数据（保留目录结构）
    "storage/database.sqlite"
    "storage/login_throttle"
    "storage/logs"
    "storage/cache"
)

while IFS= read -r item; do
    item="${item%$'\r'}"
    [ -n "$item" ] && EXCLUDES+=("$item")
done < <(php "bin/blox-assets.php" list pro)

for item in "${EXCLUDES[@]}"; do
    rm -rf "$PKG_DIR/$item"
done

# 清空 uploads 和 storage 内容，但保留目录
rm -rf "$PKG_DIR/uploads/"*
rm -rf "$PKG_DIR/storage/"*
touch "$PKG_DIR/uploads/.gitkeep"
touch "$PKG_DIR/storage/.gitkeep"

# ---- 写入产品来源清单 ----
# 清单由包内实际文件计算，不能在复制/排除前生成。source_dirty 明确记录当前源码
# 是否含未提交修改，避免只写 commit 却把工作树内容误装成该提交的正式产物。
VERIFY_PKG_DIR="$PKG_DIR"
if [ "$(php -r 'echo DIRECTORY_SEPARATOR;')" = '\' ] && command -v wslpath >/dev/null 2>&1; then
    VERIFY_PKG_DIR="$(wslpath -w "$PKG_DIR")"
fi
SOURCE_COMMIT=$(repo_git rev-parse HEAD 2>/dev/null | tr -d '\r')
SOURCE_DIRTY=0
if [ -n "$(repo_git status --porcelain --untracked-files=normal)" ]; then
    echo "Error: 构建期间工作树发生变化，正式构建已中止。"
    exit 1
fi
php "tools/build-product-manifest.php" "$VERIFY_PKG_DIR" "$VERSION" "$BUILD_ID" "$SOURCE_COMMIT" "$SOURCE_DIRTY"

# ---- 验证关键文件 ----
echo "[3/5] 验证打包内容..."

ERRORS=0

if ! php "bin/blox-assets.php" verify-free "$VERIFY_PKG_DIR"; then
    ERRORS=$((ERRORS + 1))
fi

# 不应存在的文件
MUST_NOT_EXIST=(
    "installed.lock"
    "config/config.php"
    "config/installed.lock"
    ".git"
    "releases"
    "assets/css/src"
    "install/upgrade.php"
    "install/run_upgrade.php"
)
for f in "${MUST_NOT_EXIST[@]}"; do
    if [ -e "$PKG_DIR/$f" ]; then
        echo "  ✗ 不应存在: $f"
        ERRORS=$((ERRORS + 1))
    fi
done

# 必须存在的文件
MUST_EXIST=(
    "index.php"
    "config/config.sample.php"
    "config/config.php.example"
    "config/database.php"
    "config/build.php"
    "config/product.php"
    "config/provenance.php"
    "config/release-runtime.php"
    "includes/ProductIdentity.php"
    "includes/FooterNavigation.php"
    "includes/functions.php"
    "includes/LegacyInstallCleanup.php"
    "includes/SiteHealth.php"
    "includes/HomeSettingsLanguageDefaults.php"
    "admin/index.php"
    "admin/site_health.php"
    "config/site-health-probe.php"
    "includes/site-health-probe.php"
    "install/index.php"
    "install/sql/mysql.sql"
    "install/sql/sqlite.sql"
    "deploy/README.md"
    "deploy/nginx-server.conf"
    "deploy/nginx-baota.conf"
    "deploy/aliyun-nginx-minimal.txt"
    "migrations/20260817_repair_non_zh_home_factory_defaults.php"
    "assets/css/tailwind.css"
    "includes/Pinyin.php"
    "includes/pinyin/chars.php"
    "includes/pinyin/phrases.php"
    "includes/pinyin/overrides.php"
    "includes/pinyin/LICENSE.txt"
    "includes/pinyin/AUTHORS.txt"
    "uploads/.gitkeep"
    "storage/.gitkeep"
    ".htaccess"
)
for f in "${MUST_EXIST[@]}"; do
    if [ ! -e "$PKG_DIR/$f" ]; then
        echo "  ✗ 缺少文件: $f"
        ERRORS=$((ERRORS + 1))
    fi
done

# 根目录白名单：排除清单是黑名单，新增开发文件必然漏排（1.17.0 的
# playwright.config.js 就是这么发出去的）。这里反过来断言——包根只允许出现
# 已知文件，多一个就构建失败，逼开发者显式决定它该不该随包。
ROOT_ALLOWED=(
    ".htaccess" "LICENSE" "LICENSE-MIT-HISTORICAL" "README.md" "THIRD-PARTY-NOTICES.md"
    "favicon.ico" "robots.txt"
    "index.php" "article.php" "captcha.php" "contact.php" "cron.php" "detail.php"
    "download.php" "form_submit.php" "history.php" "job_detail.php" "list.php"
    "news.php" "page.php" "product.php" "search.php" "sitemap.php"
)
while IFS= read -r entry; do
    name="$(basename "$entry")"
    known=0
    for a in "${ROOT_ALLOWED[@]}"; do
        [ "$name" = "$a" ] && { known=1; break; }
    done
    if [ "$known" -eq 0 ]; then
        echo "  ✗ 包根出现未登记文件: $name（确认该随包则加进 build.sh 的 ROOT_ALLOWED，否则加进排除清单）"
        ERRORS=$((ERRORS + 1))
    fi
done < <(find "$PKG_DIR" -maxdepth 1 -type f)

if [ $ERRORS -gt 0 ]; then
    echo ""
    echo "Error: 验证失败（${ERRORS} 个问题），中止打包。"
    rm -rf "$TMP_DIR"
    exit 1
fi

echo "  ✓ 验证通过"

# ---- 统计 ----
FILE_COUNT=$(find "$PKG_DIR" -type f | wc -l)
echo "  文件总数: $FILE_COUNT"

# ---- 创建 ZIP ----
echo "[4/5] 创建 ZIP 包..."

ZIP_FILE="$RELEASE_DIR/${PACKAGE_NAME}.zip"
rm -f "$ZIP_FILE"

# 优先使用 zip 命令，其次用 PowerShell（WSL 环境）
if command -v zip &>/dev/null; then
    cd "$TMP_DIR"
    zip -r -q "$ZIP_FILE" "$PACKAGE_NAME"
    cd "$ROOT_DIR"
else
    # WSL 环境：压缩临时根目录，保持与 zip 分支相同的版本目录外壳。
    WIN_SOURCE=$(wslpath -w "$TMP_DIR")
    WIN_ZIP=$(wslpath -w "$ZIP_FILE")
    powershell.exe -Command "
        Add-Type -AssemblyName System.IO.Compression.FileSystem
        [System.IO.Compression.ZipFile]::CreateFromDirectory('$WIN_SOURCE', '$WIN_ZIP')
    "
fi

# ---- 生成校验和 ----
echo "[5/5] 生成 SHA256 校验和..."

SHA_FILE="$RELEASE_DIR/${PACKAGE_NAME}.sha256"
sha256sum "$ZIP_FILE" > "$SHA_FILE"

# Source checks are insufficient: validate the actual ZIP after all copy, prune and
# compression steps. This catches missing runtime data such as the pinyin dictionaries.
echo "[5a/5] 运行 Release Artifact Smoke Test..."
VERIFY_ZIP_FILE="$ZIP_FILE"
if [ "$(php -r 'echo DIRECTORY_SEPARATOR;')" = '\' ] && command -v wslpath >/dev/null 2>&1; then
    VERIFY_ZIP_FILE="$(wslpath -w "$ZIP_FILE")"
fi
php tools/release-artifact-smoke.php "$VERIFY_ZIP_FILE"

# ============================================================
# 生成增量升级包（delta）
#   目的：从最近 N 个历史版本各生成一个「只含变化文件」的小包，
#         让在线升级下载几 KB、只覆盖几个文件 —— 根治共享主机上
#         「解压 22MB + 逐个覆盖 800 文件」的代理超时（升级卡住）。
#   结构：delta-<from>-to-<VERSION>.zip = .delta-manifest.json + payload/ 镜像树
#   安全：客户端只在「当前版本 == delta.from」时使用，否则回退全量包。
# ============================================================
echo "[+] 生成增量升级包（delta）..."
DELTA_COUNT="${DELTA_BASES:-3}"        # 回溯的历史版本数（可用环境变量覆盖）
DELTA_FLOOR="${DELTA_FLOOR:-1.12.1}"   # 下限：更老版本的历史目录差异大，统一走全量包更稳
DELTA_EXTRA_FILE="${DELTA_EXTRA_FILE:-$ROOT_DIR/tools/delta-bases.txt}"
DELTA_JSON_ITEMS=()                    # 收集 releases.json 用的片段
# 同一目标版本可能残留灰度时代或中断构建的 delta。新构建开始前先清掉，最终允许
# 上传的集合只由本次生成的 deltas-v<version>.json 决定。
rm -f "$RELEASE_DIR"/delta-*-to-"$VERSION".zip \
      "$RELEASE_DIR"/delta-*-to-"$VERSION".sha256 \
      "$RELEASE_DIR/deltas-v${VERSION}.json"
# 基线集合 = 最近 N 个发布 ∪ 在野版本清单（tools/delta-bases.txt）。
# 只取最近 N 个远远不够：增量包按 from 精确匹配，客户实际在跑的版本才是要覆盖的对象。
# 2026-08-22 实测：63 个在野站点分布在 25 个版本上，最近 3 个版本只覆盖 13 站。
    ALL_TAGS=$(repo_git tag -l 'v*' | tr -d '\r' | sed 's/^v//' \
        | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V \
        | awk -v v="$VERSION" 'v==$0{exit} {print}')
    RECENT_BASES=$(printf '%s\n' "$ALL_TAGS" | tail -n "$DELTA_COUNT")
    WILD_BASES=""
    if [ -f "$DELTA_EXTRA_FILE" ]; then
        # 去掉注释与空行；再与真实存在的 tag 取交集（清单里写错/未发布的版本直接忽略）
        WILD_BASES=$(sed 's/#.*//' "$DELTA_EXTRA_FILE" | tr -d ' \r' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' \
            | sort -u | comm -12 - <(printf '%s\n' "$ALL_TAGS" | sort -u) || true)
        echo "  在野版本清单 $(basename "$DELTA_EXTRA_FILE"): $(printf '%s\n' "$WILD_BASES" | grep -c . || true) 个基线"
    fi
    mapfile -t PREV_BASES < <(printf '%s\n%s\n' "$RECENT_BASES" "$WILD_BASES" | grep -E '^[0-9]+\.[0-9]+\.[0-9]+$' | sort -V -u)

    if [ ${#PREV_BASES[@]} -eq 0 ]; then
        echo "  （无历史标签，跳过 delta 生成）"
    fi
    for base in "${PREV_BASES[@]}"; do
        # 下限护栏：base < DELTA_FLOOR 则跳过（这些老版本一律走全量包）
        if [ "$(printf '%s\n%s\n' "$DELTA_FLOOR" "$base" | sort -V | head -1)" != "$DELTA_FLOOR" ]; then
            echo "  （$base < 下限 $DELTA_FLOOR，跳过 delta）"
            continue
        fi
        tag="v$base"
        DELTA_DIR="$TMP_DIR/delta-$base"
        PAYLOAD="$DELTA_DIR/payload"
        mkdir -p "$PAYLOAD"
        DELETED=()
        # build.php / provenance.php 不在 git diff 中，但每个增量包都必须覆盖它们，
        # 同时切换 HTML 缓存命名空间和产品来源证明。
        mkdir -p "$PAYLOAD/config"
        cp "$PKG_DIR/config/build.php" "$PAYLOAD/config/build.php"
        cp "$PKG_DIR/config/provenance.php" "$PAYLOAD/config/provenance.php"
        ADDED=2
        # name-status：A/M/C 复制新内容；D 记删除；R 旧路径删、新路径复制
        while IFS=$'\t' read -r status path newpath; do
            [ -z "$status" ] && continue
            case "$status" in
                D)
                    # 市场主题安装后属于站点资产，核心增量包不得将其卸载。
                    # favicon.ico：v1.18.6 起不再随包发（它是站点资产，原文件又是我们的品牌图标，
                    # 不该出现在客户官网上）。但存量站根目录那个很可能是客户用图标工坊生成的——
                    # 从 git 移走后若不豁免，增量包会把它当废弃文件删掉。
                    # 两个遗留安装入口相反：必须让增量包不可逆地删掉它们（无鉴权可执行）。
                    case "$path" in
                        install/upgrade.php|install/run_upgrade.php) ;;
                        config/config.php|storage/*|uploads/*|install/*|themes/*|favicon.ico) continue;;
                    esac
                    DELETED+=("$path")
                    ;;
                R*)
                    if [ -f "$PKG_DIR/$newpath" ]; then
                        ( cd "$PKG_DIR" && cp --parents "$newpath" "$PAYLOAD/" ) && ADDED=$((ADDED + 1))
                    fi
                    case "$path" in
                        install/upgrade.php|install/run_upgrade.php) DELETED+=("$path");;
                        config/config.php|storage/*|uploads/*|install/*|themes/*) ;;
                        *) DELETED+=("$path");;
                    esac
                    ;;
                *)  # A / M / C：仅当该文件确实进了包（未被打包排除）才纳入
                    if [ -f "$PKG_DIR/$path" ]; then
                        ( cd "$PKG_DIR" && cp --parents "$path" "$PAYLOAD/" ) && ADDED=$((ADDED + 1))
                    fi
                    ;;
            esac
        done < <(repo_git diff --name-status "$tag" -- . 2>/dev/null | tr -d '\r')

        # 发版工作树可能包含尚未提交的新文件；完整包会纳入它们，delta 也必须保持一致。
        # 被打包规则排除的源码在 PKG_DIR 不存在，因此这里天然跳过 tests/marketplace 等。
        while IFS= read -r -d '' path; do
            if [ -f "$PKG_DIR/$path" ]; then
                ( cd "$PKG_DIR" && cp --parents "$path" "$PAYLOAD/" ) && ADDED=$((ADDED + 1))
            fi
        done < <(repo_git ls-files --others --exclude-standard -z)

        # Blox 基础编辑器可由私库注入并被公开仓库忽略，永远不会出现在 git diff 中。
        # 每个 delta 强制携带完整 core/runtime，避免升级成功后编辑器因缺文件白屏。
        for scope in core runtime; do
            while IFS= read -r item; do
                item="${item%$'\r'}"
                [ -z "$item" ] && continue
                if [ -d "$PKG_DIR/$item" ]; then
                    ( cd "$PKG_DIR" && cp -a --parents "$item" "$PAYLOAD/" ) && ADDED=$((ADDED + 1))
                elif [ -f "$PKG_DIR/$item" ]; then
                    ( cd "$PKG_DIR" && cp --parents "$item" "$PAYLOAD/" ) && ADDED=$((ADDED + 1))
                else
                    echo "Error: delta $base 缺少 Blox 免费 ${scope} 资产: $item"
                    exit 1
                fi
            done < <(php "bin/blox-assets.php" list "$scope")
        done

        # payload 不是完整 CMS，但对 Blox 免费资产必须是自洽的全集。
        # WSL 调 Windows php.exe 时要把 /tmp 路径转成 Windows 可识别路径。
        VERIFY_DELTA_PAYLOAD="$PAYLOAD"
        if [ "$(php -r 'echo DIRECTORY_SEPARATOR;')" = '\' ] && command -v wslpath >/dev/null 2>&1; then
            VERIFY_DELTA_PAYLOAD="$(wslpath -w "$PAYLOAD")"
        fi
        if ! php "bin/blox-assets.php" verify-free "$VERIFY_DELTA_PAYLOAD"; then
            echo "Error: delta $base → $VERSION 的 Blox 资产不完整"
            exit 1
        fi

        if [ "$ADDED" -eq 0 ] && [ ${#DELETED[@]} -eq 0 ]; then
            echo "  （$base → $VERSION 无打包内变化，跳过）"
            continue
        fi

        # 写包内 manifest（deleted 清单随包被 SHA256 覆盖，防篡改）
        del_json=""
        for d in "${DELETED[@]}"; do del_json="$del_json\"$d\","; done
        del_json="${del_json%,}"
        printf '{"from":"%s","to":"%s","deleted":[%s]}\n' "$base" "$VERSION" "$del_json" > "$DELTA_DIR/.delta-manifest.json"

        DELTA_ZIP="$RELEASE_DIR/delta-${base}-to-${VERSION}.zip"
        rm -f "$DELTA_ZIP"
        if command -v zip &>/dev/null; then
            ( cd "$DELTA_DIR" && zip -r -q "$DELTA_ZIP" .delta-manifest.json payload )
        else
            WIN_SRC=$(wslpath -w "$DELTA_DIR"); WIN_DZ=$(wslpath -w "$DELTA_ZIP")
            powershell.exe -Command "Add-Type -AssemblyName System.IO.Compression.FileSystem; [System.IO.Compression.ZipFile]::CreateFromDirectory('$WIN_SRC', '$WIN_DZ')"
        fi

        # 必须用客户端同样的 ZipArchive 语义复验。Windows Compress-Archive 会把条目写成
        # payload\...，哈希虽正确，但升级器按 payload/ 匹配时会得到 0 个文件。
        VERIFY_DELTA_ZIP="$DELTA_ZIP"
        if [ "$(php -r 'echo DIRECTORY_SEPARATOR;')" = '\' ] && command -v wslpath >/dev/null 2>&1; then
            VERIFY_DELTA_ZIP="$(wslpath -w "$DELTA_ZIP")"
        fi
        if ! php -r '
            $zip = new ZipArchive();
            if ($zip->open($argv[1]) !== true || $zip->getFromName(".delta-manifest.json") === false) exit(2);
            $files = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $name = $zip->getNameIndex($i);
                if ($name === false || strpos($name, "\\") !== false) exit(3);
                if (strncmp($name, "payload/", 8) === 0 && substr($name, -1) !== "/") $files++;
            }
            $zip->close();
            exit($files > 0 ? 0 : 4);
        ' "$VERIFY_DELTA_ZIP"; then
            echo "Error: delta $base → $VERSION 的 ZIP 条目结构不兼容升级器"
            exit 1
        fi
        sha256sum "$DELTA_ZIP" > "${DELTA_ZIP%.zip}.sha256"
        DHASH=$(cut -d' ' -f1 "${DELTA_ZIP%.zip}.sha256")
        DSIZE=$(stat -c%s "$DELTA_ZIP" 2>/dev/null || wc -c < "$DELTA_ZIP")
        DKB=$(( (DSIZE + 1023) / 1024 ))
        echo "  ✓ delta $base → $VERSION：$ADDED 个文件 / 删 ${#DELETED[@]} / ${DKB}KB  $(basename "$DELTA_ZIP")"
        DELTA_JSON_ITEMS+=("$(printf '{ "from": "%s", "package": "delta-%s-to-%s.zip", "hash": "sha256:%s", "size": "%sKB" }' "$base" "$base" "$VERSION" "$DHASH" "$DKB")")
    done

    # 输出可直接粘进 releases.json 对应版本条目的 deltas 片段
    if [ ${#DELTA_JSON_ITEMS[@]} -gt 0 ]; then
        DELTA_META_FILE="$RELEASE_DIR/deltas-v${VERSION}.json"
        { echo '"deltas": ['; printf '  %s,\n' "${DELTA_JSON_ITEMS[@]}" | sed '$ s/,$//'; echo ']'; } > "$DELTA_META_FILE"
        echo "  → deltas 元数据已写入 $(basename "$DELTA_META_FILE")（粘进 releases.json 对应版本条目）"
fi

# ---- 清理 ----
rm -rf "$TMP_DIR"

# ---- 结果 ----
ZIP_SIZE=$(ls -lh "$ZIP_FILE" | awk '{print $5}')
SHA_VALUE=$(cut -d' ' -f1 "$SHA_FILE")

echo ""
echo "=========================================="
echo " 打包完成!"
echo "=========================================="
echo " 文件: $ZIP_FILE"
echo " 大小: $ZIP_SIZE"
echo " SHA256: $SHA_VALUE"
echo " 文件数: $FILE_COUNT"
echo ""
echo " 发布到 GitHub:"
echo "   gh release create v${VERSION} \\"
echo "     releases/${PACKAGE_NAME}.zip \\"
echo "     releases/${PACKAGE_NAME}.sha256 \\"
echo "     --title 'Yikai CMS v${VERSION}'"
echo "=========================================="
