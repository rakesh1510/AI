/* http-source-test.js - browser test of the PRODUCTION graph page (universe-graph.php) with the real HTTP data source, against the PHP dev server.
 * Usage: node http-source-test.js [--out=http-source-results.json]
 * Needs: MySQL/MariaDB reachable with AIHUB_COMPAT_ROOT_USER / AIHUB_COMPAT_ROOT_PASS / AIHUB_DB_HOST (like the other Phase 7 tests); playwright (preinstalled); axe-core
 * installed OUTSIDE the repo, e.g. `npm i axe-core` in a scratch directory with AXE_CORE_PATH=/path/to/node_modules/axe-core/axe.min.js (same convention as a11y-test.js).
 * The script starts tests/phase7_graph_fixture.php (scratch database p7gj_<pid>, SYNTHETIC ledger seeded through the service functions, php -S on a port 8300-8320),
 * runs every check below and closes the fixture (which drops the database). Exit code 1 when a check fails or axe reports a violation.
 * Verifies: only verified nodes load, neighbourhoods expand (paged, "load more"), relationship types stay separate with citation + date, review mode cannot be enabled
 * through the URL or a global, hostile text never becomes markup, error / empty / malformed-response states, request aborts, deep links and shareable URLs,
 * keyboard operation, the page without JavaScript, and axe-core (light/dark x desktop 1280 / mobile 360 x several states).
 */
'use strict';
const { chromium } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'), path = require('path');
const ROOT = path.resolve(__dirname, '..', '..');
const OUT = (process.argv.find(a => a.startsWith('--out=')) || '--out=http-source-results.json').slice(6);
const AXE_SRC = fs.readFileSync(process.env.AXE_CORE_PATH || require.resolve('axe-core/axe.min.js'), 'utf8');

const T0 = Date.now();
const results = { checks: [], axe: [], notes: [] };
let failed = 0;
function check(name, ok, detail) {
  lastProgress = Date.now();
  results.checks.push({ name, ok: !!ok, detail: detail === undefined ? undefined : String(detail).slice(0, 300) });
  if (process.env.VERBOSE && ok) console.error('  ok: ' + name + ' [' + Math.round((Date.now() - T0) / 1000) + 's]');
  if (!ok) { failed++; console.error('  FAIL: ' + name + (detail !== undefined ? ' -> ' + String(detail).slice(0, 300) : '')); }
}

function startFixture() {
  return new Promise((resolve, reject) => {
    const env = { ...process.env, AIHUB_COMPAT_ROOT_USER: process.env.AIHUB_COMPAT_ROOT_USER || 'root', AIHUB_COMPAT_ROOT_PASS: process.env.AIHUB_COMPAT_ROOT_PASS || 'root_test_only', AIHUB_DB_HOST: process.env.AIHUB_DB_HOST || '127.0.0.1' };
    const fx = spawn('php', ['tests/phase7_graph_fixture.php'], { cwd: ROOT, env, stdio: ['pipe', 'pipe', 'inherit'] });
    let buf = '';
    fx.stdout.on('data', d => { buf += d; const m = /READY (\d+) (.*)\n/.exec(buf); if (m) resolve({ proc: fx, base: 'http://127.0.0.1:' + m[1], meta: JSON.parse(m[2]) }); });
    fx.on('exit', c => reject(new Error('fixture exited early with ' + c)));
    setTimeout(() => reject(new Error('fixture did not start in 180 s')), 180000);
  });
}

