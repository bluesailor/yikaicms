#!/usr/bin/env bash

set -euo pipefail

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
rsync -a --exclude .git --exclude BLOX-README.md "$source_path/" ./
test -f admin/blox_editor.php
echo "Injected: admin/blox_editor.php"
