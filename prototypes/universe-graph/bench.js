/* bench.js - headless Chromium benchmark + conformance checks. Usage: node bench.js [--quick]
 * Serves this folder on localhost only, drives index.html with Playwright, writes bench-results.json
 * and refreshes the table between the BENCH markers in README.md. Everything reported is measured.
 */
'use strict';
const { chromium } = require('playwright');
const http = require('http'), fs = require('fs'), path = require('path');
const DataGen = require('./data-gen.js');

const ROOT = __dirname;
const QUICK = process.argv.includes('--quick');
const SETTLE_CAP = QUICK ? 20000 : 90000;
const THRESH = { desktop: 33, mobile: 50, hit: 16 };
const MIME = { '.html': 'text/html', '.js': 'text/javascript', '.json': 'application/json', '.md': 'text/plain' };

function serve() {
  return new Promise(res => {
    const srv = http.createServer((q, r) => {
      let p = path.join(ROOT, decodeURIComponent(q.url.split('?')[0])); if (p.endsWith(path.sep)) p += 'index.html';
      if (!p.startsWith(ROOT)) { r.writeHead(403); return r.end(); }
      fs.readFile(p, (e, d) => { if (e) { r.writeHead(404); return r.end(); } r.writeHead(200, { 'content-type': MIME[path.extname(p)] || 'text/plain' }); r.end(d); });
    }).listen(0, '127.0.0.1', () => res(srv));
  });
}
const mean = a => a.reduce((x, y) => x + y, 0) / (a.length || 1);
const pct = (a, p) => { if (!a.length) return NaN; const s = a.slice().sort((x, y) => x - y); return s[Math.min(s.length - 1, Math.ceil(p / 100 * s.length) - 1)]; };
const r2 = x => (typeof x === 'number' && isFinite(x)) ? Math.round(x * 100) / 100 : x;

const PROFILES = {
  desktop: { ctx: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 }, throttle: 1 },
  mobile: { ctx: { viewport: { width: 390, height: 844 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true }, throttle: 4 }
};
const RUNS = [
  { size: 500, variant: 'default', q: '' },
  { size: 5000, variant: 'default', q: '' },
  { size: 50000, variant: 'default', q: '' },
  { size: 50000, variant: 'no-clustering', q: '&cluster=off' },
  { size: 50000, variant: 'progressive', q: '&load=progressive' }
];

async function newPage(browser, profile, extra) {
  const ctx = await browser.newContext(Object.assign({}, PROFILES[profile].ctx, extra || {}));
  const page = await ctx.newPage();
  const errors = []; page.on('pageerror', e => errors.push(String(e.message))); page.on('console', m => { if (m.type() === 'error') errors.push(m.text()); });
  const cdp = await ctx.newCDPSession(page);
  if (PROFILES[profile].throttle > 1) await cdp.send('Emulation.setCPUThrottlingRate', { rate: PROFILES[profile].throttle });
  return { ctx, page, cdp, errors };
}

async function calibrate(page) {
  return page.evaluate(() => new Promise(res => {
    const src = 'onmessage=()=>{const t=performance.now();let s=0;for(let i=0;i<3e7;i++)s+=i%7;postMessage(performance.now()-t)}';
    const w = new Worker(URL.createObjectURL(new Blob([src])));
    const t = performance.now(); let s = 0; for (let i = 0; i < 3e7; i++) s += i % 7; const main = performance.now() - t;
    w.onmessage = e => { w.terminate(); res({ mainMs: main, workerMs: e.data }); }; w.postMessage(1);
  }));
}

