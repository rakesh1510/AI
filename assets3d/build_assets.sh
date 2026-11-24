#!/usr/bin/env bash
# Renders every studio scene with Blender (bpy, Cycles) and converts the stills to WebP with alpha into assets/art/.
# Usage: ./assets3d/build_assets.sh [scene ...]   (default: all).  Needs: python3 -m venv /tmp/bpyenv && /tmp/bpyenv/bin/pip install bpy pillow
set -euo pipefail
cd "$(dirname "$0")"
PY=${BPY:-/tmp/bpyenv/bin/python}
OUT=../assets/art; mkdir -p "$OUT" /tmp/art_png
SCENES=("$@"); [ ${#SCENES[@]} -eq 0 ] && SCENES=(hero_knot agent podium chat shield network layers lens steps compass mail lost doc ads versus cubes book workflow)
for sc in "${SCENES[@]}"; do
  case "$sc" in hero_knot|agent) S=1.0; N=128;; *) S=0.8; N=112;; esac
  $PY render.py "$sc" "/tmp/art_png/$sc.png" --samples $N --scale $S 2>&1 | grep -E "Traceback|Error:|rendered" || true
done
if [ ${#SCENES[@]} -ge 18 ] || [[ " ${SCENES[*]} " == *" thumbs "* ]]; then
  for t in 0 1 2 3; do THUMB=$t $PY render.py thumbs "/tmp/art_png/thumb$t.png" --samples 96 --scale 0.8 2>&1 | grep -E "Traceback|Error:|rendered" || true; done
fi
$PY - <<'PYEOF'
import glob, os
from PIL import Image
for f in sorted(glob.glob('/tmp/art_png/*.png')):
    im = Image.open(f).convert('RGBA')
    bbox = im.getchannel('A').point(lambda v: 255 if v > 6 else 0).getbbox()
    if bbox:
        pad = 12; l, t, r, b = bbox; im = im.crop((max(0, l - pad), max(0, t - pad), min(im.width, r + pad), min(im.height, b + pad)))
    name = os.path.splitext(os.path.basename(f))[0]
    im.save(f'../assets/art/{name}.webp', 'WEBP', quality=84, method=6, alpha_quality=90)
    print(name, im.size, os.path.getsize(f'../assets/art/{name}.webp') // 1024, 'KB')
PYEOF
