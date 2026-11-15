#!/usr/bin/env bash
# Stops everything start.sh started. Idempotent. Keeps logs/ and laravel.offset; removes the database and the seed.
set -uo pipefail

YAK_E2E_DIR="${YAK_E2E_DIR:-${TMPDIR:-/tmp}/yak-e2e}"
YAK_E2E_DIR="${YAK_E2E_DIR%/}"

if [ -d "$YAK_E2E_DIR/pids" ]; then
    for pidFile in "$YAK_E2E_DIR"/pids/*.pid; do
        [ -e "$pidFile" ] || continue
        pid="$(cat "$pidFile" 2>/dev/null || true)"
        if [ -n "$pid" ] && kill -0 "$pid" 2>/dev/null; then
            kill -TERM "$pid" 2>/dev/null || true
            for _ in $(seq 1 100); do
                kill -0 "$pid" 2>/dev/null || break
                sleep 0.1
            done
            kill -0 "$pid" 2>/dev/null && kill -KILL "$pid" 2>/dev/null
        fi
        rm -f "$pidFile"
    done
fi

# The built-in server's worker children outlive the artisan process that spawned them.
pkill -f "php.* -S 127\.0\.0\.1:18000" 2>/dev/null || true

if command -v redis-cli >/dev/null 2>&1 && redis-cli -p 16379 ping >/dev/null 2>&1; then
    redis-cli -p 16379 shutdown nosave >/dev/null 2>&1 || true
fi
rm -f "$YAK_E2E_DIR"/redis.pid

rm -f "$YAK_E2E_DIR/seed.json" "$YAK_E2E_DIR"/yak.sqlite*
exit 0
