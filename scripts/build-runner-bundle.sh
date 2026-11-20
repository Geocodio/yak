#!/usr/bin/env bash
# Packs the agent runner (runner/) into <outdir>/yak-runner.tgz with its production
# node_modules, and writes <outdir>/version: a hash of the runner sources and lockfile.
# The app pushes the tarball into a sandbox whose /opt/yak-runner/.version differs.
# Usage: scripts/build-runner-bundle.sh <outdir>   (RUNNER_CPU defaults to x64)
set -euo pipefail

mkdir -p "$1"
outdir=$(cd "$1" && pwd)
cd "$(dirname "$0")/../runner"

version=$(find runner.mjs lib hooks package.json package-lock.json -type f -print0 \
    | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp -R runner.mjs lib hooks package.json package-lock.json "$stage/"
# The sandboxes are glibc Linux on the host's CPU, whatever machine builds the bundle.
(cd "$stage" && npm ci --omit=dev --no-audit --no-fund --os=linux --cpu="${RUNNER_CPU:-x64}" --libc=glibc)
test -f "$stage/node_modules/@anthropic-ai/claude-agent-sdk/package.json"

echo "$version" > "$stage/.version"
tar -czf "$outdir/yak-runner.tgz" -C "$stage" .
echo "$version" > "$outdir/version"
