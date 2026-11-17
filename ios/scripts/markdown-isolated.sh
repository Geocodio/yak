#!/usr/bin/env bash
# Runs every MarkdownLayoutTests input in its own test process, so memory is measured from a clean heap.
# YAK_LAYOUT_CONTAINER picks the container (scrollView, flattenedIntoLazyMessages, lazyMessages, eagerMessages).
# Usage: scripts/markdown-isolated.sh "iPhone 18 Pro" [InputName ...]
set -u
cd "$(dirname "$0")/.."
device="${1:?device name}"
shift
if [ $# -gt 0 ]; then
    inputs=("$@")
else
    inputs=($(awk '/enum Input:/,/nonisolated var text/' YakTests/MarkdownLayoutTests.swift \
        | grep -E '^[[:space:]]*case ' | sed -E 's/^[[:space:]]*case //' | tr ',' '\n' | tr -d ' '))
fi
build_directory="build/isolated"
xcodebuild build-for-testing -project Yak.xcodeproj -scheme Yak -destination "platform=iOS Simulator,name=$device" \
    -derivedDataPath "$build_directory" -only-testing:YakTests/MarkdownLayoutTests CODE_SIGNING_ALLOWED=NO >/dev/null || exit 1
failed=0
for input in "${inputs[@]}"; do
    rm -f "$build_directory/$input.log"
    if TEST_RUNNER_YAK_LAYOUT_INPUT="$input" TEST_RUNNER_YAK_LAYOUT_CONTAINER="${YAK_LAYOUT_CONTAINER:-scrollView}" xcodebuild test-without-building -project Yak.xcodeproj -scheme Yak \
        -destination "platform=iOS Simulator,name=$device" -derivedDataPath "$build_directory" \
        -only-testing:YakTests/MarkdownLayoutTests CODE_SIGNING_ALLOWED=NO >"$build_directory/$input.log" 2>&1 \
        && grep -q MEASURED "$build_directory/$input.log"; then
        echo "ok   $(grep -h MEASURED "$build_directory/$input.log" | head -1 | sed 's/^MEASURED //')"
    else
        echo "FAIL $input"
        grep -E "Expectation failed|issue" "$build_directory/$input.log" | head -4
        failed=1
    fi
done
exit "$failed"