async function runOne(browser, base, profile, spec) {
  const { ctx, page, cdp, errors } = await newPage(browser, profile);
  const out = { profile, size: spec.size, variant: spec.variant, viewport: PROFILES[profile].ctx.viewport, cpuThrottle: PROFILES[profile].throttle };
  const url = `${base}/index.html?size=${spec.size}&bench=1&settleCap=${SETTLE_CAP}${spec.q}`;
  const tWall = Date.now();
  await page.goto(url, { waitUntil: 'load' });
  await page.waitForFunction(() => window.GraphApp && GraphApp.perf.firstPaint > 0, null, { timeout: 120000, polling: 100 });
  await page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: SETTLE_CAP + 120000, polling: 250 });
  const st = await page.evaluate(() => ({ perf: Object.assign({}, GraphApp.perf), state: GraphApp.getState(), paint: performance.getEntriesByType('paint').map(p => [p.name, p.startTime]), mem: performance.memory && { used: performance.memory.usedJSHeapSize, total: performance.memory.totalJSHeapSize } }));
  Object.assign(out, {
    nodes: st.state.N, edges: st.state.E, loadedNodesAtSettle: st.state.loaded,
    dataGenMs: r2(st.perf.dataGenMs), firstPaintMs: r2(st.perf.firstPaint), fcpMs: (st.paint.find(p => p[0] === 'first-contentful-paint') || [0, null])[1],
    layoutSettleMs: r2(st.perf.settleMs), layoutTicks: st.perf.layoutTicks, layoutCappedAtLimit: st.perf.layoutCapped, quadtreeBuildMs: r2(st.perf.qtBuildMs),
    heapAfterSettleMB: st.mem ? r2(st.mem.used / 1048576) : null
  });
  if (spec.variant === 'progressive') {
    const before = await page.evaluate(() => GraphApp.getState().loaded);
    const exp = await page.evaluate(() => { const a = []; for (let i = 0; i < 10; i++) { const t = performance.now(); GraphApp.select(i, {}); a.push([performance.now() - t, GraphApp.perf.expandAdded]); } return a; });
    await page.waitForFunction(() => GraphApp.getState().loaded > 0, null, { timeout: 60000 });
    await page.waitForTimeout(SETTLE_CAP > 30000 ? 6000 : 3000);
    const after = await page.evaluate(() => GraphApp.getState());
    out.progressive = { loadedBefore: before, loadedAfter10Expansions: after.loaded, edgesLoaded: after.edgesLoaded, expandCallMs: exp.map(e => r2(e[0])), nodesAddedPerExpansion: exp.map(e => e[1]) };
    await page.evaluate(() => GraphApp.select(-1, {}));
  }
  // scripted zoom/pan
  const scr = await page.evaluate(() => GraphApp.runScript());
  const fr = scr.frames.slice(5);
  const dts = fr.map(f => f.dt), dr = fr.map(f => f.draw);
  const byPhase = {};
  for (const f of fr) (byPhase[f.phase] = byPhase[f.phase] || []).push(f.dt);
  const byMode = {};
  for (const f of fr) { const m = /clustered/.test(f.mode) ? 'clustered' : /nodes only/.test(f.mode) ? 'nodes-only' : 'full-detail'; (byMode[m] = byMode[m] || []).push(f.dt); }
  out.frame = { frames: dts.length, avgMs: r2(mean(dts)), p95Ms: r2(pct(dts, 95)), maxMs: r2(Math.max(...dts)), drawAvgMs: r2(mean(dr)), drawP95Ms: r2(pct(dr, 95)),
    byPhaseP95: Object.fromEntries(Object.entries(byPhase).map(([k, v]) => [k, r2(pct(v, 95))])),
    byRenderMode: Object.fromEntries(Object.entries(byMode).map(([k, v]) => [k, { frames: v.length, avgMs: r2(mean(v)), p95Ms: r2(pct(v, 95)) }])) };
  await page.waitForTimeout(400);
  out.settledRedrawAtFit = await page.evaluate(() => GraphApp.settledFrameCost());
  // hit-test latency (quadtree, k=2 detail view)
  const hit = await page.evaluate(() => GraphApp.benchHit(400));
  out.hitTest = { calls: hit.count, hitsFound: hit.hits, perCallMeanMs: r2(mean(hit.samples)), perCallP95Ms: r2(pct(hit.samples, 95)), perCallMaxMs: r2(Math.max(...hit.samples)), batchMeanMs: Math.round(hit.batchMeanMs * 1e5) / 1e5, timerResolutionNote: 'performance.now is quantised (~0.1 ms); batchMeanMs is the finer figure' };
  await page.waitForTimeout(300);
  out.settledRedrawAtDetail = await page.evaluate(() => GraphApp.settledFrameCost());
  // click -> selection -> next draw
  const clicks = [];
  for (let i = 0; i < 5; i++) {
    const t = await page.evaluate(() => { GraphApp.select(-1, {}); return GraphApp.nearestToCentre(); });
    if (!t) break;
    await page.mouse.click(t.pos.x, t.pos.y); await page.waitForTimeout(250);
    const r = await page.evaluate(() => ({ ms: GraphApp.perf.lastClickMs, sel: GraphApp.getState().sel }));
    clicks.push({ ms: r.ms, selected: r.sel === t.id });
  }
  out.clickToPaint = { samples: clicks.length, meanMs: r2(mean(clicks.map(c => c.ms))), maxMs: r2(Math.max(...clicks.map(c => c.ms))), allSelected: clicks.every(c => c.selected) };
  const mem2 = await page.evaluate(() => performance.memory && { used: performance.memory.usedJSHeapSize });
  out.heapAfterInteractionMB = mem2 ? r2(mem2.used / 1048576) : null;
  out.heapNote = 'main-thread JS heap only (performance.memory); the layout Web Worker heap is not included';
  const au = await page.evaluate(() => GraphApp.audit());
  out.unverifiedDrawnOnCanvas = au.unverifiedDrawnOnCanvas;
  out.pageErrors = errors;
  out.wallSeconds = r2((Date.now() - tWall) / 1000);
  const thr = THRESH[profile];
  out.verdict = { frameP95Threshold: thr, framePass: out.frame.p95Ms <= thr, hitThreshold: THRESH.hit, hitPass: out.hitTest.perCallP95Ms <= THRESH.hit, layoutSettledWithinCap: !out.layoutCappedAtLimit };
  out.verdict.pass = out.verdict.framePass && out.verdict.hitPass;
  await ctx.close();
  return out;
}

