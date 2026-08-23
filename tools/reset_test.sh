#!/bin/bash
# ============================================================
# 一键重置 testcms.yikai 测试环境
#
# 功能：
#   1. 清空 testcms 数据库（SQLite + MySQL）
#   2. 清空 testcms 目录下所有文件
#   3. 从 ikaicms 复制干净的发布文件
#   4. 确保可直接访问安装向导
#
# 用法：bash reset_test.sh
# ============================================================

set -e

# 路径配置
SOURCE="/mnt/d/phpstudy_pro/WWW/ikaicms.yikai"
TARGET="/mnt/d/phpstudy_pro/WWW/testcms.yikai"
MYSQL="/mnt/d/phpstudy_pro/Extensions/MySQL8.0.12/bin/mysql.exe"
MYSQL_USER="root"
MYSQL_PASS="123456"
MYSQL_DB="testcms"

echo "=========================================="
echo " 重置 testcms.yikai 测试环境"
echo "=========================================="
echo ""
echo " 源目录: $SOURCE"
echo " 目标:   $TARGET"
echo ""

# ---- Step 1: 清空 MySQL 数据库 ----
echo "[1/4] 清空 MySQL 数据库 ($MYSQL_DB)..."

if [ -f "$MYSQL" ]; then
    # 检查数据库是否存在
    DB_EXISTS=$("$MYSQL" -u"$MYSQL_USER" -p"$MYSQL_PASS" -N -e "SELECT SCHEMA_NAME FROM INFORMATION_SCHEMA.SCHEMATA WHERE SCHEMA_NAME='$MYSQL_DB'" 2>/dev/null)

    if [ -n "$DB_EXISTS" ]; then
        "$MYSQL" -u"$MYSQL_USER" -p"$MYSQL_PASS" -e "DROP DATABASE \`$MYSQL_DB\`; CREATE DATABASE \`$MYSQL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>/dev/null
        echo "       MySQL: 已重建数据库 $MYSQL_DB"
    else
        "$MYSQL" -u"$MYSQL_USER" -p"$MYSQL_PASS" -e "CREATE DATABASE IF NOT EXISTS \`$MYSQL_DB\` CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;" 2>/dev/null
        echo "       MySQL: 已创建数据库 $MYSQL_DB"
    fi
else
    echo "       MySQL: 跳过（未找到 mysql.exe）"
fi

# ---- Step 2: 清空目标目录 ----
echo "[2/4] 清空目标目录..."

if [ -d "$TARGET" ]; then
    # 删除目标目录下所有文件和子目录
    rm -rf "$TARGET"/*
    rm -rf "$TARGET"/.[!.]* 2>/dev/null || true
    echo "       已清空 $TARGET"
else
    mkdir -p "$TARGET"
    echo "       已创建 $TARGET"
fi

# ---- Step 3: 复制发布文件 ----
echo "[3/4] 复制项目文件..."

# 排除不需要的文件/目录
rsync -a --delete \
    --exclude='.git/' \
    --exclude='.gitignore' \
    --exclude='.gitattributes' \
    --exclude='.claude/' \
    --exclude='docs/' \
    --exclude='node_modules/' \
    --exclude='releases/' \
    --exclude='assets/css/src/' \
    --exclude='config/config.php' \
    --exclude='installed.lock' \
    --exclude='storage/database.sqlite' \
    --exclude='storage/logs/*.log' \
    --exclude='storage/cache/*' \
    --exclude='storage/login_throttle/*' \
    --exclude='build.sh' \
    --exclude='build.bat' \
    --exclude='reset_test.sh' \
    --exclude='psalm.xml' \
    --exclude='composer.phar' \
    --exclude='composer.lock' \
    --exclude='package.json' \
    --exclude='package-lock.json' \
    --exclude='CLAUDE.md' \
    --exclude='*.bak' \
    --exclude='*.rar' \
    --exclude='*.log' \
    "$SOURCE/" "$TARGET/"

# 统计文件数
FILE_COUNT=$(find "$TARGET" -type f | wc -l)
echo "       已复制 $FILE_COUNT 个文件"

# ---- Step 4: 重置运行时目录 ----
echo "[4/4] 初始化目录结构..."

# 清空 uploads 内容
rm -rf "$TARGET/uploads/images/"* 2>/dev/null || true
rm -rf "$TARGET/uploads/files/"* 2>/dev/null || true
rm -rf "$TARGET/uploads/videos/"* 2>/dev/null || true
rm -rf "$TARGET/uploads/albums/"* 2>/dev/null || true

# 确保目录存在
mkdir -p "$TARGET/uploads/images"
mkdir -p "$TARGET/uploads/files"
mkdir -p "$TARGET/uploads/videos"
mkdir -p "$TARGET/uploads/albums"
mkdir -p "$TARGET/storage/logs"
mkdir -p "$TARGET/storage/cache"
mkdir -p "$TARGET/storage/login_throttle"

# 占位文件
touch "$TARGET/uploads/images/.gitkeep"
touch "$TARGET/uploads/files/.gitkeep"
touch "$TARGET/uploads/videos/.gitkeep"
touch "$TARGET/uploads/albums/.gitkeep"
touch "$TARGET/storage/logs/.gitkeep"
touch "$TARGET/storage/cache/.gitkeep"
touch "$TARGET/storage/login_throttle/.gitkeep"

# 删除安装锁（确保能进安装向导）
rm -f "$TARGET/installed.lock"
rm -f "$TARGET/config/config.php"
rm -f "$TARGET/storage/database.sqlite"

echo ""
echo "=========================================="
echo " 重置完成!"
echo "=========================================="
echo ""
echo " 文件数: $FILE_COUNT"
echo " MySQL:  $MYSQL_DB (已清空)"
echo ""
echo " 访问安装向导:"
echo "   http://testcms.yikai/install/"
echo ""
echo " 如需测试 MySQL 安装，数据库信息:"
echo "   主机: localhost"
echo "   数据库: $MYSQL_DB"
echo "   用户: $MYSQL_USER"
echo "   密码: $MYSQL_PASS"
echo "=========================================="
