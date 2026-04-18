#!/usr/bin/env bash
# Produces dist/yak-browser.js for consumption by the sandbox image build.
set -euo pipefail

SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
cd "$SRC_DIR"

npm install --no-audit --no-fund
npm run build
chmod +x dist/yak-browser.js

echo "Built: $SRC_DIR/dist/yak-browser.js"