async function checks(browser, base) {
  const C = {};
  const { ctx, page, errors } = await newPage(browser, 'desktop', { viewport: { width: 360, height: 740 }, isMobile: true, hasTouch: true });
  await page.goto(`${base}/index.html?size=500&bench=1`); await page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  await page.evaluate(() => { document.querySelector('#controls').open = true; document.querySelector('#legendBox').open = true; });
  C.width360 = { graphScrollWidth: await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth)), viewport: 360 };
  C.syntheticLabelVisibleGraph = await page.evaluate(() => { const e = document.querySelector('.syn'); const r = e.getBoundingClientRect(); return r.width > 0 && /SYNTHETIC TEST DATA/.test(e.textContent); });
  C.legendRelTypesListed = await page.evaluate(() => ['technical_ancestry', 'product_succession', 'company_ownership', 'integration', 'similarity', 'historical_association'].filter(r => document.querySelector('#legend').textContent.includes(r)));
  // select a node so the panel is open, then audit target sizes in both views
  const nid = await page.evaluate(() => { const A = GraphApp.adj(); const i = A.findIndex(a => a.length > 3); GraphApp.select(i, {}); return i; });
  const targets = async () => page.evaluate(() => {
    const sel = 'button, select, summary, input[type=search], label.chk, [role=option], tbody tr[data-i], .linkbtn';
    return Array.from(document.querySelectorAll(sel)).filter(e => { const r = e.getBoundingClientRect(); const cs = getComputedStyle(e); return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden'; })
      .map(e => { const r = e.getBoundingClientRect(); return { el: e.tagName.toLowerCase() + (e.id ? '#' + e.id : '') + (e.className && typeof e.className === 'string' ? '.' + e.className.split(' ')[0] : ''), w: Math.round(r.width), h: Math.round(r.height) }; });
  });
  let t1 = await targets();
  await page.evaluate(() => GraphApp.setView('table')); await page.waitForTimeout(200);
  const t2 = await targets();
  const all = t1.concat(t2);
  C.touchTargets = { checked: all.length, under44: all.filter(t => t.w < 44 || t.h < 44) };
  C.width360.tableScrollWidth = await page.evaluate(() => Math.max(document.documentElement.scrollWidth, document.body.scrollWidth));
  C.syntheticLabelVisibleTable = await page.evaluate(() => { const e = document.querySelector('.syn'); return e.getBoundingClientRect().width > 0; });
  // keyboard in table
  await page.evaluate(() => { GraphApp.select(-1, {}); });
  await page.focus('tbody tr[tabindex="0"]');
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
  const focusedI = await page.evaluate(() => +document.activeElement.getAttribute('data-i'));
  await page.keyboard.press('Enter');
  const kb = await page.evaluate(() => ({ sel: GraphApp.getState().sel, panelVisible: !document.querySelector('#panel').hidden, focusId: document.activeElement.id }));
  C.tableKeyboard = { arrowDownTwiceFocusedRowIndex: focusedI, enterSelected: kb.sel, selectionMatchesFocusedRow: kb.sel === focusedI, panelOpened: kb.panelVisible, focusMovedToPanelHeading: kb.focusId === 'pname' };
  await page.keyboard.press('Tab'); // focus close button next
  C.tableKeyboard.tabReachesCloseButton = await page.evaluate(() => document.activeElement.id === 'pclose');
  C.tableAria = await page.evaluate(() => ({ role: document.querySelector('#tbl').getAttribute('role'), label: !!document.querySelector('#tbl').getAttribute('aria-label'), rowsWithLabel: document.querySelectorAll('tbody tr[aria-label]').length, canvasLabel: !!document.querySelector('#cv').getAttribute('aria-label') }));
  // edge UI rules
  C.edgePanel = await page.evaluate(() => {
    const li = Array.from(document.querySelectorAll('#edges li')).filter(l => l.querySelector('.rel'));
    return { edgesListed: li.length, withCitation: li.filter(l => /SYN-CITE-\d{5}/.test(l.textContent)).length, withDateOrNotRecorded: li.filter(l => /Verified: \d{4}-\d\d-\d\d|not recorded/.test(l.textContent)).length, withUnverifiedText: li.filter(l => /unverified/i.test(l.textContent)).length };
  });
  C.audit = await page.evaluate(() => GraphApp.audit());
  // search
  await page.evaluate(() => GraphApp.setView('graph'));
  await page.fill('#q', 'a'); await page.waitForTimeout(250);
  const sr = await page.evaluate(() => ({ n: document.querySelectorAll('#results li[data-i]').length }));
  await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter'); await page.waitForTimeout(300);
  C.search = { resultsShown: sr.n, enterSelectedNode: (await page.evaluate(() => GraphApp.getState().sel)) >= 0 };
  // filter
  const before = await page.evaluate(() => GraphApp.getState().visible);
  await page.evaluate(() => { const c = document.querySelector('input[data-t=model]'); c.click(); });
  const after = await page.evaluate(() => GraphApp.getState().visible);
  C.filter = { visibleBefore: before, visibleAfterUncheckingModel: after };
  // deep link round trip
  await page.evaluate(() => { GraphApp.select(7, {}); GraphApp.setTransformWorld(1.75, 120, -80); });
  const hash = await page.evaluate(() => GraphApp.hash());
  const p2 = await ctx.newPage(); await p2.goto(`${base}/index.html?size=500${hash}`); await p2.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  const s2 = await p2.evaluate(() => GraphApp.getState());
  C.deepLink = { hash, restoredSel: s2.sel, restoredK: r2(s2.k), expectedK: 1.75, panelOpen: await p2.evaluate(() => !document.querySelector('#panel').hidden) };
  C.pageErrors = errors.slice();
  await ctx.close();
  // reduced motion
  const rm = await newPage(browser, 'desktop', { reducedMotion: 'reduce' });
  await rm.page.goto(`${base}/index.html?size=500&bench=1`); await rm.page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  C.reducedMotion = await rm.page.evaluate(() => ({ matches: matchMedia('(prefers-reduced-motion: reduce)').matches, zoomTransitionMs: GraphApp.zoomDuration() }));
  const rm2 = await newPage(browser, 'desktop');
  await rm2.page.goto(`${base}/index.html?size=500&bench=1`); await rm2.page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  C.reducedMotion.zoomTransitionMsWhenNoPreference = await rm2.page.evaluate(() => GraphApp.zoomDuration());
  await rm.ctx.close(); await rm2.ctx.close();
  // review mode contrast
  const rv = await newPage(browser, 'desktop');
  await rv.page.addInitScript(() => { window.UNIVERSE_REVIEW_ENABLED = true; });   // review mode needs this flag; the URL alone no longer enables it
  await rv.page.goto(`${base}/index.html?size=500&bench=1&mode=review`); await rv.page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  { const pub = await browser.newPage(); await pub.goto(`${base}/index.html?size=500&bench=1&mode=review`); await pub.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 }); C.urlAloneCannotEnableReviewMode = await pub.evaluate(() => ({ unverifiedEdgesLoaded: GraphApp.edges().filter(x => !x.v).length, bannerHidden: document.querySelector('#reviewBanner').hidden })); await pub.close(); }
  C.reviewModeOnlyViaUrl = await rv.page.evaluate(() => { const e = GraphApp.edges().find(x => !x.v); GraphApp.select(e.s, {}); return { unverifiedEdgesLoaded: GraphApp.edges().filter(x => !x.v).length, badgeInPanel: /UNVERIFIED/.test(document.querySelector('#edges').textContent), bannerVisible: !document.querySelector('#reviewBanner').hidden }; });
  await rv.ctx.close();
  // progressive 1-hop
  const pg = await newPage(browser, 'desktop');
  await pg.page.goto(`${base}/index.html?size=5000&bench=1&load=progressive`); await pg.page.waitForFunction(() => GraphApp.perf.settled, null, { timeout: 60000 });
  C.progressive = await pg.page.evaluate(() => { const b = GraphApp.getState().loaded; const A = GraphApp.adj(); const id = 200 + 30; GraphApp.select(id, {}); const a = GraphApp.getState().loaded; return { loadedBefore: b, selectedId: id, degree: A[id].length, loadedAfterOneSelect: a, addedNodes: a - b, expandMs: GraphApp.perf.expandMs }; });
  await pg.ctx.close();
  // determinism
  C.determinism = Object.fromEntries(DataGen.SIZES.map(s => { const a = DataGen.checksum(DataGen.generate(s, 42)), b = DataGen.checksum(DataGen.generate(s, 42)); return [s, { checksum: a, identicalOnSecondRun: a === b }]; }));
  return C;
}

