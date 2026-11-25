#!/usr/bin/env python3
"""Copies official AI brand marks for directory tools into assets/globe/logos and writes assets/globe/brands.json.
Usage: python3 assets3d/globe/make_brands.py <node_modules/@lobehub/icons-static-svg> <node_modules/simple-icons>
Sources: @lobehub/icons-static-svg (MIT) and Simple Icons (CC0-1.0). Only tools that really exist in the directory AND have an official mark are mapped; every other
tool gets a neutral app-style monogram tile (never an invented logo). Marks are used nominatively to identify the listed product; trademarks belong to their owners."""
import json, os, re, sys
lobe, si = sys.argv[1] + '/icons', sys.argv[2] + '/icons'
root = os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__))))
tools = json.load(open(root + '/data/tools.json')); tools = tools['tools'] if isinstance(tools, dict) else tools
ids = {t['id'] for t in tools}

# tool id -> (set, icon file stem, accent hex used for the tile glow)
INK = '#ffffff'   # monochrome marks are drawn in white on the dark glass tiles
want = {'chatgpt': ('lobe', 'openai', '10a37f'), 'claude': ('lobe', 'claude-color', 'd97757'), 'gemini': ('lobe', 'gemini-color', '3186ff'), 'perplexity': ('lobe', 'perplexity-color', '22b8cd'),
        'deepseek': ('lobe', 'deepseek-color', '4d6bfe'), 'copilot': ('lobe', 'copilot-color', '6a5cff'), 'microsoft-bing': ('lobe', 'bing-color', '0b8bd6'), 'deepl-translator': ('lobe', 'deepl-color', '1f6fd0'),
        'notebooklm': ('lobe', 'notebooklm', '5b6bff'), 'together-ai': ('lobe', 'together-color', '0f6fff'), 'codegeex': ('lobe', 'codegeex-color', '3b82f6'), 'monica': ('lobe', 'monica-color', '6d5cff'),
        'google-cloud-vision-ai': ('lobe', 'googlecloud-color', '4285f4'), 'grammarly': ('si', 'grammarly', '027e6f'), 'tensorflow': ('si', 'tensorflow', 'ff6f00'), 'zoom-ai-companion': ('si', 'zoom', '0b5cff'),
        'deepnote': ('si', 'deepnote', '3793ef'), 'ifttt': ('si', 'ifttt', '5b6bff')}
dst = root + '/assets/globe/logos'; os.makedirs(dst, exist_ok=True); out = {}
for tid, (src, stem, hexv) in want.items():
    f = f'{lobe if src == "lobe" else si}/{stem}.svg'
    if tid not in ids or not os.path.isfile(f): print('skip', tid, stem); continue
    svg = open(f).read()
    svg = re.sub(r'<title>.*?</title>', '', svg)
    svg = re.sub(r'\sstyle="[^"]*"', '', svg, count=1)
    svg = re.sub(r'height="1em"', 'height="192"', svg); svg = re.sub(r'width="1em"', 'width="192"', svg)
    if 'width="192"' not in svg: svg = svg.replace('<svg ', '<svg width="192" height="192" ', 1)
    if src == 'si': svg = svg.replace('<svg ', f'<svg fill="#{hexv}" ', 1)
    elif 'fill="currentColor"' in svg: svg = svg.replace('fill="currentColor"', f'fill="{INK}"')
    elif '-color' not in stem and 'fill=' not in svg.split('>')[0]: svg = svg.replace('<svg ', f'<svg fill="{INK}" ', 1)
    open(f'{dst}/{stem}.svg', 'w').write(svg); out[tid] = {'svg': f'/assets/globe/logos/{stem}.svg', 'hex': hexv}
json.dump(out, open(root + '/assets/globe/brands.json', 'w'), indent=1, sort_keys=True); print(len(out), 'official brand marks written')
