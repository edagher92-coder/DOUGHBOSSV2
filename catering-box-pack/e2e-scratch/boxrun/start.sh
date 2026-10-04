#!/usr/bin/env bash
# Boot the local DoughBoss WordPress runtime (WordPress Playground CLI: PHP-WASM + SQLite).
# Idempotent: if it is already running and healthy, this prints the URL and exits 0.
#
#   scripts/wp-local/start.sh            # start (or no-op if already up)
#   scripts/wp-local/start.sh --restart  # stop first, then start fresh (re-copies plugin/theme, re-seeds)
#
# Env overrides: see config.sh (WPL_PORT, WPL_PHP, WPL_WP, WPL_PLUGIN_SRC, WPL_EXTRA_PLUGIN_SRC, WPL_ORDERING_OPEN, ...).
set -euo pipefail
# shellcheck source=config.sh
. /home/user/DOUGHBOSSV2/web/scripts/wp-local/config.sh

if [ "${1:-}" = "--restart" ]; then
  "${WPL_HERE}/stop.sh" >/dev/null || true
fi

healthy() {
  curl -fsS --max-time 20 -o /dev/null "${WPL_URL}/?rest_route=/doughboss/v1/config" 2>/dev/null
}
alive() {
  [ -f "${WPL_PIDFILE}" ] && kill -0 "$(cat "${WPL_PIDFILE}")" 2>/dev/null
}

mkdir -p "${WPL_RUN}/out" "${WPL_SRC}"

if alive && healthy; then
  echo "already running: ${WPL_URL} (pid $(cat "${WPL_PIDFILE}"))"
  exit 0
fi
if alive; then
  echo "process alive but not healthy; restarting" >&2
  "${WPL_HERE}/stop.sh" >/dev/null || true
fi
rm -f "${WPL_PIDFILE}"

# Port must be free (ours would have been caught above).
if (exec 3<>"/dev/tcp/${WPL_HOST}/${WPL_PORT}") 2>/dev/null; then
  echo "ERROR: port ${WPL_PORT} is in use by something else. Set WPL_PORT to a free port." >&2
  exit 1
fi

T0=$(date +%s)

# 1. Tooling: pinned Playground CLI in scratch (never inside web/node_modules).
if [ ! -x "${WPL_PG_BIN}" ]; then
  echo "installing @wp-playground/cli@${WPL_PLAYGROUND_VERSION} into ${WPL_PG_DIR} ..."
  mkdir -p "${WPL_PG_DIR}"
  (cd "${WPL_PG_DIR}" && { [ -f package.json ] || npm init -y >/dev/null; } \
    && npm install --no-audit --no-fund "@wp-playground/cli@${WPL_PLAYGROUND_VERSION}" >"${WPL_RUN}/npm-install.log" 2>&1)
fi

# 2. Copy (never mount in place) the plugin and the theme. The plugin root is the repo root of the
#    candidate worktree; leave out VCS, CI, docs, ops scripts, the bundled theme and tests (the
#    plugin does not load them at runtime).
[ -f "${WPL_PLUGIN_SRC}/doughboss.php" ] || { echo "ERROR: ${WPL_PLUGIN_SRC}/doughboss.php not found (set WPL_PLUGIN_SRC)" >&2; exit 1; }
[ -f "${WPL_THEME_SRC}/style.css" ]      || { echo "ERROR: ${WPL_THEME_SRC}/style.css not found (set WPL_THEME_SRC)" >&2; exit 1; }
rm -rf "${WPL_SRC}/plugins/doughboss" "${WPL_SRC}/themes/doughboss-final"
mkdir -p "${WPL_SRC}/plugins/doughboss" "${WPL_SRC}/themes"
tar -C "${WPL_PLUGIN_SRC}" --exclude=.git --exclude=.github --exclude=.claude --exclude=themes \
    --exclude=tests --exclude=docs --exclude=ops -cf - . | tar -C "${WPL_SRC}/plugins/doughboss" -xf -
cp -a "${WPL_THEME_SRC}" "${WPL_SRC}/themes/doughboss-final"
rm -f "${WPL_RUN}/out/seed-report.json"

# 2b. Optional extra plugin (WPL_EXTRA_PLUGIN_SRC, default unset = nothing changes): copy it, mount it, activate it.
EXTRA_NAME=""
EXTRA_MOUNT=()
EXTRA_STEP=""
if [ -n "${WPL_EXTRA_PLUGIN_SRC}" ]; then
  EXTRA_SRC="${WPL_EXTRA_PLUGIN_SRC%/}"
  EXTRA_NAME="$(basename "${EXTRA_SRC}")"
  case "${EXTRA_NAME}" in ''|*[!A-Za-z0-9._-]*|.|..) echo "ERROR: WPL_EXTRA_PLUGIN_SRC directory name '${EXTRA_NAME}' must match [A-Za-z0-9._-]+" >&2; exit 1;; esac
  [ -f "${EXTRA_SRC}/${EXTRA_NAME}.php" ] || { echo "ERROR: ${EXTRA_SRC}/${EXTRA_NAME}.php not found (the main file must be <dir-name>/<dir-name>.php)" >&2; exit 1; }
  rm -rf "${WPL_SRC}/plugins/${EXTRA_NAME}"
  mkdir -p "${WPL_SRC}/plugins/${EXTRA_NAME}"
  tar -C "${EXTRA_SRC}" --exclude=.git --exclude=.github --exclude=.claude --exclude=node_modules \
      --exclude=tests --exclude=scripts --exclude=docs --exclude=dist -cf - . | tar -C "${WPL_SRC}/plugins/${EXTRA_NAME}" -xf -
  EXTRA_MOUNT=(--mount "${WPL_SRC}/plugins/${EXTRA_NAME}:/wordpress/wp-content/plugins/${EXTRA_NAME}")
  EXTRA_STEP=$'\n    { "step": "activatePlugin", "pluginPath": "'"${EXTRA_NAME}/${EXTRA_NAME}"'.php" },'
