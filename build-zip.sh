#!/usr/bin/env bash
#
# Build an installable WordPress plugin zip from the repo.
#
# Produces ./dist/doughboss.zip with a top-level `doughboss/` directory so it
# installs cleanly via Plugins → Add New → Upload Plugin.
#
set -euo pipefail

SLUG="doughboss"
ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
DIST="${ROOT}/dist"
STAGE="${DIST}/${SLUG}"

# --- Release-consistency gate -------------------------------------------------
# The plugin version, readme.txt's Stable tag and readme.txt's top changelog
# entry must all agree, or the zip ships with a stale/contradictory version.
PLUGIN_VERSION="$(sed -n "s/^define( 'DOUGHBOSS_VERSION', '\([0-9][0-9.]*\)' );.*/\1/p" "${ROOT}/doughboss.php")"
STABLE_TAG="$(sed -n 's/^Stable tag:[[:space:]]*//p' "${ROOT}/readme.txt" | tr -d '[:space:]')"
TOP_CHANGELOG="$(awk '/^== Changelog ==/{flag=1;next} flag && /^= /{gsub(/[= ]/,"");print;exit}' "${ROOT}/readme.txt")"

if [ -z "${PLUGIN_VERSION}" ]; then
	echo "ERROR: could not read DOUGHBOSS_VERSION from doughboss.php" >&2
	exit 1
fi
if [ "${PLUGIN_VERSION}" != "${STABLE_TAG}" ]; then
	echo "ERROR: DOUGHBOSS_VERSION (${PLUGIN_VERSION}) != readme.txt Stable tag (${STABLE_TAG})" >&2
	exit 1
fi
if [ "${PLUGIN_VERSION}" != "${TOP_CHANGELOG}" ]; then
	echo "ERROR: DOUGHBOSS_VERSION (${PLUGIN_VERSION}) != readme.txt top changelog entry (${TOP_CHANGELOG})" >&2
	exit 1
fi

rm -rf "${DIST}"
mkdir -p "${STAGE}"

# Files/dirs that make up the shippable plugin.
cp "${ROOT}/doughboss.php" "${STAGE}/"
cp "${ROOT}/uninstall.php" "${STAGE}/"
cp "${ROOT}/readme.txt" "${STAGE}/"
cp "${ROOT}/README.md" "${STAGE}/"
cp -r "${ROOT}/includes" "${STAGE}/"
cp -r "${ROOT}/admin" "${STAGE}/"
cp -r "${ROOT}/public" "${STAGE}/"

# Menu seeder wrapper (the canonical seeder ships as `wp doughboss seed-menu` in
# includes/; this also lets owners run `wp eval-file scripts/seed-menu.php`).
if [ -f "${ROOT}/scripts/seed-menu.php" ]; then
	mkdir -p "${STAGE}/scripts"
	cp "${ROOT}/scripts/seed-menu.php" "${STAGE}/scripts/"
fi

# Languages dir is optional; include it if present.
if [ -d "${ROOT}/languages" ]; then
	cp -r "${ROOT}/languages" "${STAGE}/"
fi

# Lint every staged PHP file — never ship a zip with a parse error.
PHP_BIN="$(command -v php || echo /usr/bin/php)"
if [ ! -x "${PHP_BIN}" ]; then
	echo "ERROR: php not found; cannot lint the staged tree" >&2
	exit 1
fi
while IFS= read -r -d '' file; do
	"${PHP_BIN}" -l "${file}" > /dev/null
done < <(find "${STAGE}" -name '*.php' -print0)

( cd "${DIST}" && zip -rq "${SLUG}.zip" "${SLUG}" )
rm -rf "${STAGE}"

echo "Built ${DIST}/${SLUG}.zip"
