#!/usr/bin/env bash
set -euo pipefail

attempts="${PLAYWRIGHT_INSTALL_ATTEMPTS:-3}"
timeout_seconds="${PLAYWRIGHT_INSTALL_TIMEOUT_SECONDS:-420}"
retry_delay="${PLAYWRIGHT_INSTALL_RETRY_DELAY_SECONDS:-15}"

if ! [[ "$attempts" =~ ^[1-9][0-9]*$ && "$timeout_seconds" =~ ^[1-9][0-9]*$ && "$retry_delay" =~ ^[0-9]+$ ]]; then
  echo "::error title=Invalid Playwright retry settings::Attempts and timeout must be positive integers; delay must be non-negative."
  exit 2
fi

for ((attempt = 1; attempt <= attempts; attempt++)); do
  echo "::group::Install Chromium (attempt ${attempt}/${attempts})"
  set +e
  timeout --signal=TERM --kill-after=30s "${timeout_seconds}s" \
    npx playwright install --with-deps chromium
  status=$?
  set -e
  echo "::endgroup::"

  if [[ $status -eq 0 ]]; then
    exit 0
  fi

  if [[ $attempt -lt $attempts ]]; then
    echo "::warning title=Chromium install retry::Attempt ${attempt} exited with ${status}; retrying after ${retry_delay}s."
    sleep "$retry_delay"
  fi
done

echo "::error title=Chromium install failed::All ${attempts} attempts failed or timed out."
exit "$status"