function table(results) {
  let md = '| Profile | Nodes | Variant | First paint (ms) | Layout settle (ms) | Frame avg / p95 (ms) | Hit-test mean / p95 (ms) | Idle full-quality redraw fit / k=2 (ms) | Click to paint (ms) | JS heap (MB) | Frame thr. | Verdict |\n|---|---|---|---|---|---|---|---|---|---|---|---|\n';
  for (const r of results) {
    const settle = r.layoutSettleMs + (r.layoutCappedAtLimit ? ' (stopped at cap, ' + r.layoutTicks + ' ticks)' : '');
    md += `| ${r.profile} | ${r.nodes.toLocaleString('en-US')} | ${r.variant} | ${r.firstPaintMs} | ${settle} | ${r.frame.avgMs} / ${r.frame.p95Ms} | ${r.hitTest.batchMeanMs} / ${r.hitTest.perCallP95Ms} | ${r2(r.settledRedrawAtFit.ms)} / ${r2(r.settledRedrawAtDetail.ms)} | ${r.clickToPaint.meanMs} | ${r.heapAfterInteractionMB} | ${r.verdict.frameP95Threshold} | ${r.verdict.pass ? 'PASS' : 'FAIL'} |\n`;
  }
  return md;
}

(async () => {
  const srv = await serve(); const base = `http://127.0.0.1:${srv.address().port}`;
  const browser = await chromium.launch({ args: ['--enable-precise-memory-info'] });
  const res = { generatedAt: new Date().toISOString(), chromium: browser.version(), quick: QUICK, thresholds: THRESH, settleCapMs: SETTLE_CAP, note: 'All data SYNTHETIC. Frame time = requestAnimationFrame interval during a scripted 390-frame zoom/pan; first 5 frames dropped.', calibration: {}, runs: [] };
  if (process.argv.includes('--checks-only')) { const C = await checks(browser, base); console.log(JSON.stringify(C, null, 1)); await browser.close(); srv.close(); return; }
  for (const prof of ['desktop', 'mobile']) {
    const { ctx, page } = await newPage(browser, prof); await page.goto(base + '/index.html?size=500'); res.calibration[prof] = await calibrate(page); await ctx.close();
  }
  for (const prof of ['desktop', 'mobile']) for (const spec of RUNS) {
    process.stderr.write(`run ${prof} ${spec.size} ${spec.variant}\n`);
    try { const r = await runOne(browser, base, prof, spec); res.runs.push(r); process.stderr.write(`  first paint ${r.firstPaintMs}ms settle ${r.layoutSettleMs}ms p95 ${r.frame.p95Ms}ms hit ${r.hitTest.perCallP95Ms}ms\n`); }
    catch (e) { res.runs.push({ profile: prof, size: spec.size, variant: spec.variant, error: String(e.message) }); process.stderr.write('  ERROR ' + e.message + '\n'); }
  }
  process.stderr.write('checks\n');
  res.checks = await checks(browser, base);
  await browser.close(); srv.close();
  fs.writeFileSync(path.join(ROOT, 'bench-results.json'), JSON.stringify(res, null, 2));
  const md = table(res.runs.filter(r => !r.error));
  const rp = path.join(ROOT, 'README.md');
  if (fs.existsSync(rp)) {
    let t = fs.readFileSync(rp, 'utf8');
    t = t.replace(/<!-- BENCH:START -->[\s\S]*<!-- BENCH:END -->/, '<!-- BENCH:START -->\n' + md + '\n<!-- BENCH:END -->');
    fs.writeFileSync(rp, t);
  }
  console.log(md);
})().catch(e => { console.error(e); process.exit(1); });
