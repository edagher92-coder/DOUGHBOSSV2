#!/usr/bin/env bash
# Shared settings for the local DoughBoss WordPress runtime. Sourced by the other scripts.
# Every value can be overridden from the environment (WPL_*).
#
# This runtime is READ-ONLY with respect to the plugin/theme sources: it COPIES them into
# $WPL_STATE/src and mounts the copies. Nothing under WPL_PLUGIN_SRC is ever written.

WPL_HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
WPL_PORT="${WPL_PORT:-9410}"
WPL_HOST="127.0.0.1"
WPL_URL="http://${WPL_HOST}:${WPL_PORT}"
# Live is PHP 8.2.33 / WordPress 7.1 (candidate docs/REVIEW-20260908.md:29), so pin the same minors.
WPL_PHP="${WPL_PHP:-8.2}"
WPL_WP="${WPL_WP:-7.1}"
# Pinned: the version this runtime was verified with (npm @wp-playground/cli).
WPL_PLAYGROUND_VERSION="${WPL_PLAYGROUND_VERSION:-3.1.56}"
# Source trees (read-only). Plugin = repo root of the candidate; theme lives under themes/.
WPL_PLUGIN_SRC="${WPL_PLUGIN_SRC:-/tmp/wp-src/candidate-2.43.2}"
WPL_THEME_SRC="${WPL_THEME_SRC:-${WPL_PLUGIN_SRC}/themes/doughboss-final}"
# Scratch/state. Safe to delete when stopped.
WPL_STATE="${WPL_STATE:-/tmp/wp-local}"
# Optional second plugin to mount and activate next to DoughBoss (default unset: nothing extra, behaviour unchanged).
# Point it at a plugin directory whose main file is <dir-name>/<dir-name>.php, for example the companion:
#   WPL_EXTRA_PLUGIN_SRC=/path/to/repo/doughboss-growth scripts/wp-local/start.sh --restart
# It is COPIED (never mounted in place, never written) to ${WPL_SRC}/plugins/<dir-name> with tests, scripts, docs, dist
# and VCS/tooling folders left out, mounted into WordPress and activated after DoughBoss.
WPL_EXTRA_PLUGIN_SRC="${WPL_EXTRA_PLUGIN_SRC:-}"
# 1 = open online ordering (browse + cart UI). Payments stay OFF either way. Default 0 = the plugin's own default.
WPL_ORDERING_OPEN="${WPL_ORDERING_OPEN:-0}"
# How long start.sh waits for "Ready!" before giving up (seconds).
WPL_BOOT_TIMEOUT="${WPL_BOOT_TIMEOUT:-300}"

WPL_RUN="${WPL_STATE}/run"
WPL_PIDFILE="${WPL_RUN}/playground.pid"
WPL_LOG="${WPL_RUN}/playground.log"
WPL_VFS_FILE="${WPL_RUN}/vfs-dir"       # path of Playground's native VFS dir (holds the sqlite DB)
WPL_BLUEPRINT="${WPL_RUN}/blueprint.json"
WPL_PG_DIR="${WPL_PG_DIR:-${WPL_STATE}/pg}"  # local install of @wp-playground/cli (overridable so parallel instances can share one install)
WPL_PG_BIN="${WPL_PG_DIR}/node_modules/.bin/wp-playground-cli"
WPL_SRC="${WPL_STATE}/src"               # copies mounted into WordPress
