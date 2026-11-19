/* label-metrics.js - measures edge-label overlap around hub nodes, legacy midpoint placement vs collision-avoiding placement.
 * Usage: node label-metrics.js [--out=bench-results-label-overlap.json]
 * For each profile and data size: select each of the 10 highest-degree nodes (and 10 mid-degree nodes), centre it at zoom 2.5, force one
 * full-quality frame and read GraphApp.labelMetrics() (edge-label rectangles of that frame; pairwise rectangle intersection; label vs. node-glyph intersection).
 */
'use strict';
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const ROOT = __dirname;
const OUT = (process.argv.find(a => a.startsWith('--out=')) || '--out=bench-results-label-overlap.json').slice(6);
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css' };
function serve() {
  return new Promise(res => {
    const srv = http.createServer((q, r) => {
      let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (p.endsWith(path.sep)) p += 'index.html';
      fs.readFile(p, (e, d) => { if (e) { r.writeHead(404); return r.end(); } r.writeHead(200, { 'content-type': MIME[path.extname(p)] || 'text/plain' }); r.end(d); });
    }).listen(0, '127.0.0.1', () => res(srv));
  });
}
const PROFILES = { desktop: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }, mobile: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true } };
const sum = (a, k) => a.reduce((x, m) => x + m[k], 0);
(async () => {
  const srv = await serve(), base = `http://127.0.0.1:${srv.address().port}`, browser = await chromium.launch();
  const out = { generatedAt: new Date().toISOString(), chromium: browser.version(), note: 'SYNTHETIC data. Zoom 2.5, one hub selected per sample, one full-quality frame each.', results: [] };
  for (const prof of Object.keys(PROFILES)) for (const size of [500, 5000]) for (const labels of ['legacy', 'placed']) {
    const ctx = await browser.newContext(PROFILES[prof]), page = await ctx.newPage();
    await page.goto(`${base}/index.html?size=${size}&bench=1&labels=${labels}`);
    await page.waitForFunction(() => window.GraphApp && GraphApp.perf.settled, null, { timeout: 120000 });
    const picks = await page.evaluate(() => { const A = GraphApp.adj(), ids = A.map((a, i) => [a.length, i]).sort((x, y) => y[0] - x[0] || x[1] - y[1]); const hubs = ids.slice(0, 10).map(x => x[1]); const mid = ids.filter(x => x[0] >= 5 && x[0] <= 15).filter((x, i) => i % 7 === 0).slice(0, 10).map(x => x[1]); return { hubs, mid }; });
    const sample = async ids => {
      const rows = [];
      for (const id of ids) {
        const r = await page.evaluate(async id => { const n = GraphApp.nodes()[id]; GraphApp.select(id, {}); GraphApp.setTransformWorld(2.5, n.x, n.y); const c = await GraphApp.settledFrameCost(); const m = GraphApp.labelMetrics(); m.degree = GraphApp.adj()[id].length; m.frameMs = c.ms; return m; }, id);
        rows.push(r);
      }
      return rows;
    };
    for (const [grp, ids] of [['hubs(top10 degree)', picks.hubs], ['mid-degree(5-15)', picks.mid]]) {
      const rows = await sample(ids);
      const o = { profile: prof, size, labels, group: grp, samples: rows.length, meanDegree: Math.round(sum(rows, 'degree') / rows.length * 10) / 10,
        labelsDrawnTotal: sum(rows, 'labelsDrawn'), candidatesTotal: sum(rows, 'candidates'), hiddenTotal: sum(rows, 'hidden'),
        overlappingPairsTotal: sum(rows, 'overlappingPairs'), labelsInAnyOverlapTotal: sum(rows, 'labelsInAnyOverlap'),
        pctLabelsInAnyOverlap: Math.round(1000 * sum(rows, 'labelsInAnyOverlap') / Math.max(1, sum(rows, 'labelsDrawn'))) / 10,
        overlapAreaPxTotal: sum(rows, 'overlapAreaPx'), labelNodeOverlapsTotal: sum(rows, 'labelNodeOverlaps'), labelsCoveringANodeTotal: sum(rows, 'labelsCoveringANode'),
        meanFrameMs: Math.round(sum(rows, 'frameMs') / rows.length * 10) / 10, perSample: rows };
      out.results.push(o);
      process.stderr.write(`${prof} ${size} ${labels} ${grp}: drawn ${o.labelsDrawnTotal}, pairs ${o.overlappingPairsTotal}, inOverlap ${o.pctLabelsInAnyOverlap}%, labelNode ${o.labelNodeOverlapsTotal}, hidden ${o.hiddenTotal}, frame ${o.meanFrameMs}ms\n`);
    }
    await ctx.close();
  }
  await browser.close(); srv.close();
  fs.writeFileSync(path.join(ROOT, OUT), JSON.stringify(out, null, 2));
})().catch(e => { console.error(e); process.exit(1); });
