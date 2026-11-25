# Global AI Ecosystem globe (homepage artwork)

Real-time Three.js / WebGL scene. Source: `globe.src.js` -> built bundle `assets/globe/globe.min.js` (Three.js r170, tree-shaken, about 133 KB gzip).

Rebuild (Node 18+, needs npm once): `assets3d/globe/build.sh`. `node_modules/` is git-ignored; the site itself needs no Node.

Brand marks: `python3 assets3d/globe/make_brands.py <node_modules/@lobehub/icons-static-svg> <node_modules/simple-icons>` writes `assets/globe/logos/*.svg` and `assets/globe/brands.json` (only tools that exist in the directory AND have an official mark; everything else gets a monogram tile, never an invented logo).

Sources and licences: Earth imagery NASA Blue Marble / Black Marble (public domain, via the three-globe example images); cloud texture from the three.js examples (MIT); brand marks from `@lobehub/icons-static-svg` (MIT) and Simple Icons (CC0). Brand marks are trademarks of their owners and are used only to identify the listed product.

Poster (`earth-poster*.webp`, first paint, no-WebGL / reduced-motion / Save-Data fallback): open the homepage with `?globefreeze=1` in a WebGL browser and snapshot the canvas (`box.snapshot()`); regenerate whenever the scene changes.
