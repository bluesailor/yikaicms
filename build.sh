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

# 版本号：优先使用参数，否则从 config.sample.php 提取
if [ -n "$1" ]; then
    VERSION="$1"
else
    VERSION=$(grep -oP "CMS_VERSION',\s*'\\K[0-9]+\.[0-9]+\.[0-9]+" config/config.sample.php 2>/dev/null || echo "")
    if [ -z "$VERSION" ]; then
        echo "Error: 无法从 config.sample.php 读取版本号，请手动指定: bash build.sh 1.2.0"
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

# ---- 清理临时目录 ----
rm -rf "$TMP_DIR"
mkdir -p "$PKG_DIR"
mkdir -p "$RELEASE_DIR"

# ---- 复制全部文件 ----
echo "[1/5] 复制项目文件..."
cp -r "$ROOT_DIR"/* "$PKG_DIR/" 2>/dev/null || true
# 复制隐藏文件（如 .htaccess）
cp "$ROOT_DIR"/.htaccess "$PKG_DIR/" 2>/dev/null || true

# ---- 排除文件 ----
echo "[2/5] 排除不需要的文件..."

# 排除列表（相对于项目根目录）
EXCLUDES=(
    # 打包产物自身
    "releases"

    # 版本控制
    ".git"
    ".gitignore"
    ".gitattributes"

    # 安装锁（用户安装后才应生成）
    "installed.lock"
    "config/installed.lock"

    # 真实配置（包中只保留模板）
    "config/config.php"

    # 开发工具
    "build.sh"
    "build.bat"
    "psalm.xml"
    "composer.lock"
    ".editorconfig"
    ".claude"

    # CSS 源码（编译产物 tailwind.css 已包含）
    "assets/css/src"

    # 运行时数据（保留目录结构）
    "storage/database.sqlite"
    "storage/login_throttle"
    "storage/logs"
    "storage/cache"
)

for item in "${EXCLUDES[@]}"; do
    rm -rf "$PKG_DIR/$item"
done

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
