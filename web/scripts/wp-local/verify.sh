#!/usr/bin/env bash
# Verify the running runtime: plugin + theme active, activator tables exist, menu seeded, payments off.
# Exit 0 only if every check passes. Reads the SQLite DB read-only on the host (no writes).
set -uo pipefail
# shellcheck source=config.sh
. "$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/config.sh"

FAIL=0
ok()   { echo "PASS  $*"; }
bad()  { echo "FAIL  $*"; FAIL=1; }

curl -fsS --max-time 20 -o /dev/null "${WPL_URL}/?rest_route=/doughboss/v1/config" || { echo "FAIL  runtime not reachable at ${WPL_URL} (run start.sh)"; exit 1; }

PH="$(curl -sSI "${WPL_URL}/" | tr -d '\r' | awk -F': ' 'tolower($1)=="x-powered-by"{print $2}')"
echo "INFO  ${PH:-unknown php}"

CFG="$(curl -fsS "${WPL_URL}/?rest_route=/doughboss/v1/config")"
echo "${CFG}" | php -r '$j=json_decode(stream_get_contents(STDIN),true); exit(isset($j["payments_enabled"]) && $j["payments_enabled"]===false ? 0:1);' \
  && ok "REST /doughboss/v1/config reports payments_enabled=false" || bad "payments_enabled is not false"

MENU_N="$(curl -fsS "${WPL_URL}/?rest_route=/doughboss/v1/menu" | php -r '$j=json_decode(stream_get_contents(STDIN),true); $n=0; if(is_array($j)){ $n = isset($j["items"]) ? count($j["items"]) : count($j); } echo $n;')"
[ "${MENU_N:-0}" -ge 30 ] && ok "REST /doughboss/v1/menu returns ${MENU_N} items" || bad "menu items: ${MENU_N:-0} (expected >= 30)"

HOME_HTML="$(curl -sSL "${WPL_URL}/")"
echo "${HOME_HTML}" | grep -q '/wp-content/themes/doughboss-final/style.css' && ok "home page loads theme doughboss-final style.css" || bad "theme stylesheet not found on home page"
echo "${HOME_HTML}" | grep -q '/wp-content/plugins/doughboss/public/css/doughboss.css' && ok "home page loads plugin doughboss.css" || bad "plugin stylesheet not found on home page"
ORDER_CODE="$(curl -sSL -o /dev/null -w '%{http_code}' "${WPL_URL}/order/")"
[ "${ORDER_CODE}" = "200" ] && ok "/order/ -> 200" || bad "/order/ -> ${ORDER_CODE}"

VFS="$(cat "${WPL_VFS_FILE}" 2>/dev/null || true)"
DB="${VFS}/wordpress/wp-content/database/.ht.sqlite"
if [ -f "${DB}" ]; then
  RES="$(php -r '
    $db = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
    $t = []; $r = $db->query("SELECT name FROM sqlite_master WHERE type=\"table\" AND name LIKE \"%doughboss\\_%\" ESCAPE \"\\\"");
    while ($x = $r->fetchArray(SQLITE3_ASSOC)) { $t[] = $x["name"]; }
    $opt = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name=\"doughboss_db_version\"");
    $items = $db->querySingle("SELECT COUNT(*) FROM wp_posts WHERE post_type=\"doughboss_item\" AND post_status=\"publish\"");
    $theme = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name=\"stylesheet\"");
    $plug = $db->querySingle("SELECT option_value FROM wp_options WHERE option_name=\"active_plugins\"");
    echo count($t), "|", $opt, "|", $items, "|", $theme, "|", (strpos($plug, "doughboss/doughboss.php") !== false ? "active" : "inactive");
  ' "${DB}" 2>&1)"
  IFS='|' read -r NT DBV NI TH PA <<<"${RES}"
  [ "${NT:-0}" -ge 26 ] 2>/dev/null && ok "SQLite: ${NT} doughboss_* tables exist (activator ran), doughboss_db_version=${DBV}" || bad "SQLite tables: ${RES}"
  [ "${NI:-0}" -ge 30 ] 2>/dev/null && ok "SQLite: ${NI} published doughboss_item posts" || bad "SQLite items: ${RES}"
  [ "${TH}" = "doughboss-final" ] && ok "SQLite: active theme = ${TH}" || bad "active theme = ${TH}"
  [ "${PA}" = "active" ] && ok "SQLite: plugin doughboss/doughboss.php active" || bad "plugin inactive"
else
  bad "SQLite database not found at ${DB}"
fi
[ "${FAIL}" = 0 ] && echo "ALL CHECKS PASSED" || echo "SOME CHECKS FAILED"
exit "${FAIL}"
