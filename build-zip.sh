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

( cd "${DIST}" && zip -rq "${SLUG}.zip" "${SLUG}" )
rm -rf "${STAGE}"

echo "Built ${DIST}/${SLUG}.zip"
