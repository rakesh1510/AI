#!/usr/bin/env bash
# Rebuilds assets/globe/globe.min.js from assets3d/globe/globe.src.js (tree-shaken Three.js r170). Run from anywhere; needs Node 18+ and network access once for npm.
set -euo pipefail
cd "$(dirname "$0")"
[ -d node_modules ] || npm install --no-audit --no-fund --silent
npx esbuild globe.src.js --bundle --minify --format=iife --target=es2019 --legal-comments=none --outfile=../../assets/globe/globe.min.js
ls -l ../../assets/globe/globe.min.js
