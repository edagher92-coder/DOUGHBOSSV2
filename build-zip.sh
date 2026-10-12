#!/usr/bin/env bash
# Build the review-candidate plugin archive with the portable PHP builder.
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
php "${ROOT}/scripts/build-zip.php" "$@"
