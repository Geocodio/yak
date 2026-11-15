#!/usr/bin/env bash
# Starts a real Yak natively for the iOS journeys. Run from the repository root.
# Leaves the processes running; scripts/e2e/stop.sh stops them.
set -euo pipefail

startedAt=$SECONDS
export YAK_E2E_DIR="${YAK_E2E_DIR:-${TMPDIR:-/tmp}/yak-e2e}"
YAK_E2E_DIR="${YAK_E2E_DIR%/}"
scriptDir="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

fail() {
    echo "e2e: $1" >&2
    exit 1
}

php -r 'exit(PHP_VERSION_ID >= 80500 ? 0 : 1);' || fail "PHP 8.5 or newer is required; install it (brew install php) and put it first on PATH."
command -v redis-server >/dev/null || fail "redis-server is missing; install it (brew install redis)."
command -v redis-cli >/dev/null || fail "redis-cli is missing; install it (brew install redis)."
command -v curl >/dev/null || fail "curl is missing; install it."
command -v nc >/dev/null || fail "nc is missing; install netcat."
[ -f public/build/manifest.json ] || fail "public/build/manifest.json is missing; run npm run build first."

mkdir -p "$YAK_E2E_DIR/logs" "$YAK_E2E_DIR/pids"
processNames=()

failWith() {
    local message="$1" logName="${2:-}"
    echo "e2e: $message" >&2
    if [ -n "$logName" ] && [ -f "$YAK_E2E_DIR/logs/$logName.log" ]; then
        echo "e2e: last 50 lines of $logName.log:" >&2
        tail -n 50 "$YAK_E2E_DIR/logs/$logName.log" >&2
    fi
    "$scriptDir/stop.sh" >/dev/null 2>&1 || true
    exit 1
}

if redis-cli -p 16379 ping >/dev/null 2>&1; then
    fail "something already answers on Redis port 16379; run scripts/e2e/stop.sh first."
fi

trap '"$scriptDir/stop.sh" >/dev/null 2>&1' ERR INT TERM

redis-server --port 16379 --save "" --appendonly no --dir "$YAK_E2E_DIR" --daemonize yes \
    --pidfile "$YAK_E2E_DIR/redis.pid" --logfile "$YAK_E2E_DIR/logs/redis.log" >/dev/null
redisReady=0
for _ in $(seq 1 100); do
    if [ "$(redis-cli -p 16379 ping 2>/dev/null || true)" = "PONG" ]; then redisReady=1; break; fi
    sleep 0.1
done
[ "$redisReady" = 1 ] || failWith "Redis did not answer PING within 10 s." redis

# shellcheck source=env.sh
source "$scriptDir/env.sh"

laravelLog=storage/logs/laravel.log
if [ -f "$laravelLog" ]; then wc -c < "$laravelLog" | tr -d ' ' > "$YAK_E2E_DIR/laravel.offset"; else echo 0 > "$YAK_E2E_DIR/laravel.offset"; fi

rm -f "$YAK_E2E_DIR"/yak.sqlite*
touch "$YAK_E2E_DIR/yak.sqlite"
php artisan migrate:fresh --force > "$YAK_E2E_DIR/logs/migrate.log" 2>&1 || failWith "migrate:fresh failed." migrate
(umask 077; BROADCAST_CONNECTION=null php artisan yak:e2e-seed --url="$APP_URL" > "$YAK_E2E_DIR/seed.json" 2> "$YAK_E2E_DIR/logs/seed.log") || failWith "yak:e2e-seed failed." seed

launch() {
    local name="$1"; shift
    "$@" > "$YAK_E2E_DIR/logs/$name.log" 2>&1 < /dev/null &
    echo $! > "$YAK_E2E_DIR/pids/$name.pid"
    disown
    processNames+=("$name")
}

PHP_CLI_SERVER_WORKERS=4 launch serve php artisan serve --host=127.0.0.1 --port=18000 --no-reload
launch reverb php artisan reverb:start --host=127.0.0.1 --port=18080 --debug
for worker in 1 2; do
    launch "queue-$worker" php artisan queue:work redis --queue=default,yak-claude,yak-deployments,yak-render,yak-poll --timeout=900 --tries=1 --sleep=1
done
launch thread-drivers php artisan yak:thread-drivers
launch runner php artisan yak:e2e-runner --pace="$YAK_E2E_PACE"

ready=0
deadline=$((SECONDS + 60))
while [ "$SECONDS" -lt "$deadline" ]; do
    if [ "$(curl -s -o /dev/null -w '%{http_code}' --max-time 2 "$APP_URL/api/app/v1/instance" || true)" = "200" ]; then ready=1; break; fi
    kill -0 "$(cat "$YAK_E2E_DIR/pids/serve.pid")" 2>/dev/null || break
    sleep 0.5
done
[ "$ready" = 1 ] || failWith "instance check failed: GET /api/app/v1/instance did not answer 200 (the server exited or took over 60 s)." serve

ready=0
for _ in $(seq 1 60); do
    if nc -z 127.0.0.1 18080 2>/dev/null; then ready=1; break; fi
    sleep 0.5
done
[ "$ready" = 1 ] || failWith "Reverb check failed: port 18080 did not open within 30 s." reverb

ready=0
for _ in $(seq 1 60); do
    if [ -n "$(redis-cli -p 16379 get broker:alive 2>/dev/null || true)" ]; then ready=1; break; fi
    sleep 0.5
done
[ "$ready" = 1 ] || failWith "fake runner check failed: broker:alive was not set within 30 s." runner

sleep 5
for name in "${processNames[@]}"; do
    kill -0 "$(cat "$YAK_E2E_DIR/pids/$name.pid")" 2>/dev/null || failWith "process $name exited during startup." "$name"
done

trap - ERR INT TERM
echo "e2e: Yak ready in $((SECONDS - startedAt)) s, seed at $YAK_E2E_DIR/seed.json"
