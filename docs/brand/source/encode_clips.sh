#!/bin/sh
# Encodes rendered clip frames: an MP4 on the cream #f5f0e8 backdrop and a ProRes 4444 .mov with alpha for slides.
# Usage: encode_clips.sh <frames_dir> <out_dir> [clip ...]   (all clips in <frames_dir> when none are named)
set -e
frames=$1 out=$2
shift 2
mkdir -p "$out"
clips=${*:-$(ls "$frames")}
for clip in $clips; do
  ffmpeg -y -loglevel error -f lavfi -i color=c=0xf5f0e8:s=1920x1080:r=24 -framerate 24 -i "$frames/$clip/%04d.png" \
    -filter_complex "[0][1]overlay=shortest=1,format=yuv420p" -c:v libx264 -preset slow -crf 16 -movflags +faststart "$out/yak-$clip.mp4"
  ffmpeg -y -loglevel error -framerate 24 -i "$frames/$clip/%04d.png" -c:v prores_ks -profile:v 4444 -pix_fmt yuva444p10le \
    -vendor apl0 "$out/yak-$clip-alpha.mov"
  echo "encoded $clip"
done
