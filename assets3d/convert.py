#!/usr/bin/env python3
"""Converts Cycles stills (PNG, RGBA) into optimised WebP with alpha: a full-size file and a half-size file for phones.
Usage: convert.py [name ...]   (default: every PNG in /tmp/art_png).  Source PNGs are never committed; only the WebP output in assets/art/."""
import glob, os, sys
from PIL import Image
SRC = '/tmp/art_png'; OUT = os.path.join(os.path.dirname(__file__), '..', 'assets', 'art'); os.makedirs(OUT, exist_ok=True)
names = sys.argv[1:] or [os.path.splitext(os.path.basename(f))[0] for f in sorted(glob.glob(SRC + '/*.png'))]
for name in names:
    im = Image.open(f'{SRC}/{name}.png').convert('RGBA')
    bbox = im.getchannel('A').point(lambda v: 255 if v > 6 else 0).getbbox()
    if bbox:
        l, t, r, b = bbox; pad = 14
        im = im.crop((max(0, l - pad), max(0, t - pad), min(im.width, r + pad), min(im.height, b + pad)))
    big = im if im.width <= 1100 else im.resize((1100, round(im.height * 1100 / im.width)), Image.LANCZOS)
    small = big.resize((560, round(big.height * 560 / big.width)), Image.LANCZOS)
    big.save(f'{OUT}/{name}.webp', 'WEBP', quality=82, method=6, alpha_quality=88)
    small.save(f'{OUT}/{name}-sm.webp', 'WEBP', quality=80, method=6, alpha_quality=85)
    print(name, big.size, os.path.getsize(f'{OUT}/{name}.webp') // 1024, 'KB +', os.path.getsize(f'{OUT}/{name}-sm.webp') // 1024, 'KB')
