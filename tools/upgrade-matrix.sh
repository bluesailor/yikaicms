#!/usr/bin/env bash
#
# 升级回归一键检查（兼容 + 安装对盘）
#
# 目标：每次发版前，用一条命令覆盖以下三类场景
# 1) 旧版本兼容导入：1.12.9 / 1.17.x / v1.18.x
# 2) schema 双向对盘：全新安装 ≡ 老站跑迁移
#
# 用法：
#   bash tools/upgrade-matrix.sh
#   UPGRADE_FROM_TAGS='v1.12.9 v1.17.0 v1.17.2' bash tools/upgrade-matrix.sh
#   bash tools/upgrade-matrix.sh v1.17.1 v1.18.0
#
# 兼容检查依赖 tags；无 git 也可在 WSL 里用 YK_TAG_SQL_DIR 提前放好
#   v1.xx.xx.sqlite.sql，对应 tests/smoke/blox_upgrade_compat.php 的退化路径。

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

overall=0
run_or_record() {
    local label="$1"
    shift

    local code=0
    if "$@"; then
        echo "[$label] OK"
    else
        code=$?
        echo "[$label] FAIL (code=$code)"
        if [ "$code" -gt "$overall" ]; then
            overall=$code
        fi
    fi
}

default_tags=(v1.12.9 v1.14.0 v1.17.0 v1.17.2 v1.17.3.2 v1.18.1 v1.18.2 v1.18.3 v1.18.4)
if [ "$#" -gt 0 ]; then
    from_tags=("$@")
elif [ -n "${UPGRADE_FROM_TAGS:-}" ]; then
    # shellcheck disable=SC2206
    from_tags=(${UPGRADE_FROM_TAGS})
else
    from_tags=("${default_tags[@]}")
fi

# 1) 旧站兼容导入（含 1.12.9 与 1.17.x）
for tag in "${from_tags[@]}"; do
    if [[ ! "$tag" =~ ^v1\.[0-9]+\.[0-9]+(\.[0-9]+)?$ ]]; then
        echo "skip: invalid tag token '$tag' (expected v1.x.y[.z])"
        continue
    fi
    run_or_record "compat $tag" php tests/smoke/blox_upgrade_compat.php --from="$tag"
done

# 2) 全新安装 + 老站迁移对盘（1.12.9 为基线）
run_or_record "schema_parity" php tests/smoke/schema_parity.php

if [ "$overall" -ne 0 ]; then
    echo
    echo "!! 升级回归未通过（exit=$overall）"
    exit "$overall"
fi

echo
echo "✓ 升级回归完成：兼容导入 + 对盘验证"
exit 0
