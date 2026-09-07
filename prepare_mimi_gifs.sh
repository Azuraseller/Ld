#!/usr/bin/env bash
set -euo pipefail
SRC=/home/ubuntu/projects/ld-tool-770cf429
OUT=/home/ubuntu/webdev-static-assets
mkdir -p "$OUT"

# Mapping theo hai asset duy nhất hiện có trong nguồn: ảnh dọc làm 624 mặc định,
# ảnh vuông làm 042 khi Mimi đang trả lời.
ffmpeg -hide_banner -loglevel error -y -i "$SRC/lv_0_20260905143634.gif" \
  -filter_complex "[0:v]crop=238:267:0:80,scale=240:240:flags=lanczos,setpts=PTS/0.75,split[a][b];[a]palettegen=max_colors=256[p];[b][p]paletteuse=dither=sierra2_4a" \
  -loop 0 "$OUT/624.gif"

ffmpeg -hide_banner -loglevel error -y -i "$SRC/lv_0_20260905144042.gif" \
  -filter_complex "[0:v]scale=240:240:flags=lanczos,setpts=PTS/0.75,split[a][b];[a]palettegen=max_colors=256[p];[b][p]paletteuse=dither=sierra2_4a" \
  -loop 0 "$OUT/042.gif"

for file in "$OUT/624.gif" "$OUT/042.gif"; do
  echo "FILE=$file"
  ffprobe -v error -select_streams v:0 -show_entries stream=width,height,nb_frames,r_frame_rate,duration -of default=noprint_wrappers=1 "$file"
done