const PROFILES = {
  desktop: { viewport: { width: 1280, height: 800 }, deviceScaleFactor: 1 },
  mobile360: { viewport: { width: 360, height: 740 }, deviceScaleFactor: 1, isMobile: true, hasTouch: true }
};
async function openPage(browser, profile, scheme, opts) {
  opts = opts || {};
  const ctx = await browser.newContext({ ...PROFILES[profile], colorScheme: scheme, javaScriptEnabled: opts.js !== false });
  const page = await ctx.newPage();
  openPages.add(page); page.once('close', () => openPages.delete(page));
  page.errs = []; page.reqs = [];
  page.on('pageerror', e => page.errs.push('pageerror: ' + e.message));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource/.test(m.text())) page.errs.push('console: ' + m.text()); });
  page.on('request', r => page.reqs.push({ url: r.url(), method: r.method() }));
  if (opts.init) await page.addInitScript(opts.init);
  return page;
}
const openPages = new Set(); let lastProgress = Date.now();
setInterval(async () => {   // watchdog: a stalled run prints where the page is stuck instead of hanging CI for 30 minutes
  if (Date.now() - lastProgress < 120000) return;
  console.error('WATCHDOG: no progress for 120 s');
  for (const pg of openPages) { try { const c = await pg.context().newCDPSession(pg); await c.send('Debugger.enable'); const ev = new Promise(r => c.once('Debugger.paused', r)); await c.send('Debugger.pause'); const e = await Promise.race([ev, new Promise(r => setTimeout(() => r(null), 3000))]); console.error(pg.url() + ' -> ' + (e ? e.callFrames.slice(0, 6).map(f => f.functionName + ':' + f.location.lineNumber + ' ' + f.url.split('/').pop()).join(' < ') : 'not pausable')); } catch (err) { console.error('cdp failed ' + err.message); } }
  process.exit(3);
}, 15000).unref();
async function ready(page) { await page.waitForFunction(() => window.GraphApp && GraphApp.perf.settled, null, { timeout: 60000 }); }
async function selectSlug(page, slug) { await page.evaluate(s => { const A = GraphApp.nodes(); GraphApp.select(A.findIndex(n => n.id === s), {}); }, slug); }
async function netState(page, slug) { return page.evaluate(s => { const n = GraphApp.nodes().find(x => x.id === s); return n && n.net ? { state: n.net.state, total: n.net.total, next: n.net.next, err: n.net.err } : null; }, slug); }
async function waitNet(page, slug, states) { await page.waitForFunction(([s, st]) => { const n = GraphApp.nodes().find(x => x.id === s); return n && n.net && st.includes(n.net.state); }, [slug, states], { timeout: 20000 }); }
const apiCalls = page => page.reqs.filter(r => r.url.includes('/universe-api.php'));

async function axeRun(page) {
  await page.evaluate(AXE_SRC);
  return page.evaluate(async () => {
    const r = await axe.run(document, { rules: { 'target-size': { enabled: true } }, resultTypes: ['violations', 'incomplete'] });
    const slim = list => list.map(v => ({ id: v.id, impact: v.impact, help: v.help, nodes: v.nodes.length, targets: v.nodes.slice(0, 4).map(n => n.target.join(' ')), summary: (v.nodes[0] && v.nodes[0].failureSummary || '').slice(0, 300) }));
    return { version: axe.version, violations: slim(r.violations), incomplete: slim(r.incomplete), passes: r.passes.length };
  });
}

