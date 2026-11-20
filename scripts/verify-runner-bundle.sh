#!/usr/bin/env bash
# Fails unless <dir> holds both runner parts and their version files.
set -euo pipefail

dir=$1
for file in deps.tgz src.tgz deps.version src.version; do test -s "$dir/$file"; done
deps=$(tar -tzf "$dir/deps.tgz")
grep -q '^node_modules/@anthropic-ai/claude-agent-sdk/package.json$' <<< "$deps"
src=$(tar -tzf "$dir/src.tgz")
grep -q '^runner.mjs$' <<< "$src"
grep -q '^package-lock.json$' <<< "$src"
echo "runner bundle ok: deps $(cat "$dir/deps.version") src $(cat "$dir/src.version")"
