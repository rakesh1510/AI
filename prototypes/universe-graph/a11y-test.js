/* a11y-test.js - axe-core run over the prototype in light/dark x desktop/360px mobile, several UI states.
 * Usage: node a11y-test.js [--out=a11y-results.json]
 * axe-core is NOT vendored: it is loaded from node_modules (install it outside this folder, e.g. `npm i axe-core` in a scratch directory and set
 * AXE_CORE_PATH=/path/to/node_modules/axe-core/axe.min.js, or have it resolvable from NODE_PATH).
 * Also runs scripted keyboard / focus-management checks that axe cannot do.
 */
'use strict';
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const ROOT = __dirname;
const OUT = (process.argv.find(a => a.startsWith('--out=')) || '--out=a11y-results.json').slice(6);
const AXE_PATH = process.env.AXE_CORE_PATH || require.resolve('axe-core/axe.min.js');
const AXE_SRC = fs.readFileSync(AXE_PATH, 'utf8');
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.css': 'text/css', '.json': 'application/json' };

function serve() {
  return new Promise(res => {
    const srv = http.createServer((q, r) => {
      let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (p.endsWith(path.sep)) p += 'index.html';
      if (!p.startsWith(ROOT)) { r.writeHead(403); return r.end(); }
      fs.readFile(p, (e, d) => { if (e) { r.writeHead(404); return r.end(); } r.writeHead(200, { 'content-type': MIME[path.extname(p)] || 'text/plain' }); r.end(d); });
    }).listen(0, '127.0.0.1', () => res(srv));
  });
}
const PROFILES = {
  desktop: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 },
  mobile360: { viewport: { width: 360, height: 740 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true }
};
const STATES = [
  { name: 'graph-idle', run: async () => {} },
  { name: 'graph-selected-panel-open', run: async p => { await p.evaluate(() => { const A = GraphApp.adj(); GraphApp.select(A.findIndex(a => a.length > 6), {}); }); await p.waitForTimeout(300); } },
  { name: 'controls-legend-help-open', run: async p => { await p.evaluate(() => { for (const id of ['controls', 'legendBox', 'kbdBox']) document.getElementById(id).open = true; }); } },
  { name: 'search-results-open', run: async p => { await p.evaluate(() => { document.getElementById('controls').open = true; }); await p.fill('#q', 'a'); await p.waitForTimeout(300); } },
  { name: 'search-no-matches', run: async p => { await p.evaluate(() => { document.getElementById('controls').open = true; }); await p.fill('#q', 'zzzzqq'); await p.waitForTimeout(300); } },
  { name: 'table-page1', run: async p => { await p.evaluate(() => GraphApp.setView('table')); await p.waitForTimeout(200); } },
  { name: 'table-selected', run: async p => { await p.evaluate(() => { GraphApp.setView('table'); GraphApp.select(5, {}); }); await p.waitForTimeout(200); } },
  { name: 'table-no-matches', run: async p => { await p.evaluate(() => { document.getElementById('controls').open = true; GraphApp.setView('table'); }); await p.fill('#q', 'zzzzqq'); await p.waitForTimeout(400); } }
];

async function axeRun(page) {
  await page.evaluate(AXE_SRC);
  return page.evaluate(async () => {
    const r = await axe.run(document, { rules: { 'target-size': { enabled: true } }, resultTypes: ['violations', 'incomplete'] });
    const slim = list => list.map(v => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, targets: v.nodes.slice(0, 4).map(n => n.target.join(' ')), summary: (v.nodes[0] && v.nodes[0].failureSummary || '').slice(0, 300) }));
    return { version: axe.version, violations: slim(r.violations), incomplete: slim(r.incomplete), passes: r.passes.length };
  });
}

