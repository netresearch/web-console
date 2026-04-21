#!/bin/sh
# Runs biome against resources/js. Prints a friendly hint and exits 1
# when npm is not installed (e.g. when `composer ci:test` is running
# inside a PHP-only CI container). Single-quoted to prevent shell
# substitution of the `npm install` mention.
set -e

if ! command -v npm >/dev/null 2>&1; then
    echo 'biome stage skipped: npm not found. Run '"'"'npm install'"'"' once on a host that has Node 20+.' >&2
    exit 1
fi

exec npm run --silent ci:js:check
