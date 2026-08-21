#!/usr/bin/env bash

set -euo pipefail

# 2026-08-21：Blox 源码已并回主仓，随 checkout 到场即为权威版本，无需再注入。
# 保留本脚本仅为兼容旧 workflow 调用；源码在场则直接跳过，绝不用私库副本覆盖。
if [ -f admin/blox_editor.php ]; then
    echo "::notice::Blox sources present in-repo; injection skipped (merged 2026-08-21)."
    exit 0
fi

if [ -z "${BLOX_DEPLOY_KEY:-}" ]; then
    echo "::notice::BLOX_DEPLOY_KEY absent; paid sources not injected."
    exit 0
fi

key_path="$HOME/.ssh/blox_key"
source_path="/tmp/blox-src"

cleanup() {
    rm -rf "$source_path" "$key_path"
}
trap cleanup EXIT

mkdir -p "$HOME/.ssh"
printf '%s\n' "$BLOX_DEPLOY_KEY" > "$key_path"
chmod 600 "$key_path"
ssh-keyscan github.com >> "$HOME/.ssh/known_hosts" 2>/dev/null
rm -rf "$source_path"

GIT_SSH_COMMAND="ssh -i $key_path -o IdentitiesOnly=yes" \
    git clone --depth 1 git@github.com:bluesailor/yikaicms-blox.git "$source_path"

echo "Blox private SHA: $(git -C "$source_path" rev-parse HEAD)"
# BLOX-README.md 一并注入：registry private 作用域登记了它，verify-source 要求在场；
# 主仓 .gitignore 覆盖该文件，git ls-files 打包路径不会把它带进发行 zip。
rsync -a --exclude .git "$source_path/" ./
test -f admin/blox_editor.php
echo "Injected: admin/blox_editor.php"
