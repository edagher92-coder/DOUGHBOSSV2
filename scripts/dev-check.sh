#!/usr/bin/env bash
# scripts/dev-check.sh
#
# DoughBoss project verifier for fresh AI / web (Claude Code) sessions.
# Lints every PHP file with `php -l` and prints a PASS/FAIL summary.
#
# Resilient by contract: this script ALWAYS exits 0 so a SessionStart hook
# can never block or abort a session. Failures are reported in the output,
# not via the exit code. Fast, dependency-light, no network.
set -u

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 0

echo "=== DoughBoss dev-check ==="
echo "branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo n/a)"

# php may not be on PATH in every session — fall back to common locations.
PHP_BIN=""
for cand in php /usr/bin/php /usr/local/bin/php; do
	if command -v "$cand" >/dev/null 2>&1; then PHP_BIN="$cand"; break; fi
done
if [ -z "$PHP_BIN" ]; then
	echo "WARN: php not found — skipping syntax checks."
	echo "RESULT: SKIPPED"
	exit 0
fi
echo "php:    $("$PHP_BIN" -r 'echo PHP_VERSION;' 2>/dev/null) ($PHP_BIN)"
echo "--- php -l (syntax lint) ---"

total=0
failed=0
# Process substitution keeps the loop in the current shell, so counters
# survive (no pipe subshell, no temp files). Repo paths have no spaces.
while IFS= read -r f; do
	total=$((total + 1))
	if ! err="$("$PHP_BIN" -l "$f" 2>&1)"; then
		failed=$((failed + 1))
		echo "FAIL: $f"
		printf '%s\n' "$err" | sed 's/^/      /'
	fi
done < <(find . -type d \( -name .git -o -name vendor -o -name node_modules -o -name dist \) -prune \
	-o -type f -name '*.php' -print)

echo "--- summary: $((total - failed))/$total passed · $failed failed ---"

# Optional phpcs, only if the toolchain is actually present.
if [ -f composer.json ] && grep -q "php_codesniffer\|wp-coding-standards\|phpcs" composer.json 2>/dev/null; then
	if [ -x vendor/bin/phpcs ]; then
		echo "--- phpcs ---"
		vendor/bin/phpcs -q . || true
	else
		echo "note: phpcs configured but not installed (run 'composer install')."
	fi
fi

if [ "$failed" -eq 0 ]; then
	echo "RESULT: PASS"
else
	echo "RESULT: FAIL (see above)"
fi
exit 0
