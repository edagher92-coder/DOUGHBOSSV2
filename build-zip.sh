#!/usr/bin/env bash
#
# Build an installable WordPress plugin ZIP from the repo.
#
# The implementation is PHP so it behaves the same from PowerShell, Git Bash,
# macOS and Linux CI (rather than relying on POSIX cp/find/zip utilities).
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "${ROOT}/scripts/build-zip.php"
