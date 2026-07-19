#!/bin/bash
# ============================================================
# Yikai CMS - 发布打包脚本
#
# 用法：
#   bash build.sh          # 自动从 config.sample.php 读取版本号
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

# ---- 清理临时目录 ----
rm -rf "$TMP_DIR"
mkdir -p "$PKG_DIR"
mkdir -p "$RELEASE_DIR"

# ---- 复制文件（仅 git 跟踪的文件 + vendor 生产依赖）----
# 用 git ls-files 取「已跟踪文件」的当前工作树内容打包：
#   - 天然排除任何未跟踪 / 被 gitignore 的散落文件（测试 rar、截图、临时脚本等）
#   - 仍是工作树内容，未提交的改动照样进包（与旧 cp -r 行为一致）
echo "[1/5] 复制项目文件（git 跟踪 + vendor 生产依赖）..."
if git -C "$ROOT_DIR" rev-parse --git-dir >/dev/null 2>&1; then
    git -C "$ROOT_DIR" ls-files -z | tar -C "$ROOT_DIR" --null -T - -cf - | tar -xf - -C "$PKG_DIR"
else
    echo "  ⚠️ 非 git 仓库，回退到 cp -r（注意：可能打入根目录散落文件）"
    cp -r "$ROOT_DIR"/* "$PKG_DIR/" 2>/dev/null || true
    cp "$ROOT_DIR"/.htaccess "$PKG_DIR/" 2>/dev/null || true
fi
# vendor 被 gitignore 但运行时需要（overtrue/pinyin 生成中文 slug），单独复制，随后裁剪为生产依赖
cp -r "$ROOT_DIR/vendor" "$PKG_DIR/" 2>/dev/null || true

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
    "composer.phar"
    ".editorconfig"
    ".claude"

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

    # 注：vendor 不整体排除 —— 运行时需 overtrue/pinyin（生成中文 slug）。
    #     dev 依赖（psalm/phpunit 等）在下方循环后单独剔除，只保留生产部分。

    # CSS 源码（编译产物 tailwind.css 已包含）
    "assets/css/src"

    # 前端构建依赖（dev only，运行时不需要）
    "node_modules"
    "package.json"
    "package-lock.json"
    "tailwind.config.js"
    "postcss.config.js"

    # 插件：包内只预装核心体验两件（back-to-top 返回顶部、cookie-consent Cookie 同意），
    # 其余走插件市场按需安装（update.yikaicms.com/api/plugins/）。源码保留在仓库供开发与市场打包。
    "plugins/_example"
    "plugins/announcement"
    "plugins/menu-sort"
    "plugins/search-replace"
    "plugins/stats"

    # 临时测试文件（如本地 dev 时手写的）
    "recipe_test.php"
    "_i18n_test.php"

    # 运行时数据（保留目录结构）
    "storage/database.sqlite"
    "storage/login_throttle"
    "storage/logs"
    "storage/cache"
)

for item in "${EXCLUDES[@]}"; do
    rm -rf "$PKG_DIR/$item"
done

# vendor：只保留生产依赖（autoload + composer 元数据 + overtrue/pinyin），剔除 psalm/phpunit 等 dev 包
if [ -d "$PKG_DIR/vendor" ]; then
    find "$PKG_DIR/vendor" -mindepth 1 -maxdepth 1 \
        ! -name 'autoload.php' ! -name 'composer' ! -name 'overtrue' \
        -exec rm -rf {} +
    echo "  ✓ vendor 已精简为生产依赖（overtrue/pinyin）"
fi

# 清空 uploads 和 storage 内容，但保留目录
rm -rf "$PKG_DIR/uploads/"*
rm -rf "$PKG_DIR/storage/"*
touch "$PKG_DIR/uploads/.gitkeep"
touch "$PKG_DIR/storage/.gitkeep"

# ---- 验证关键文件 ----
echo "[3/5] 验证打包内容..."

ERRORS=0

# 不应存在的文件
MUST_NOT_EXIST=(
    "installed.lock"
    "config/config.php"
    "config/installed.lock"
    ".git"
    "releases"
    "assets/css/src"
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
    "includes/functions.php"
    "admin/index.php"
    "install/index.php"
    "install/sql/mysql.sql"
    "install/sql/sqlite.sql"
    "assets/css/tailwind.css"
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
    # WSL 环境：将路径转换为 Windows 格式给 PowerShell
    WIN_SOURCE=$(wslpath -w "$PKG_DIR")
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
