"""Render one studio scene: /tmp/bpyenv/bin/python assets3d/render.py <scene> <out.png> [--samples N] [--scale S]"""
import sys, os, importlib
sys.path.insert(0, os.path.dirname(__file__))
import lib
args = sys.argv[sys.argv.index('--') + 1:] if '--' in sys.argv else sys.argv[1:]
name, out = args[0], args[1]
samples = int(args[args.index('--samples') + 1]) if '--samples' in args else None
scale = float(args[args.index('--scale') + 1]) if '--scale' in args else None
mod = importlib.import_module('scenes.' + name)
mod.build(samples, scale)
lib.render(out)
print('rendered', out)
