#!/usr/bin/env bash
# Packs the agent runner (runner/) into <outdir> as two parts with separate versions:
#   deps.tgz  node_modules (production), deps.version = hash of package-lock.json + target os/cpu/libc
#   src.tgz   runner.mjs lib hooks package*.json, src.version = hash of those files
# The app pushes only the part whose version differs from the sandbox's /opt/yak-runner/.{deps,src}-version.
# Usage: scripts/build-runner-bundle.sh <outdir>   (RUNNER_CPU defaults to x64)
set -euo pipefail

cpu="${RUNNER_CPU:-x64}"
mkdir -p "$1"
outdir=$(cd "$1" && pwd)
cd "$(dirname "$0")/../runner"

srcFiles=(runner.mjs lib hooks package.json package-lock.json)
srcVersion=$(find "${srcFiles[@]}" -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -c1-16)
depsVersion=$( (sha256sum package-lock.json; echo "linux-$cpu-glibc") | sha256sum | cut -c1-16)

stage=$(mktemp -d)
trap 'rm -rf "$stage"' EXIT
cp package.json package-lock.json "$stage/"
# The sandboxes are glibc Linux on the host's CPU, whatever machine builds the bundle.
(cd "$stage" && npm ci --omit=dev --no-audit --no-fund --os=linux --cpu="$cpu" --libc=glibc)
test -f "$stage/node_modules/@anthropic-ai/claude-agent-sdk/package.json"

tar -czf "$outdir/deps.tgz" -C "$stage" node_modules
tar -czf "$outdir/src.tgz" "${srcFiles[@]}"
echo "$depsVersion" > "$outdir/deps.version"
echo "$srcVersion" > "$outdir/src.version"
