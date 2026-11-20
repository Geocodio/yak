#!/usr/bin/env bash
# Fails unless <dir> holds a runner tarball whose .version matches the version file.
set -euo pipefail

dir=$1
test -s "$dir/yak-runner.tgz"
test -s "$dir/version"
files=$(tar -tzf "$dir/yak-runner.tgz")
grep -q '^\./runner.mjs$' <<< "$files"
grep -q 'node_modules/@anthropic-ai/claude-agent-sdk/package.json$' <<< "$files"
test "$(tar -xzOf "$dir/yak-runner.tgz" ./.version)" = "$(cat "$dir/version")"
echo "runner bundle ok: $(cat "$dir/version")"
