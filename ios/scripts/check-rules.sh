#!/usr/bin/env bash
# Project rules as grep checks. Exits non-zero when a rule is broken.
set -u
cd "$(dirname "$0")/.."

failed=0
targets=(Yak Packages/*/Sources)

fail() {
    echo "rule broken: $1"
    failed=1
}

check() {
    local pattern="$1" label="$2"
    shift 2
    local hits
    hits=$(grep -rnF --include='*.swift' "$pattern" "${targets[@]}" "$@" 2>/dev/null || true)
    if [ -n "$hits" ]; then
        echo "$hits"
        fail "$label"
    fi
}

check 'UIScreen.main' 'UIScreen.main'
check 'userInterfaceIdiom' 'userInterfaceIdiom'
check 'UIDevice.current.orientation' 'UIDevice.current.orientation'
check 'print(' 'print('

http_hits=$(grep -rnF --include='*.swift' 'http://' "${targets[@]}" 2>/dev/null \
    | grep -v '/TestMode.swift:' | grep -v '/YakStub/' || true)
if [ -n "$http_hits" ]; then
    echo "$http_hits"
    fail 'http:// outside TestMode.swift and the stub'
fi

ats_hits=$(grep -rn 'NSAllowsArbitraryLoads' "${targets[@]}" project.yml 2>/dev/null || true)
if [ -n "$ats_hits" ]; then
    echo "$ats_hits"
    fail 'NSAllowsArbitraryLoads'
fi

[ -f Yak/Support/PrivacyInfo.xcprivacy ] || fail 'Yak/Support/PrivacyInfo.xcprivacy is missing'
grep -q 'UILaunchScreen' project.yml || fail 'project.yml does not name the launch screen key'

exit "$failed"
