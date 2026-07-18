#!/usr/bin/env bash
# scripts/dev-check.sh
#
# DoughBoss project verifier for AI sessions and CI.
#
# Default mode is session-safe and always exits 0 so Claude/ChatGPT session-start
# hooks are never blocked. Pass --strict in CI to return a failing exit code when
# verification fails.
set -u

STRICT=0
if [ "${1:-}" = "--strict" ]; then
	STRICT=1
fi

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT" || exit 0

failed=0
php_total=0
php_failed=0
js_total=0
js_failed=0

echo "=== DoughBoss dev-check ==="
echo "branch: $(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo n/a)"
echo "mode:   $([ "$STRICT" -eq 1 ] && echo strict || echo session)"

# php may not be on PATH in every session — fall back to common locations.
PHP_BIN=""
for cand in php /usr/bin/php /usr/local/bin/php; do
	if command -v "$cand" >/dev/null 2>&1; then PHP_BIN="$cand"; break; fi
done

if [ -z "$PHP_BIN" ]; then
	echo "WARN: php not found — skipping PHP syntax checks."
	if [ "$STRICT" -eq 1 ]; then
		echo "RESULT: FAIL (php missing in strict mode)"
		exit 1
	fi
	echo "RESULT: SKIPPED"
	exit 0
fi

echo "php:    $($PHP_BIN -r 'echo PHP_VERSION;' 2>/dev/null) ($PHP_BIN)"
echo "--- php -l (syntax lint) ---"

while IFS= read -r f; do
	php_total=$((php_total + 1))
	if ! err="$($PHP_BIN -l "$f" 2>&1)"; then
		php_failed=$((php_failed + 1))
		failed=$((failed + 1))
		echo "FAIL: $f"
		printf '%s\n' "$err" | sed 's/^/      /'
	fi
done < <(find . -type d \( -name .git -o -name vendor -o -name node_modules -o -name dist \) -prune \
	-o -type f -name '*.php' -print)

echo "--- php summary: $((php_total - php_failed))/$php_total passed · $php_failed failed ---"

# JavaScript syntax check is intentionally dependency-light. It runs when node is
# available and silently skips in normal sessions where node is absent.
if command -v node >/dev/null 2>&1; then
	echo "node:   $(node --version)"
	echo "--- node --check (JS syntax) ---"
	while IFS= read -r f; do
		js_total=$((js_total + 1))
		if ! err="$(node --check "$f" 2>&1)"; then
			js_failed=$((js_failed + 1))
			failed=$((failed + 1))
			echo "FAIL: $f"
			printf '%s\n' "$err" | sed 's/^/      /'
		fi
	done < <(find . -type d \( -name .git -o -name vendor -o -name node_modules -o -name dist \) -prune \
		-o -type f -name '*.js' -print)
	echo "--- js summary: $((js_total - js_failed))/$js_total passed · $js_failed failed ---"
else
	echo "note: node not found — skipping JS syntax checks."
fi

# Optional phpcs, only if the toolchain is actually present.
if [ -f composer.json ] && grep -q "php_codesniffer\|wp-coding-standards\|phpcs" composer.json 2>/dev/null; then
	if [ -x vendor/bin/phpcs ]; then
		echo "--- phpcs ---"
		if ! vendor/bin/phpcs -q .; then
			failed=$((failed + 1))
		fi
	else
		echo "note: phpcs configured but not installed (run 'composer install')."
		if [ "$STRICT" -eq 1 ]; then
			failed=$((failed + 1))
		fi
	fi
fi

if [ "$failed" -eq 0 ]; then
	echo "RESULT: PASS"
	exit 0
fi

echo "RESULT: FAIL ($failed failed check group(s); see above)"
if [ "$STRICT" -eq 1 ]; then
	exit 1
fi
exit 0
