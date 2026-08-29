#!/usr/bin/env bash
#
# Yikai CMS - Tailwind CSS 一键重建
#
# 用法：
#   bash tools/build_css.sh           # 一次性构建
#   bash tools/build_css.sh --watch   # 监听文件变化，热重建（开发用）
#
# 环境前提：
#   - Tailwind CSS 4.3.3 独立二进制或本地 npm CLI 存在
#   - 唯一入口源文件：assets/css/src/app.css
#   - 输出：assets/css/tailwind.css
#

set -e

EXPECTED_VERSION="4.3.3"

# 找到项目根（脚本所在目录的上一级）
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
ROOT_DIR="$(dirname "$SCRIPT_DIR")"
cd "$ROOT_DIR"

# 候选 tailwind 二进制位置（按优先级）
CANDIDATES=(
    "/mnt/d/phpstudy_pro/WWW/tailwindcss-windows-x64.exe"
    "$ROOT_DIR/../tailwindcss-windows-x64.exe"
    "$ROOT_DIR/tailwindcss-windows-x64.exe"
    "$ROOT_DIR/node_modules/.bin/tailwindcss"
    "$(command -v tailwindcss 2>/dev/null || true)"
)

TAILWIND=""
TAILWIND_VERSION=""
REJECTED=()
for c in "${CANDIDATES[@]}"; do
    if [ -n "$c" ] && [ -x "$c" ]; then
        VERSION_OUTPUT=$("$c" --help 2>&1 | sed -n '1,3p' | tr -d '\r' || true)
        if [[ "$VERSION_OUTPUT" == *"tailwindcss v${EXPECTED_VERSION}"* ]]; then
            TAILWIND="$c"
            TAILWIND_VERSION="${EXPECTED_VERSION}"
            break
        fi
        REJECTED+=("$c")
    fi
done

if [ -z "$TAILWIND" ]; then
    echo "✗ 找不到 Tailwind CSS ${EXPECTED_VERSION} 编译器。"
    if [ ${#REJECTED[@]} -gt 0 ]; then
        echo "以下编译器存在但版本不匹配："
        printf '  - %s\n' "${REJECTED[@]}"
    fi
    echo "期望以下其一存在且版本为 ${EXPECTED_VERSION}："
    printf '  - %s\n' "${CANDIDATES[@]}"
    echo
    echo "可下载：https://github.com/tailwindlabs/tailwindcss/releases"
    exit 1
fi

INPUT="assets/css/src/app.css"
OUTPUT="assets/css/tailwind.css"

if [ ! -f "$INPUT" ]; then
    echo "✗ 入口文件不存在：$INPUT"
    exit 1
fi

# 参数透传（--watch / --minify 等）
EXTRA_ARGS=("$@")
if [ ${#EXTRA_ARGS[@]} -eq 0 ]; then
    EXTRA_ARGS=("--minify")
fi

echo "→ Tailwind:  $TAILWIND"
echo "→ Version:   $TAILWIND_VERSION"
echo "→ Input:     $INPUT"
echo "→ Output:    $OUTPUT"
echo "→ Args:      ${EXTRA_ARGS[*]}"
echo

"$TAILWIND" -i "$INPUT" -o "$OUTPUT" "${EXTRA_ARGS[@]}"

if [ -f "$OUTPUT" ]; then
    SIZE=$(ls -la "$OUTPUT" | awk '{print $5}')
    echo
    echo "✓ 构建成功，大小 $SIZE bytes"
fi
