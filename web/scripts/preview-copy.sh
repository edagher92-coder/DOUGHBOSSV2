#!/usr/bin/env bash
#
# Isolated preview of the app for browser testing.
#
# Several people/agents work in web/ at once, and "next dev" / "next build" both
# write to .next/ and rewrite tsconfig.json — running them concurrently in the
# same directory corrupts both. This script snapshots the source into
# /tmp/doughboss-preview/<name>/ (sharing node_modules by symlink) and runs
# "next dev" there, on its own port. Re-run it any time to refresh the copy; the
# already-running dev server hot-reloads the changes.
#
# Usage:   scripts/preview-copy.sh <name> <port>
# Env:     DOUGHBOSS_DEMO_DATA (default 1) loads the clearly-fake demo catalogue so
#          ordering can be exercised; set to 0 to see the real (unpriced) data.
# Stop:    kill "$(cat /tmp/doughboss-preview/<name>/dev.pid)"
#
set -euo pipefail

NAME="${1:?usage: preview-copy.sh <name> <port>}"
PORT="${2:?usage: preview-copy.sh <name> <port>}"
SRC="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
DEST="/tmp/doughboss-preview/${NAME}"

mkdir -p "${DEST}"
# Replace the source trees wholesale so files deleted in web/ disappear here too.
for d in src public prisma content marketing scripts tests docs labs; do rm -rf "${DEST:?}/${d}"; done
tar -C "${SRC}" \
  --exclude=./node_modules --exclude=./.next --exclude=./test-results --exclude=./playwright-report \
  --exclude='./.next-*' -cf - . | tar -C "${DEST}" -xf -
ln -sfn "${SRC}/node_modules" "${DEST}/node_modules"

# Temporary lab pages: web/labs/<name>/page.tsx is mounted at /lab/<name> in the PREVIEW ONLY,
# so a slice can be viewed on its own without touching app/page.tsx. labs/ is git-ignored.
if [ -d "${DEST}/labs" ]; then
  mkdir -p "${DEST}/src/app/lab"
  cp -R "${DEST}/labs/." "${DEST}/src/app/lab/"
fi

cd "${DEST}"
if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/"; then
  echo "preview refreshed: http://127.0.0.1:${PORT}/  (dev server already running; log ${DEST}/dev.log)"
  exit 0
fi

DOUGHBOSS_DEMO_DATA="${DOUGHBOSS_DEMO_DATA:-1}" \
NEXT_PUBLIC_DEMO_DATA="${DOUGHBOSS_DEMO_DATA:-1}" \
ALLOW_PAY_AT_PICKUP="${ALLOW_PAY_AT_PICKUP:-1}" \
NEXT_TELEMETRY_DISABLED=1 \
  nohup npx next dev -p "${PORT}" > "${DEST}/dev.log" 2>&1 &
echo $! > "${DEST}/dev.pid"

for _ in $(seq 1 120); do
  if curl -sf -o /dev/null "http://127.0.0.1:${PORT}/"; then
    echo "preview ready: http://127.0.0.1:${PORT}/  (log ${DEST}/dev.log, pid file ${DEST}/dev.pid)"
    exit 0
  fi
  sleep 1
done
echo "preview did not become ready in 120s — see ${DEST}/dev.log" >&2
tail -40 "${DEST}/dev.log" >&2 || true
exit 1