fi

# 2c. Box plugins, extracted from the built zips (WPL_BOX_ZIPS dir).
rm -rf "${WPL_SRC}/plugins/doughboss-growth-box" "${WPL_SRC}/plugins/doughboss-growth-media"
unzip -q "${WPL_BOX_ZIP_CODE}" -d "${WPL_SRC}/plugins"
unzip -q "${WPL_BOX_ZIP_MEDIA}" -d "${WPL_SRC}/plugins"

# 3. Blueprint: activate theme + plugin (runs the plugin's activator), then seed.
if [ "${WPL_ORDERING_OPEN}" = "1" ]; then OPEN=true; else OPEN=false; fi
cat > "${WPL_BLUEPRINT}" <<JSON
{
  "\$schema": "https://playground.wordpress.net/blueprint-schema.json",
  "landingPage": "/",
  "preferredVersions": { "php": "${WPL_PHP}", "wp": "${WPL_WP}" },
  "steps": [
    { "step": "setSiteOptions", "options": { "blogname": "Dough Boss (local runtime)" } },
    { "step": "activateTheme",  "themeFolderName": "doughboss-final" },
    { "step": "activatePlugin", "pluginPath": "doughboss/doughboss.php" },
    { "step": "activatePlugin", "pluginPath": "doughboss-growth-media/doughboss-growth-media.php" },
    { "step": "activatePlugin", "pluginPath": "doughboss-growth-box/doughboss-growth-box.php" },${EXTRA_STEP}
    { "step": "runPHP", "code": "<?php require_once '/wordpress/wp-load.php'; \$GLOBALS['wpl_ordering_open'] = ${OPEN}; require '/internal/wpl/seed.php';" }
  ]
}
JSON

# 4. Launch detached. exec so the pidfile holds the node process itself.
: > "${WPL_LOG}"
setsid nohup bash -c 'echo $$ > "$1"; shift; exec "$@"' _ "${WPL_PIDFILE}" \
  "${WPL_PG_BIN}" server \
  --port "${WPL_PORT}" --php "${WPL_PHP}" --wp "${WPL_WP}" \
  --blueprint "${WPL_BLUEPRINT}" \
  --mount "${WPL_SRC}/plugins/doughboss:/wordpress/wp-content/plugins/doughboss" \
  --mount "${WPL_SRC}/themes/doughboss-final:/wordpress/wp-content/themes/doughboss-final" \
  ${EXTRA_MOUNT[@]+"${EXTRA_MOUNT[@]}"} \
  --mount "${WPL_SRC}/plugins/doughboss-growth-box:/wordpress/wp-content/plugins/doughboss-growth-box" \
  --mount "${WPL_SRC}/plugins/doughboss-growth-media:/wordpress/wp-content/plugins/doughboss-growth-media" \
  --mount "${WPL_HERE}/php:/internal/wpl" \
  --mount "${WPL_RUN}/out:/internal/wpl-out" \
  --define-bool WP_DEBUG true --define-bool WP_DEBUG_DISPLAY false --define-bool WP_DEBUG_LOG true \
  --define-bool DISABLE_WP_CRON true \
  --verbosity normal \
  > "${WPL_LOG}" 2>&1 < /dev/null &

for _ in $(seq 1 50); do [ -s "${WPL_PIDFILE}" ] && break; sleep 0.1; done

# 5. Wait for Ready and a healthy plugin REST route.
i=0
until grep -q "^Ready!" "${WPL_LOG}" 2>/dev/null; do
  alive || { echo "ERROR: playground exited during boot. Log tail:" >&2; tail -30 "${WPL_LOG}" >&2; exit 1; }
  i=$((i+1)); [ "$i" -gt "${WPL_BOOT_TIMEOUT}" ] && { echo "ERROR: boot timeout (${WPL_BOOT_TIMEOUT}s). Log tail:" >&2; tail -30 "${WPL_LOG}" >&2; exit 1; }
  sleep 1
done
# Record Playground's native VFS dir (holds wp-content/database/.ht.sqlite) for verify.sh/stop.sh.
# It is named node-playground-cli-site-<pid>--<pid>-<random> after the node process.
ls -d /tmp/node-playground-cli-site-"$(cat "${WPL_PIDFILE}")"-* 2>/dev/null | head -1 > "${WPL_VFS_FILE}" || true
j=0; until healthy; do j=$((j+1)); [ "$j" -gt 60 ] && { echo "ERROR: ready but REST not healthy" >&2; exit 1; }; sleep 1; done
# Warm the home page (the first request after install can answer with a redirect and be slow).
curl -sSL --max-time 90 -o /dev/null "${WPL_URL}/" || true

T1=$(date +%s)
echo "started: ${WPL_URL} (pid $(cat "${WPL_PIDFILE}"), PHP ${WPL_PHP}, WP ${WPL_WP}) in $((T1-T0))s"
[ -f "${WPL_RUN}/out/seed-report.json" ] && echo "seed report: ${WPL_RUN}/out/seed-report.json" || echo "WARNING: no seed report was written (seed step may have failed; see ${WPL_LOG})" >&2
