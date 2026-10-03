#!/usr/bin/env bash
#
# Run the Playwright suite against an isolated dev preview (see playwright.config.ts for why).
#
# Usage:  scripts/e2e.sh [playwright args...]     e.g. scripts/e2e.sh tests/e2e/ordering.spec.ts --project=mobile
#
set -euo pipefail

SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
PORT="${E2E_PORT:-3100}"
NAME="e2e"

"${SRC}/scripts/preview-copy.sh" "${NAME}" "${PORT}"

cleanup() {
  if [ -f "/tmp/doughboss-preview/${NAME}/dev.pid" ]; then
    kill "$(cat "/tmp/doughboss-preview/${NAME}/dev.pid")" 2>/dev/null || true
  fi
}
trap cleanup EXIT

cd "${SRC}"
E2E_BASE_URL="http://127.0.0.1:${PORT}" npx playwright test "$@"