(async () => {
  const fx = await startFixture();
  const { base, meta } = fx;
  console.error('fixture: ' + base + ' ' + JSON.stringify({ entities: meta.entities, acmeEdges: meta.acmeEdges }));
  const browser = await chromium.launch();
  try {
    // ------------------------------------------------------------------------------------------------------------------------------ load
    {
      const page = await openPage(browser, 'desktop', 'light');
      await page.goto(base + '/universe-graph.php'); await ready(page);
      const st = await page.evaluate(() => GraphApp.getState());
      check('loads every verified entity (and nothing else)', st.N === meta.entities, `N=${st.N} expected ${meta.entities}`);
      check('HTTP source is active on the production page', await page.evaluate(() => GraphApp.isHttp === true && GraphApp.source.kind === 'http'));
      check('relationships are not loaded before an entity is selected (progressive)', st.E === 0 && st.edgesLoaded === 0, `E=${st.E}`);
      const txt = await page.evaluate(() => document.body.innerText);
      check('no synthetic label anywhere on the production page', !/synthetic/i.test(txt) && !(await page.evaluate(() => /SYNTHETIC/i.test(document.documentElement.innerHTML))));
      check('synthetic generator is not loaded', await page.evaluate(() => typeof window.DataGen === 'undefined' && !document.querySelector('#srcSel') && !document.querySelector('script[src*="data-gen"]')));
      check('plain list is collapsed once the app is up, app is visible', await page.evaluate(() => document.getElementById('uni-static').open === false && !document.getElementById('ugapp').hidden));
      check('no external hosts contacted, API is GET only', page.reqs.every(r => r.url.startsWith(base) || r.url.startsWith('data:')) && apiCalls(page).every(r => r.method === 'GET'), page.reqs.filter(r => !r.url.startsWith(base)).map(r => r.url).join(','));
      check('no unverified or ghost entity in the node list', await page.evaluate(() => !GraphApp.nodes().some(n => /LEAK|ghost/i.test(n.name + n.id))));
      check('no page errors on load', page.errs.length === 0, page.errs.join(' | '));
      check('legend lists the nine relationship types separately', await page.evaluate(() => { const t = document.getElementById('legend').textContent; return ['developed_by', 'acquired_by', 'version_of', 'derived_from', 'built_on', 'successor_of', 'renamed_from', 'integrates_with', 'hosted_on'].every(r => t.includes(r)); }));
      check('canvas has no synthetic watermark and a graph role / label', await page.evaluate(() => { const c = document.getElementById('cv'); return c.getAttribute('role') === 'application' && /verified/i.test(c.getAttribute('aria-label')); }));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ expand (desktop) + search + load more
    {
      const page = await openPage(browser, 'desktop', 'light');
      await page.goto(base + '/universe-graph.php'); await ready(page);
      await page.fill('#q', 'Acme'); await page.waitForSelector('#results li[role=option]');
      await page.keyboard.press('ArrowDown'); await page.keyboard.press('Enter');
      await waitNet(page, 'acme', ['more', 'done']);
      let st = await page.evaluate(() => GraphApp.getState());
      const net = await netState(page, 'acme');
      check('selecting an entity requests only its own neighbourhood page (limit 100) and its detail', apiCalls(page).some(r => /r=graph&slug=acme&limit=100&offset=0/.test(r.url)) && apiCalls(page).some(r => /r=entity&slug=acme/.test(r.url)) && !apiCalls(page).some(r => /r=graph&slug=(?!acme)/.test(r.url)), apiCalls(page).map(r => r.url.slice(-60)).join(' '));
      check('first neighbourhood page is paged: 100 of ' + meta.acmeEdges, net.state === 'more' && net.total === meta.acmeEdges && st.edgesLoaded === 100, JSON.stringify(net) + ' loaded ' + st.edgesLoaded);
      const items = await page.evaluate(() => Array.from(document.querySelectorAll('#edges li')).map(li => ({ type: (li.querySelector('.rel span:nth-child(2)') || {}).textContent, link: !!li.querySelector('.cite a[href^="https://"]'), cite: li.querySelector('.cite') ? li.querySelector('.cite').textContent : '' })));
      check('panel lists 100 relationships, each with a typed label', items.length === 100 && items.every(i => i.type), items.length);
      check('every relationship shows its source link and the retrieval date', items.every(i => i.link && /retrieved 2025-01-15\)/.test(i.cite)), JSON.stringify(items[0]));
      check('panel states how many are loaded and that more exist', /Showing 100 loaded relationships of 110/.test(await page.textContent('#pnet')));
      check('entity verification date and source are shown', await page.evaluate(() => /Verified\s*\d{4}-\d{2}-\d{2}/.test(document.getElementById('pdl').textContent) && !!document.querySelector('#pdl a[href^="https://"]')));
      check('"Load more" button is visible after the first page', await page.isVisible('#pmore'));
      await page.focus('#pmore'); await page.keyboard.press('Enter');          // keyboard operation
      await waitNet(page, 'acme', ['done']);
      st = await page.evaluate(() => GraphApp.getState());
      const types = await page.evaluate(() => Array.from(new Set(Array.from(document.querySelectorAll('#edges li .rel span:nth-child(2)')).map(s => s.textContent))).sort());
      check('after "Load more" all relationships are present and the button is gone', st.edgesLoaded === meta.acmeEdges && !(await page.isVisible('#pmore')) && (await page.locator('#edges li').count()) === meta.acmeEdges, `edges ${st.edgesLoaded}`);
      check('relationship types stay separate (four distinct types kept)', JSON.stringify(types) === JSON.stringify(['acquired_by', 'developed_by', 'integrates_with', 'renamed_from']), types.join(','));
      check('focus is not lost when the "Load more" button disappears', await page.evaluate(() => document.activeElement && document.activeElement.id === 'pname'));
      check('requests of the second page use offset 100', apiCalls(page).some(r => /r=graph&slug=acme&limit=100&offset=100/.test(r.url)));
      check('unverified relationships of acme (proposed, in review, rejected) never appear', await page.evaluate(() => !/LEAK/.test(document.getElementById('panel').textContent)) && (await page.evaluate(() => GraphApp.audit())).unverifiedEdgesInUiArray === 0);
      // shareable URL: ?slug= follows the selection
      await page.waitForFunction(() => /[?&]slug=acme(&|#|$)/.test(location.search), null, { timeout: 5000 });
      check('selection is mirrored in a shareable ?slug= URL', await page.evaluate(() => /slug=acme/.test(location.search)), await page.evaluate(() => location.href));
      // click through to a neighbour from the panel
      await page.evaluate(() => { const b = Array.from(document.querySelectorAll('#edges .linkbtn')).find(x => x.textContent === 'Model X'); b.click(); });
      await waitNet(page, 'modelx', ['more', 'done']);
      await page.waitForFunction(() => /slug=modelx/.test(location.search), null, { timeout: 5000 });
      check('following a relationship selects the neighbour, loads its neighbourhood and updates the URL', (await page.textContent('#pname')) === 'Model X' && (await page.evaluate(() => GraphApp.getState().sel === GraphApp.nodes().findIndex(n => n.id === 'modelx'))));
      const mxTypes = await page.evaluate(() => Array.from(new Set(Array.from(document.querySelectorAll('#edges li .rel span:nth-child(2)')).map(s => s.textContent))).sort());
      check('model X shows its distinct types (derived_from, successor_of, built_on, hosted_on, developed_by, version_of)', ['built_on', 'derived_from', 'developed_by', 'hosted_on', 'successor_of', 'version_of'].every(t => mxTypes.includes(t)), mxTypes.join(','));
      check('"Developed by" is derived from a loaded developed_by relationship, not guessed', await page.evaluate(() => /Developed by\s*Acme Labs/.test(document.getElementById('pdl').textContent)));
      // keyboard: related cycling and back from the canvas
      await page.focus('#cv'); await page.keyboard.press(']');
      const afterRel = await page.evaluate(() => GraphApp.getState().sel); await page.keyboard.press('b');
      check('keyboard: "]" moves to a related entity and "b" goes back', afterRel >= 0 && (await page.evaluate(() => GraphApp.nodes()[GraphApp.getState().sel].id)) === 'modelx');
      await page.keyboard.press('Escape');
      check('keyboard: Escape clears the selection and closes the panel', (await page.evaluate(() => GraphApp.getState().sel)) === -1 && (await page.isHidden('#panel')));
      check('no page errors while expanding', page.errs.length === 0, page.errs.join(' | '));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ deep link
    {
      const page = await openPage(browser, 'desktop', 'light');
      await page.goto(base + '/universe-graph.php?slug=modelx'); await ready(page);
      await waitNet(page, 'modelx', ['more', 'done']);
      check('deep link ?slug= selects the entity and opens its details', (await page.textContent('#pname')) === 'Model X' && await page.isVisible('#panel'));
      check('deep link loads relationships', (await page.locator('#edges li').count()) >= 6);
      check('server-rendered detail for the same entity exists in the collapsed plain list', await page.evaluate(() => !!document.querySelector('#uni-static #entity') && document.getElementById('uni-static').open === false));
      await page.context().close();
      const page2 = await openPage(browser, 'desktop', 'light');
      await page2.goto(base + '/universe-graph.php?slug=does-not-exist');
      check('unknown slug: server answers 404 with the not-found text and no app', (await page2.textContent('main')).includes('Not found among the verified entities') && (await page2.evaluate(() => !document.getElementById('ugapp'))));
      await page2.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ review mode cannot be enabled
    {
      const page = await openPage(browser, 'desktop', 'light', { init: () => { window.UNIVERSE_REVIEW_ENABLED = true; } });
      await page.goto(base + '/universe-graph.php?mode=review&source=synthetic&size=50000&api=//evil.example/x'); await ready(page);
      await selectSlug(page, 'acme'); await waitNet(page, 'acme', ['more', 'done']);
      check('review mode cannot be enabled through the URL or a global (mode stays public)', await page.evaluate(() => GraphApp.cfg.mode === 'public' && GraphApp.isHttp && !document.getElementById('reviewBanner') && GraphApp.audit().unverifiedEdgesInUiArray === 0));
      check('?source=synthetic, ?size and ?api= have no effect on the production page', await page.evaluate(() => GraphApp.source.kind === 'http') && page.reqs.every(r => r.url.startsWith(base)) && !/synthetic/i.test(await page.evaluate(() => document.body.innerText)));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ hostile text
    {
      const page = await openPage(browser, 'desktop', 'light');
      await page.goto(base + '/universe-graph.php'); await ready(page);
      await page.evaluate(() => GraphApp.setView('table'));
      await page.fill('#q', 'HOSTILE'); await page.waitForFunction(() => document.querySelectorAll('#tbody tr[data-i]').length === 1);
      const cell = await page.evaluate(() => document.querySelector('#tbody .rowbtn').textContent);
      check('hostile entity name is shown as text in the table', cell === meta.hostileName, cell);
      await page.click('#tbody .rowbtn'); await waitNet(page, 'hostile-co', ['more', 'done']);
      check('hostile name is text in the panel; no element or handler was created from it', (await page.textContent('#pname')) === meta.hostileName && (await page.evaluate(() => !document.querySelector('#panel img, #tbody img, #results img, #ugapp img') && window.__xss === undefined)));
      await page.evaluate(() => GraphApp.setView('graph')); await page.fill('#q', 'HOSTILE'); await page.waitForSelector('#results li');
      check('hostile name is text in the search results', await page.evaluate(() => !document.querySelector('#results img') && document.querySelector('#results li').textContent.includes('HOSTILE') && window.__xss === undefined));
      await page.waitForTimeout(300);
      check('no script ran from entity text', await page.evaluate(() => window.__xss === undefined));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ error, empty and malformed states
    {
      let page = await openPage(browser, 'desktop', 'light');
      await page.route('**/universe-api.php*', r => r.abort());
      await page.goto(base + '/universe-graph.php'); await page.waitForSelector('#ugstate:not([hidden])');
      check('network failure: honest error with retry, role=alert, canvas hidden', await page.evaluate(() => { const s = document.getElementById('ugstate'); return s.getAttribute('role') === 'alert' && /Could not load verified data: network error/.test(s.textContent) && !!s.querySelector('button') && document.getElementById('cv').hidden; }));
      check('the plain server-rendered list stays open as the fallback', await page.evaluate(() => document.getElementById('uni-static').open === true));
      await page.unroute('**/universe-api.php*');
      await page.click('#ugstate button'); await ready(page);
      check('"Try again" recovers once the API is reachable', await page.evaluate(() => GraphApp.getState().N) === meta.entities);
      await page.context().close();

      page = await openPage(browser, 'desktop', 'light');
      await page.route('**/universe-api.php*', r => r.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"service_unavailable"}' }));
      await page.goto(base + '/universe-graph.php'); await page.waitForSelector('#ugstate:not([hidden])');
      check('HTTP 503: "temporarily unavailable" message', /temporarily unavailable/.test(await page.textContent('#ugstate')));
      await page.context().close();

      page = await openPage(browser, 'desktop', 'light');
      await page.route('**/universe-api.php*', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{"data":{"items":[],"page":1,"per_page":100,"total":0,"has_more":false}}' }));
      await page.goto(base + '/universe-graph.php'); await page.waitForSelector('#ugstate:not([hidden])');
      check('empty API answer: "No verified data yet" (role=status), no canvas', await page.evaluate(() => { const s = document.getElementById('ugstate'); return s.getAttribute('role') === 'status' && /No verified data yet/.test(s.textContent) && document.getElementById('cv').hidden; }));
      await page.context().close();

      page = await openPage(browser, 'desktop', 'light');
      await page.route('**/universe-api.php*', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{"data":{"items":"nope","total":"x"}}' }));
      await page.goto(base + '/universe-graph.php'); await page.waitForSelector('#ugstate:not([hidden])');
      check('malformed API answer is rejected, not rendered', /unexpected format/.test(await page.textContent('#ugstate')));
      await page.context().close();

      // hostile / out-of-contract payload: unknown types, bad slugs and refs are dropped, valid rows survive, nothing throws
      page = await openPage(browser, 'desktop', 'light');
      const items = [
        { ref: 'a'.repeat(32), entity_type: 'company', slug: 'ok-co', name: 'Ok Co', summary: 's', verified_at: '2025-01-01 00:00:00' },
        { ref: 'b'.repeat(32), entity_type: 'family', slug: 'bad-type', name: 'Bad type', summary: '', verified_at: '' },
        { ref: 'c'.repeat(32), entity_type: 'model', slug: 'Bad Slug!', name: 'Bad slug', summary: '', verified_at: '' },
        { ref: '', entity_type: 'model', slug: 'no-ref', name: 'No ref', summary: '', verified_at: '' },
        { ref: 'd'.repeat(32), entity_type: 'model', slug: 'no-name', name: '   ', summary: '', verified_at: '' },
        { ref: 'e'.repeat(32), entity_type: 'product', slug: 'ok-prod', name: 'Ok Prod', summary: '', verified_at: '' }, 'junk', null, 5];
      await page.route('**/universe-api.php*', r => {
        const u = r.request().url();
        if (/r=entities/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { items, page: 1, per_page: 100, total: items.length, has_more: false } }) });
        if (/r=graph/.test(u)) return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { center: 'a'.repeat(32), nodes: [], edges: [
          { from: 'a'.repeat(32), to: 'e'.repeat(32), type: 'developed_by', valid_from: 'garbage', valid_to: null, sources: [{ url: 'javascript:alert(1)', publisher: 'P', retrieved_at: '2025-01-01' }, 5] },
          { from: 'a'.repeat(32), to: 'e'.repeat(32), type: 'similarity', sources: [] }, { from: 'zz', to: 'a'.repeat(32), type: 'developed_by', sources: [] }, null], total_edges: 4, has_more: false, next_offset: null } }) });
        return r.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ data: { slug: 'ok-co', summary: 'x', verified_at: '2025-01-01', sources: [{ url: 'javascript:alert(2)', publisher: 'Evil', retrieved_at: '2025-01-02' }] } }) });
      });
      await page.goto(base + '/universe-graph.php'); await ready(page);
      const dropped = await page.evaluate(() => ({ N: GraphApp.getState().N, stats: GraphApp.source.stats }));
      check('payload validation: invalid rows are dropped and counted, valid ones kept', dropped.N === 2 && dropped.stats.droppedNodes >= 6, JSON.stringify(dropped));
      await selectSlug(page, 'ok-co'); await waitNet(page, 'ok-co', ['done']);
      const hostileEdge = await page.evaluate(() => ({ e: GraphApp.getState().edgesLoaded, links: Array.from(document.querySelectorAll('#panel a')).map(a => a.getAttribute('href')), types: Array.from(document.querySelectorAll('#edges .rel span:nth-child(2)')).map(s => s.textContent) }));
      check('unknown relationship types and unknown endpoints are dropped; javascript: URLs never become links', hostileEdge.types.join() === 'developed_by' && hostileEdge.links.every(h => /^https?:/.test(h)) && hostileEdge.links.length === 0, JSON.stringify(hostileEdge));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ neighbourhood errors, retry, aborts
    {
      const page = await openPage(browser, 'desktop', 'light');
      let fail = true;
      await page.route('**/universe-api.php?r=graph*', r => fail ? r.fulfill({ status: 429, contentType: 'application/json', headers: { 'Retry-After': '60' }, body: '{"error":"rate_limited"}' }) : r.continue());
      await page.goto(base + '/universe-graph.php'); await ready(page);
      await selectSlug(page, 'modelx'); await waitNet(page, 'modelx', ['error']);
      check('HTTP 429 on a neighbourhood: honest message and a retry button, graph stays usable', /Could not load relationships: too many requests/.test(await page.textContent('#pnet')) && (await page.isVisible('#pmore')) && (await page.textContent('#pmore')) === 'Try again' && await page.evaluate(() => GraphApp.getState().N > 0));
      fail = false; await page.click('#pmore'); await waitNet(page, 'modelx', ['more', 'done']);
      check('retry after the error loads the relationships', (await page.locator('#edges li').count()) >= 6 && !(await page.isVisible('#pmore')));
      await page.context().close();

      const page2 = await openPage(browser, 'desktop', 'light');
      await page2.route('**/universe-api.php?r=graph&slug=prodz*', async r => { await new Promise(res => setTimeout(res, 2500)); try { await r.continue(); } catch (e) { /* aborted by the page */ } });
      await page2.goto(base + '/universe-graph.php'); await ready(page2);
      const aborted = []; page2.on('requestfailed', rq => { if (/slug=prodz/.test(rq.url())) aborted.push(rq.failure() && rq.failure().errorText); });
      await selectSlug(page2, 'prodz'); await page2.waitForTimeout(300); await selectSlug(page2, 'globex');
      await waitNet(page2, 'globex', ['more', 'done']); await page2.waitForTimeout(400);
      check('moving the selection aborts the in-flight neighbourhood request of the previous entity', aborted.length > 0 && /ABORTED/i.test(aborted[0]), JSON.stringify(aborted));
      check('an aborted entity can be loaded again when selected again', await (async () => { await page2.unroute('**/universe-api.php?r=graph&slug=prodz*'); await selectSlug(page2, 'prodz'); await waitNet(page2, 'prodz', ['more', 'done']); return (await page2.locator('#edges li').count()) >= 4; })());
      await page2.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ keyboard: table, skip links, graph
    {
      const page = await openPage(browser, 'desktop', 'light');
      await page.goto(base + '/universe-graph.php'); await ready(page);
      await page.focus('#vTable'); await page.keyboard.press('Enter');
      check('keyboard: Enter on the Table button switches to the table', (await page.evaluate(() => GraphApp.getView())) === 'table' && (await page.locator('#tbody tr[data-i]').count()) === 50);
      await page.focus('#tbody .rowbtn'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
      check('keyboard: arrow keys move between row buttons', await page.evaluate(() => document.activeElement.classList.contains('rowbtn') && document.activeElement.closest('tr') === document.querySelectorAll('#tbody tr')[2]));
      await page.keyboard.press('Enter'); await page.waitForTimeout(200);
      check('keyboard: Enter on a row selects the entity and moves focus to its details', await page.evaluate(() => GraphApp.getState().sel >= 0 && document.activeElement.id === 'pname'));
      await page.focus('#next'); await page.keyboard.press('Enter');
      check('keyboard: pager works (page 2 of 3, 50 rows)', (await page.evaluate(() => GraphApp.getState().tablePage)) === 1 && (await page.locator('#tbody tr[data-i]').count()) === 50);
      await page.focus('#vGraph'); await page.keyboard.press('Space');
      await page.focus('#cv'); await page.keyboard.press('n');
      check('keyboard: N selects an entity from the graph, Enter opens its details', await page.evaluate(() => GraphApp.getState().sel >= 0) && (await page.keyboard.press('Enter'), await page.evaluate(() => document.activeElement.id === 'pname')));
      await page.keyboard.press('Tab');
      check('keyboard: Tab moves on from the details heading to the Close button', await page.evaluate(() => document.activeElement.id === 'pclose'));
      await page.keyboard.press('Enter');
      check('keyboard: Close clears the selection and returns focus', (await page.evaluate(() => GraphApp.getState().sel)) === -1 && await page.evaluate(() => document.activeElement && document.activeElement.tagName !== 'BODY'));
      // skip links
      await page.focus('#ugapp .skip[data-skip=stage]'); await page.keyboard.press('Enter');
      check('keyboard: skip link focuses the graph', await page.evaluate(() => document.activeElement.id === 'cv'));
      check('no page errors during keyboard use', page.errs.length === 0, page.errs.join(' | '));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ without JavaScript
    {
      const page = await openPage(browser, 'desktop', 'light', { js: false });
      await page.goto(base + '/universe-graph.php');
      const r = await page.evaluate(() => ({ appShown: getComputedStyle(document.getElementById('ugapp')).display !== 'none', open: document.getElementById('uni-static').open, rows: document.querySelectorAll('#uni-static tbody tr').length, links: document.querySelectorAll('#uni-static tbody a[href*="slug="]').length, note: !!document.querySelector('noscript') }));
      check('no JavaScript: app hidden, plain list open with 50 entities per page and deep links', !r.appShown && r.open && r.rows === 50 && r.links === 50, JSON.stringify(r));
      await page.goto(base + '/universe-graph.php?slug=acme');
      const d = await page.evaluate(() => ({ rows: document.querySelectorAll('#entity table tbody tr').length, th: document.querySelectorAll('#entity table tbody th[scope=row]').length, cites: document.querySelectorAll('#entity table a[rel="nofollow noopener"]').length, more: /Showing the first 100 of 110/.test(document.getElementById('entity').textContent) }));
      check('no JavaScript: entity page lists its verified relationships with sources (first 100 of 110)', d.rows === 100 && d.th === 100 && d.cites >= 100 && d.more, JSON.stringify(d));
      await page.context().close();
    }

    // ------------------------------------------------------------------------------------------------------------------------------ axe-core
    const states = {
      'graph-idle': async (p, base0) => { await p.goto(base0 + '/universe-graph.php'); await ready(p); },
      'graph-selected': async (p, base0) => { await p.goto(base0 + '/universe-graph.php?slug=acme'); await ready(p); await waitNet(p, 'acme', ['more', 'done']); await p.waitForTimeout(300); },
      'table': async (p, base0) => { await p.goto(base0 + '/universe-graph.php'); await ready(p); await p.evaluate(() => GraphApp.setView('table')); await p.waitForTimeout(200); },
      'search-open': async (p, base0) => { await p.goto(base0 + '/universe-graph.php'); await ready(p); await p.evaluate(() => { document.getElementById('controls').open = true; }); await p.fill('#q', 'sat'); await p.waitForSelector('#results li'); },
      'legend-help-open': async (p, base0) => { await p.goto(base0 + '/universe-graph.php'); await ready(p); await p.evaluate(() => { for (const id of ['controls', 'legendBox', 'kbdBox']) document.getElementById(id).open = true; }); },
      'api-error': async (p, base0) => { await p.route('**/universe-api.php*', r => r.fulfill({ status: 503, contentType: 'application/json', body: '{"error":"service_unavailable"}' })); await p.goto(base0 + '/universe-graph.php'); await p.waitForSelector('#ugstate:not([hidden])'); },
      'api-empty': async (p, base0) => { await p.route('**/universe-api.php*', r => r.fulfill({ status: 200, contentType: 'application/json', body: '{"data":{"items":[],"page":1,"per_page":100,"total":0,"has_more":false}}' })); await p.goto(base0 + '/universe-graph.php'); await p.waitForSelector('#ugstate:not([hidden])'); },
      'no-js-list': async (p, base0) => { await p.goto(base0 + '/universe-graph.php'); },
      'no-js-entity': async (p, base0) => { await p.goto(base0 + '/universe-graph.php?slug=acme'); }
    };
    for (const scheme of ['light', 'dark']) for (const prof of ['desktop', 'mobile360']) for (const [name, run] of Object.entries(states)) {
      const nojs = name.startsWith('no-js');
      // axe cannot run with scripting disabled, so the no-JS states block the graph assets instead (the app stays hidden exactly as without JavaScript)
      const page = await openPage(browser, prof, scheme, {});
      if (nojs) await page.route('**/assets/universe/**', r => r.abort());
      await run(page, base);
      const ax = await axeRun(page);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      results.axe.push({ state: name, scheme, profile: prof, version: ax.version, violations: ax.violations, incomplete: ax.incomplete.map(i => ({ id: i.id, nodes: i.nodes, targets: i.targets })), passes: ax.passes, horizontalOverflowPx: overflow });
      check(`axe ${scheme}/${prof}/${name}: 0 violations`, ax.violations.length === 0, JSON.stringify(ax.violations.map(v => v.id + ':' + v.targets.join('|'))));
      if (prof === 'mobile360') check(`no horizontal page scroll at 360px (${scheme}/${name})`, overflow <= 0, overflow);
      await page.context().close();
    }
  } finally {
    await browser.close();
    fx.proc.stdin.end();
    await new Promise(r => { fx.proc.on('exit', r); setTimeout(r, 15000); });
  }
  results.summary = { checks: results.checks.length, failed, axeRuns: results.axe.length, axeViolations: results.axe.reduce((n, a) => n + a.violations.length, 0) };
  fs.writeFileSync(path.resolve(process.cwd(), OUT), JSON.stringify(results, null, 1));
  console.log(`checks: ${results.checks.length - failed} passed, ${failed} failed; axe runs: ${results.axe.length}, violations: ${results.summary.axeViolations}`);
  process.exit(failed ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
