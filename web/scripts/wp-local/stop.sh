#!/usr/bin/env bash
# Stop the local DoughBoss WordPress runtime. Idempotent: exits 0 when nothing is running.
set -uo pipefail
# shellcheck source=config.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config.sh"

if [ -f "${WPL_PIDFILE}" ]; then
  PID="$(cat "${WPL_PIDFILE}")"
  if [ -n "${PID}" ] && kill -0 "${PID}" 2>/dev/null; then
    kill -TERM "${PID}" 2>/dev/null
    for _ in $(seq 1 20); do kill -0 "${PID}" 2>/dev/null || break; sleep 0.5; done
    if kill -0 "${PID}" 2>/dev/null; then kill -KILL "${PID}" 2>/dev/null; sleep 1; fi
    echo "stopped pid ${PID}"
  else
    echo "not running (stale pidfile removed)"
  fi
  rm -f "${WPL_PIDFILE}"
else
  echo "not running"
fi
# Playground does NOT remove its native temp dir when stopped with SIGTERM; remove ours.
if [ -f "${WPL_VFS_FILE}" ]; then
  V="$(cat "${WPL_VFS_FILE}")"
  case "${V}" in /tmp/node-playground-cli-site-*) [ -d "${V}" ] && rm -rf "${V}";; esac
  rm -f "${WPL_VFS_FILE}"
fi
# Sweep orphaned VFS dirs (~190 MB each) left by runs that were killed without a clean shutdown:
# the directory name embeds the node pid, so remove those whose pid is no longer alive.
for d in /tmp/node-playground-cli-site-*; do
  [ -d "$d" ] || continue
  p="${d#/tmp/node-playground-cli-site-}"; p="${p%%-*}"
  case "$p" in ''|*[!0-9]*) continue;; esac
  kill -0 "$p" 2>/dev/null || rm -rf "$d"
done
exit 0
