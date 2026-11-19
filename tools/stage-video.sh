#!/usr/bin/env bash
# Stitches Playwright videos into ONE MP4 per stage (ТЗ 13.1), with a 2-second title card before each scenario.
# Usage: [SPEEDUP=3] tools/stage-video.sh <videos.tsv> <output.mp4> "<stage title>"
# SPEEDUP (default 1): playback speed of the scenario recordings; title cards always last 2 seconds.
set -euo pipefail

LIST="$1"
OUT="$2"
STAGE="${3:-Stage}"
FONT="${FONT:-/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf}"
SPEEDUP="${SPEEDUP:-1}"
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

card() { # $1 = text, $2 = output. Text is read from a file: commas, quotes and % in titles need no escaping.
	printf '%s' "$1" | fold -s -w 56 > "$2.txt"
	ffmpeg -nostdin -loglevel error -y -f lavfi -i "color=c=0x1d2327:s=1280x720:d=2:r=25" \
		-f lavfi -i "anullsrc=r=44100:cl=mono" -t 2 \
		-vf "drawtext=fontfile=${FONT}:textfile='$2.txt':expansion=none:fontcolor=white:fontsize=40:line_spacing=12:x=(w-text_w)/2:y=(h-text_h)/2" \
		-c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -shortest "$2"
}

norm() { # $1 = input webm, $2 = output
	ffmpeg -nostdin -loglevel error -y -i "$1" -f lavfi -i "anullsrc=r=44100:cl=mono" \
		-vf "setpts=PTS/${SPEEDUP},scale=1280:720:force_original_aspect_ratio=decrease,pad=1280:720:(ow-iw)/2:(oh-ih)/2,fps=25" \
		-c:v libx264 -preset veryfast -pix_fmt yuv420p -c:a aac -shortest "$2"
}

i=0
: > "$WORK/concat.txt"
card "$STAGE" "$WORK/000-title.mp4"
echo "file '$WORK/000-title.mp4'" >> "$WORK/concat.txt"

while IFS=$'\t' read -r video title; do
	[ -n "${video:-}" ] && [ -f "$video" ] || continue
	i=$((i + 1))
	n=$(printf '%03d' "$i")
	card "$title" "$WORK/$n-a.mp4"
	norm "$video" "$WORK/$n-b.mp4"
	echo "file '$WORK/$n-a.mp4'" >> "$WORK/concat.txt"
	echo "file '$WORK/$n-b.mp4'" >> "$WORK/concat.txt"
done < "$LIST"

ffmpeg -nostdin -loglevel error -y -f concat -safe 0 -i "$WORK/concat.txt" -c copy "$OUT"
echo "Video: $OUT ($i scenarios)"
