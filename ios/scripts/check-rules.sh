#!/usr/bin/env bash
# Project rules as grep checks. Exits non-zero when a rule is broken.
set -u
cd "$(dirname "$0")/.."

failed=0
targets=(Yak Packages/*/Sources)
extension_targets=()
deferred_checks=()
# Folders where no rust-colored token may appear, and the files inside them that may name one on purpose.
no_rust_folders=(Yak/Thread Yak/Chat Yak/Composer Yak/Questions)
rust_allowed_files=()

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

for rules in scripts/rules.d/*.sh; do [ -e "$rules" ] && . "$rules"; done

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

# Only "Open PR" is rust-colored on a thread screen. A feature's rules.d file adds its folders to
# no_rust_folders and the files that may name a token to rust_allowed_files.
for token in yakRust yakOnRust PrimaryButtonStyle; do
    hits=$(grep -rnF --include='*.swift' "$token" "${no_rust_folders[@]}" 2>/dev/null || true)
    for allowed in ${rust_allowed_files[@]+"${rust_allowed_files[@]}"}; do
        hits=$(printf '%s\n' "$hits" | grep -vF "$allowed:" || true)
    done
    if [ -n "$hits" ]; then
        echo "$hits"
        fail "$token in a thread screen"
    fi
done

[ -f Yak/Support/PrivacyInfo.xcprivacy ] || fail 'Yak/Support/PrivacyInfo.xcprivacy is missing'
grep -q 'UILaunchScreen' project.yml || fail 'project.yml does not name the launch screen key'

for deferred in ${deferred_checks[@]+"${deferred_checks[@]}"}; do "$deferred"; done

exit "$failed"
