from __future__ import annotations

from collections import deque
from pathlib import Path
from PIL import Image, ImageSequence

SRC = Path('/home/ubuntu/projects/ld-tool-770cf429')
OUT = Path('/home/ubuntu/webdev-static-assets')
TARGET = (238, 238, 238, 255)


def normalize_frame(image: Image.Image, crop: tuple[int, int, int, int] | None) -> Image.Image:
    frame = image.convert('RGBA')
    if crop:
        frame = frame.crop(crop)
    frame = frame.resize((240, 240), Image.Resampling.LANCZOS)
    pixels = frame.load()
    width, height = frame.size
    visited: set[tuple[int, int]] = set()
    queue: deque[tuple[int, int]] = deque()
    seeds = []
    for x in range(width):
        seeds.extend(((x, 0), (x, height - 1)))
    for y in range(1, height - 1):
        seeds.extend(((0, y), (width - 1, y)))
    for point in seeds:
        if point not in visited:
            visited.add(point)
            queue.append(point)
    while queue:
        x, y = queue.popleft()
        r, g, b, a = pixels[x, y]
        # Background is a light contiguous field; dark outlines and the mascot
        # interrupt the flood fill, so the character remains intact.
        if min(r, g, b) < 145 or max(r, g, b) - min(r, g, b) > 68:
            continue
        pixels[x, y] = TARGET
        for nx, ny in ((x - 1, y), (x + 1, y), (x, y - 1), (x, y + 1)):
            if 0 <= nx < width and 0 <= ny < height and (nx, ny) not in visited:
                visited.add((nx, ny))
                queue.append((nx, ny))
    return frame.convert('P', palette=Image.Palette.ADAPTIVE, colors=256)


def normalize(source: Path, output: Path, crop: tuple[int, int, int, int] | None) -> None:
    with Image.open(source) as image:
        frames = [normalize_frame(frame.copy(), crop) for frame in ImageSequence.Iterator(image)]
        durations = [max(20, int(frame.info.get('duration', image.info.get('duration', 67)))) for frame in ImageSequence.Iterator(image)]
    frames[0].save(output, save_all=True, append_images=frames[1:], duration=durations, loop=0, optimize=False)


OUT.mkdir(parents=True, exist_ok=True)
normalize(SRC / 'lv_0_20260905143634.gif', OUT / '624-normalized.gif', (0, 80, 238, 347))
normalize(SRC / 'lv_0_20260905144042.gif', OUT / '042-normalized.gif', None)
print('normalized 624-normalized.gif and 042-normalized.gif to 240x240 with background #eeeeee')