async function keyboardChecks(browser, base) {
  const C = {};
  const ctx = await browser.newContext(PROFILES.desktop); const page = await ctx.newPage();
  const errs = []; page.on('pageerror', e => errs.push(String(e.message)));
  await page.goto(`${base}/index.html?size=500&bench=1`); await page.waitForFunction(() => window.GraphApp && GraphApp.perf.settled, null, { timeout: 60000 });
  // skip links reachable as first tab stops
  await page.keyboard.press('Tab'); C.firstTabIsSkipLink = await page.evaluate(() => document.activeElement.classList.contains('skip'));
  await page.keyboard.press('Tab'); await page.keyboard.press('Enter');
  C.skipToGraphFocusesCanvas = await page.evaluate(() => document.activeElement.id === 'cv');
  C.skipLinkKeepsHash = await page.evaluate(() => !/skip|#stage|#controls/.test(location.hash));
  // graph keyboard: pan, zoom, select, related, back, escape
  await page.focus('#cv'); const k0 = await page.evaluate(() => GraphApp.getState());
  await page.keyboard.press('ArrowLeft'); await page.keyboard.press('+'); const k1 = await page.evaluate(() => GraphApp.getState());
  C.keyboardPanZoom = { xChanged: k0.x !== k1.x, zoomIncreased: k1.k > k0.k };
  await page.keyboard.press('0'); await page.evaluate(() => GraphApp.setTransformWorld(2, 0, 0));
  await page.keyboard.press('Enter'); const s1 = await page.evaluate(() => GraphApp.getState().sel);
  await page.keyboard.press(']'); const s2 = await page.evaluate(() => GraphApp.getState().sel);
  await page.keyboard.press('b'); const s3 = await page.evaluate(() => GraphApp.getState().sel);
  C.keyboardSelect = { enterSelected: s1 >= 0, relatedMoved: s2 >= 0 && s2 !== s1, backReturned: s3 === s1 };
  await page.keyboard.press('Enter'); C.enterOpensDetails = await page.evaluate(() => document.activeElement.id === 'pname');
  await page.focus('#cv'); await page.keyboard.press('Escape'); C.escapeClears = await page.evaluate(() => GraphApp.getState().sel === -1);
  // toggle keeps selection
  await page.evaluate(() => GraphApp.select(37, {})); await page.click('#vTable'); await page.waitForTimeout(150);
  C.tableKeepsSelection = await page.evaluate(() => ({ sel: GraphApp.getState().sel, markedRow: (document.querySelector('tbody tr.sel') || {}).dataset ? +document.querySelector('tbody tr.sel').dataset.i : null, ariaCurrent: !!document.querySelector('tbody .rowbtn[aria-current]') }));
  await page.click('#vGraph'); await page.waitForTimeout(150);
  C.graphKeepsSelection = await page.evaluate(() => GraphApp.getState().sel === 37);
  // paging focus management + live region
  await page.click('#vTable'); await page.waitForTimeout(100);
  await page.click('#next'); await page.waitForTimeout(100);
  C.afterNext = await page.evaluate(() => ({ focusId: document.activeElement.id, live: document.getElementById('live').textContent, info: document.getElementById('pageinfo').textContent }));
  const pages = await page.evaluate(() => Math.ceil(GraphApp.getState().tableCount / 50));
  for (let i = 1; i < pages - 1; i++) await page.click('#next');
  await page.waitForTimeout(100);
  C.atLastPage = await page.evaluate(() => ({ nextDisabled: document.getElementById('next').disabled, focusId: document.activeElement.id }));
  await page.focus('tbody .rowbtn'); await page.keyboard.press('PageUp'); await page.waitForTimeout(100);
  C.pageUpFocus = await page.evaluate(() => ({ focusIsRowBtn: document.activeElement.classList.contains('rowbtn'), page: GraphApp.getState().tablePage }));
  // filtering announces counts
  await page.evaluate(() => { document.getElementById('controls').open = true; }); await page.fill('#q', 'qua'); await page.waitForTimeout(900);
  C.searchAnnouncement = await page.evaluate(() => ({ live: document.getElementById('live').textContent, rows: document.querySelectorAll('tbody .rowbtn').length }));
  await page.fill('#q', 'zzzzqq'); await page.waitForTimeout(900);
  C.noMatchAnnouncement = await page.evaluate(() => ({ live: document.getElementById('live').textContent, emptyRow: !!document.querySelector('tbody tr.empty td[colspan]') }));
  C.tableAria = await page.evaluate(() => ({ explicitRoleOnTable: document.querySelector('#tbl').getAttribute('role'), anyRoleGrid: !!document.querySelector('[role=grid],[role=gridcell]'), scopeCols: document.querySelectorAll('thead th[scope=col]').length, relHeader: document.getElementById('thRel').textContent }));
  // reduced motion
  const ctx2 = await browser.newContext(Object.assign({ reducedMotion: 'reduce' }, PROFILES.desktop)); const p2 = await ctx2.newPage();
  await p2.goto(`${base}/index.html?size=500&bench=1`); await p2.waitForFunction(() => window.GraphApp && GraphApp.perf.settled, null, { timeout: 60000 });
  C.reducedMotion = await p2.evaluate(() => ({ zoomMs: GraphApp.zoomDuration(), cssTransitionNone: getComputedStyle(document.querySelector('button')).transitionDuration }));
  C.pageErrors = errs;
  await ctx.close(); await ctx2.close();
  return C;
}

(async () => {
  const srv = await serve(); const base = `http://127.0.0.1:${srv.address().port}`;
  const browser = await chromium.launch();
  const res = { generatedAt: new Date().toISOString(), chromium: browser.version(), axeCore: null, runs: [], keyboard: null };
  for (const scheme of ['light', 'dark']) for (const [pn, prof] of Object.entries(PROFILES)) for (const st of STATES) {
    const ctx = await browser.newContext(Object.assign({ colorScheme: scheme }, prof)); const page = await ctx.newPage();
    await page.goto(`${base}/index.html?size=500&bench=1`); await page.waitForFunction(() => window.GraphApp && GraphApp.perf.settled, null, { timeout: 60000 });
    await page.waitForTimeout(300);
    await st.run(page);
    const r = await axeRun(page); res.axeCore = r.version;
    res.runs.push({ scheme, profile: pn, state: st.name, violations: r.violations, incomplete: r.incomplete, passes: r.passes });
    process.stderr.write(`${scheme} ${pn} ${st.name}: ${r.violations.length} violation rules, ${r.incomplete.length} incomplete\n`);
    await ctx.close();
  }
  res.keyboard = await keyboardChecks(browser, base);
  await browser.close(); srv.close();
  res.totalViolationNodes = res.runs.reduce((a, r) => a + r.violations.reduce((b, v) => b + v.nodes, 0), 0);
  fs.writeFileSync(path.join(ROOT, OUT), JSON.stringify(res, null, 2));
  console.log(JSON.stringify({ axe: res.axeCore, runs: res.runs.length, totalViolationNodes: res.totalViolationNodes, violations: res.runs.filter(r => r.violations.length).map(r => [r.scheme, r.profile, r.state, r.violations.map(v => v.id + ' x' + v.nodes)]), keyboard: res.keyboard }, null, 1));
})().catch(e => { console.error(e); process.exit(1); });
