#!/usr/bin/env bash
set -euo pipefail

if [ "$#" -lt 2 ]; then
  echo "Usage: $0 <walkthrough.webm> <storyboard.json> [output.mp4]"
  exit 1
fi

WEBM_INPUT="$1"
STORYBOARD="$2"
OUTPUT="${3:-reviewer-cut.mp4}"

SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
VIDEO_DIR="$SCRIPT_DIR/../video"

# Resolve to absolute paths
WEBM_ABS="$(cd "$(dirname "$WEBM_INPUT")" && pwd)/$(basename "$WEBM_INPUT")"
STORYBOARD_ABS="$(cd "$(dirname "$STORYBOARD")" && pwd)/$(basename "$STORYBOARD")"

# Copy webm into the Remotion public/ directory so staticFile() can resolve it at render time
cp "$WEBM_ABS" "$VIDEO_DIR/public/_render-input.webm"

STORYBOARD_JSON="$(cat "$STORYBOARD_ABS")"
# videoUrl uses a public-dir filename; the composition wraps it with staticFile() at render time
PROPS=$(cat <<EOF
{"videoUrl":"_render-input.webm","storyboard":$STORYBOARD_JSON,"musicTrack":null,"tier":"reviewer"}
EOF
)

cd "$VIDEO_DIR"
npx remotion render src/index.ts Walkthrough "$OUTPUT" --props="$PROPS"

# Clean up the temp input
rm -f "$VIDEO_DIR/public/_render-input.webm"

echo "Rendered: $OUTPUT"
