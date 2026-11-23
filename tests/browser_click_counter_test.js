// Browser check (no database, no server): the public click counter only ever shows numbers returned by the server (/click-count.php, mocked here), never adds 1 itself, refreshes when the visitor
// returns from the tool's website, ignores failures, and does not touch the counter for clicks it did not see. Usage: NODE_PATH=$(npm root -g) node tests/browser_click_counter_test.js
const { chromium } = require('playwright'); const path = require('path'); const fs = require('fs');
let pass = 0, fail = 0; const ok = (c, m) => { if (c) pass++; else { fail++; console.error('  FAIL: ' + m); } };
const js = fs.readFileSync(path.join(__dirname, '..', 'assets/js/interactions.js'), 'utf8');
const html = `<!doctype html><html><head><meta charset="utf-8"><meta name="csrf-token" content="t"></head><body>
<div class="voteRow toolStatsAjax" data-tool-id="alpha"><span class="clickPill">👁 <span class="js-click-count">10</span> clicks</span><a id="la" href="/redirect.php?id=alpha" target="_blank">Visit site</a></div>
<div class="voteRow toolStatsAjax" data-tool-id="beta"><span class="clickPill">👁 <span class="js-click-count">20</span> clicks</span><a id="lb" href="/redirect.php?id=beta">Visit site</a></div>
<script>${js}</script></body></html>`;
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext(); const page = await ctx.newPage(); const calls = []; let server = { alpha: 10, beta: 20 }, mode = 'ok';
  await page.route('**/click-count.php*', r => { const u = new URL(r.request().url()); const ids = (u.searchParams.get('ids') || '').split(','); calls.push(ids.join(',')); if (mode === 'fail') return r.fulfill({ status: 500, body: 'x' }); const counts = {}; ids.forEach(i => { if (i in server) counts[i] = server[i]; }); r.fulfill({ status: 200, contentType: 'application/json', headers: { 'Cache-Control': 'no-store' }, body: JSON.stringify({ counts, source: 'database' }) }); });
  await page.route('**/redirect.php*', r => r.fulfill({ status: 200, contentType: 'text/html', body: '<html>external</html>' }));
  await page.route('http://tool.test/page', r => r.fulfill({ status: 200, contentType: 'text/html', body: html })); await page.goto('http://tool.test/page'); await page.waitForTimeout(200); const val = id => page.evaluate(i => document.querySelector('.toolStatsAjax[data-tool-id="' + i + '"] .js-click-count').textContent, id);
  ok((await val('alpha')) === '10' && calls.length === 0, 'initial: the server-rendered number is shown and nothing is fetched on a normal load');
  // click Visit site (new tab): the number must NOT change by itself
  const popup = page.waitForEvent('popup'); await page.click('#la'); const p2 = await popup; await p2.close().catch(() => {}); await page.waitForTimeout(150);
  ok((await val('alpha')) === '10', 'clicking Visit site never increments the number locally');
  // the server saved the click; returning to the tab refreshes from the server
  server.alpha = 11; await page.evaluate(() => { Object.defineProperty(document, 'visibilityState', { configurable: true, get: () => 'visible' }); document.dispatchEvent(new Event('visibilitychange')); }); await page.waitForTimeout(900);
  ok((await val('alpha')) === '11' && calls.length === 1 && calls[0] === 'alpha', 'returning to the page shows the SERVER total (11) for the clicked tool only');
  ok((await val('beta')) === '20', 'other tools on the page are untouched');
  // a suppressed (duplicate/bot) click: the server total does not change, so the number does not change
  const pp = page.waitForEvent('popup'); await page.click('#la'); (await pp).close().catch(() => {}); await page.waitForTimeout(100); await page.evaluate(() => document.dispatchEvent(new Event('visibilitychange'))); await page.waitForTimeout(900);
  ok((await val('alpha')) === '11', 'a click the server suppressed leaves the number at the server total (11)');
  // failure: nothing guessed
  mode = 'fail'; server.alpha = 12; const p3 = page.waitForEvent('popup'); await page.click('#la'); (await p3).close().catch(() => {}); await page.evaluate(() => window.dispatchEvent(new Event('focus'))); await page.waitForTimeout(900);
  ok((await val('alpha')) === '11', 'when the server cannot be reached the old number stays (nothing is guessed)');
  mode = 'ok'; await page.evaluate(() => window.dispatchEvent(new Event('focus'))); await page.waitForTimeout(900); ok((await val('alpha')) === '12', 'the next refresh succeeds and shows the server total (12): the click stayed pending after the failure');
  // Back button / bfcache restore: refresh every counter on the page from the server
  server.alpha = 15; server.beta = 25; await page.evaluate(() => { const e = new Event('pageshow'); e.persisted = true; window.dispatchEvent(e); }); await page.waitForTimeout(900);
  ok((await val('alpha')) === '15' && (await val('beta')) === '25', 'a page restored from the browser cache (Back button) re-reads all totals from the server');
  // hostile response values are ignored
  server.alpha = -5; await page.evaluate(() => { const e = new Event('pageshow'); e.persisted = true; window.dispatchEvent(e); }); await page.waitForTimeout(900); ok((await val('alpha')) === '15', 'a negative or invalid number from the server is ignored');
  ok(!calls.some(c => c.split(',').length > 30), 'never more than 30 ids per request');
  const src = js; ok(!/textContent\s*=\s*String\([^)]*\+\s*1/.test(src) && !/\+\+/.test(src.slice(src.indexOf('Click counter refresh'))), 'the refresh code contains no local increment');
  await b.close(); console.log(`browser: PASSED ${pass} FAILED ${fail}`); process.exit(fail ? 1 : 0);
})();
