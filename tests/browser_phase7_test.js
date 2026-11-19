// Phase 7 browser tests: heatmap, lineage, arena and the editor / verifier pages. Chromium; desktop and 360px mobile, light and dark; keyboard-only; no-JS; reduced motion.
// Usage: node tests/browser_phase7_test.js [screenshotDir] [prefix]   (needs NODE_PATH with playwright and axe-core, or AXE_PATH)
// It starts tests/phase7_pages_server.php itself: a scratch database with a SYNTHETIC ledger and synthetic CRM users, on a port in 8270-8290. Nothing else is touched.
const { chromium, devices } = require('playwright');
const { spawn } = require('child_process');
const fs = require('fs'); const path = require('path');
const shotDir = process.argv[2] || './shots'; const prefix = process.argv[3] || 'p7';
const axePath = process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'); fs.mkdirSync(shotDir, { recursive: true });
let pass = 0, fail = 0; const axeTotals = { pages: 0, violations: 0 };
const check = (c, m) => { if (c) pass++; else { fail++; console.log('  FAIL: ' + m); } };

async function axe(page) {
  await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' });
  await page.evaluate(() => document.querySelectorAll('.reveal').forEach(e => e.classList.add('in')));
  await page.addScriptTag({ path: axePath });
  return page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] } })).violations.map(v => v.id + ':' + v.nodes.length + ' ' + v.nodes.slice(0, 2).map(n => n.target.join(' ')).join(' | ')));
}
const noHScroll = page => page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1);
// interactive controls inside <main> smaller than 44x44 CSS px (inline text links are exempt, as in WCAG 2.5.8)
const smallTargets = page => page.evaluate(() => {
  const out = []; const main = document.querySelector('main'); if (!main) return out;
  main.querySelectorAll('a[href],button,select,input:not([type=hidden]),textarea,summary,[role=button]').forEach(e => {
    const cs = getComputedStyle(e); if (cs.visibility === 'hidden' || cs.display === 'none') return;
    let r = e.getBoundingClientRect();
    if (e.tagName === 'INPUT' && (e.type === 'checkbox' || e.type === 'radio')) { const l = e.closest('label') || (e.id && document.querySelector('label[for="' + e.id + '"]')); if (l) r = l.getBoundingClientRect(); }
    if (r.width <= 1 && r.height <= 1) return;
    if (e.tagName === 'A' && cs.display === 'inline') return;
    if (r.width < 43.5 || r.height < 43.5) out.push((e.tagName + '.' + e.className + ' ' + (e.textContent || e.getAttribute('aria-label') || '').trim().slice(0, 24)) + ' ' + Math.round(r.width) + 'x' + Math.round(r.height));
  });
  return out;
});

(async () => {
  const fx = spawn('php', ['tests/phase7_pages_server.php'], { cwd: path.join(__dirname, '..'), env: process.env, stdio: ['pipe', 'pipe', 'inherit'] });
  const info = await new Promise((res, rej) => { let buf = ''; fx.stdout.on('data', d => { buf += d; const i = buf.indexOf('\n'); if (i >= 0) { try { res(JSON.parse(buf.slice(0, i))); } catch (e) { rej(e); } } }); fx.on('exit', c => rej(new Error('fixture exited ' + c))); setTimeout(() => rej(new Error('fixture timeout')), 120000); });
  const base = 'http://127.0.0.1:' + info.port;
  const clearRate = () => { try { for (const f of fs.readdirSync(info.rate_dir)) fs.unlinkSync(path.join(info.rate_dir, f)); } catch (e) {} };
  const browser = await chromium.launch();
  const publicPages = [
    ['heatmap-year', '/universe-heatmap.php'], ['heatmap-day', '/universe-heatmap.php?day=2024-03-15'], ['heatmap-month', '/universe-heatmap.php?view=month&month=2024-03&day=2024-03-15'],
    ['heatmap-week', '/universe-heatmap.php?view=week&week=2024-03-13'], ['heatmap-coarse-year', '/universe-heatmap.php?year=2023'],
    ['lineage-index', '/universe-lineage.php'], ['lineage-modelx', '/universe-lineage.php?entity=modelx'], ['lineage-modely', '/universe-lineage.php?entity=modely'], ['lineage-diff', '/universe-lineage.php?entity=modelx&a=v1.0&b=v2.0'],
    ['arena-select', '/universe-arena.php'], ['arena-two', '/universe-arena.php?e=modelx,modely'], ['arena-four', '/universe-arena.php?e=acme,evil-co,modelx,modely'], ['arena-sparse', '/universe-arena.php?e=prodz,lonely']];
  const editorPages = { owner: [['editor-home', '/universe-editor.php'], ['editor-overview', '/universe-editor.php?v=overview'], ['editor-item-entity', `/universe-editor.php?v=item&t=entity&id=${info.items.entity}`]],
    editor: [['editor-mine', '/universe-editor.php?v=mine'], ['editor-new', '/universe-editor.php?v=new'], ['editor-new-entity', '/universe-editor.php?v=new&t=entity'], ['editor-new-event', '/universe-editor.php?v=new&t=event']],
    verifier: [['verifier-queue', '/universe-editor.php?v=queue'], ['verifier-item', `/universe-editor.php?v=item&t=event&id=${info.items.event_in_review}`]] };
  const profiles = [['desktop-light', { viewport: { width: 1366, height: 900 }, colorScheme: 'light' }], ['desktop-dark', { viewport: { width: 1366, height: 900 }, colorScheme: 'dark' }],
    ['mobile-light', { ...devices['Pixel 7'], viewport: { width: 360, height: 780 }, colorScheme: 'light' }], ['mobile-dark', { ...devices['Pixel 7'], viewport: { width: 360, height: 780 }, colorScheme: 'dark' }]];
  const audit = async (page, label, P) => {
    const errs = []; const onErr = e => errs.push(String(e)); page.on('pageerror', onErr);
    const resp = await page.goto(base + label[1], { waitUntil: 'load' });
    check(resp && resp.status() < 500, `${P} ${label[0]} is not a server error`);
    check(await page.locator('h1').count() === 1, `${P} ${label[0]} has exactly one h1`);
    check(!(await page.content()).match(/Fatal error|Warning:|Notice:|SQLSTATE/), `${P} ${label[0]} shows no PHP error text`);
    check(await noHScroll(page), `${P} ${label[0]} has no horizontal page scrolling`);
    const ax = await axe(page); axeTotals.pages++; axeTotals.violations += ax.length; check(ax.length === 0, `${P} ${label[0]}: axe violations ${JSON.stringify(ax)}`);
    const st = await smallTargets(page); check(st.length === 0, `${P} ${label[0]}: targets under 44px ${JSON.stringify(st.slice(0, 6))}`);
    check(errs.length === 0, `${P} ${label[0]}: page errors ${errs.join(';')}`); page.off('pageerror', onErr);
  };
  for (const [prof, opts] of profiles) {
    const P = `[${prof}]`; const ctx = await browser.newContext(opts); await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort()); const page = await ctx.newPage();
    for (const pg of publicPages) await audit(page, pg, P);
    check(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === (prof.endsWith('dark') ? 'dark' : 'light'), `${P} theme follows the colour scheme`);
    await page.goto(base + '/universe-heatmap.php'); await page.screenshot({ path: `${shotDir}/${prefix}-heatmap-${prof}.png`, fullPage: true });
    await page.goto(base + '/universe-lineage.php?entity=modely'); await page.screenshot({ path: `${shotDir}/${prefix}-lineage-${prof}.png`, fullPage: true });
    await page.goto(base + '/universe-arena.php?e=modelx,modely'); await page.screenshot({ path: `${shotDir}/${prefix}-arena-${prof}.png`, fullPage: true });
    await ctx.close();
    for (const [role, pages] of Object.entries(editorPages)) {
      const c = await browser.newContext(opts); await c.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort()); const p = await c.newPage();
      clearRate(); await p.goto(base + '/universe-editor.php', { waitUntil: 'load' });
      check(await p.locator('h1').count() === 1, `${P} editor sign-in has one h1`);
      const ax0 = await axe(p); axeTotals.pages++; axeTotals.violations += ax0.length; check(ax0.length === 0, `${P} editor sign-in: axe violations ${JSON.stringify(ax0)}`);
      await p.waitForTimeout(1600); await p.fill('#crmUser', info.users[role]); await p.fill('#crmPw', info.password); await Promise.all([p.waitForNavigation(), p.keyboard.press('Enter')]);
      check(await p.locator('text=Sign out').count() >= 1, `${P} ${role} signs in with the keyboard`);
      for (const pg of pages) await audit(p, pg, `${P}[${role}]`);
      await c.close();
    }
  }

  // ---- keyboard-only operation (desktop) ----
  {
    const ctx = await browser.newContext({ viewport: { width: 1366, height: 900 } }); await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort()); const page = await ctx.newPage(); const K = '[keyboard]';
    const tabTo = async (pred, max = 160) => { for (let i = 0; i < max; i++) { await page.keyboard.press('Tab'); if (await page.evaluate(pred)) return true; } return false; };
    await page.goto(base + '/universe-heatmap.php'); await page.keyboard.press('Tab');
    check(await page.evaluate(() => document.activeElement.classList.contains('skipLink')), `${K} the first Tab stop is the skip link`);
    check(await tabTo(() => document.activeElement.classList.contains('uhCell')) && await page.evaluate(() => document.activeElement.dataset.date) === '2024-03-15', `${K} Tab reaches the first active day of the calendar`);
    await page.keyboard.press('ArrowRight'); check(await page.evaluate(() => document.activeElement.dataset.date) === '2024-03-16', `${K} ArrowRight moves to the next active day`);
    await page.keyboard.press('ArrowRight'); check(await page.evaluate(() => document.activeElement.dataset.date) === '2024-03-18', `${K} arrow keys skip empty days`);
    await page.keyboard.press('Enter'); await page.waitForFunction(() => /March 18, 2024/.test((document.querySelector('#day-h') || {}).textContent || ''));
    check(/day=2024-03-18/.test(page.url()) && await page.evaluate(() => document.activeElement.id) === 'day-panel', `${K} Enter opens the day panel and moves focus to it, the address holds the day`);
    check(await page.locator('#day-panel li.upCard').count() === 1 && await page.locator('#day-panel a[href^="https://example.test"]').count() >= 1, `${K} the panel shows the verified event and its source`);
    check(await page.locator('a.uhSel[aria-current="date"]').count() === 1, `${K} the selected day is marked`);
    await page.goBack(); await page.waitForFunction(() => /Select a day/.test((document.querySelector('#day-panel') || {}).textContent || ''));
    check(!/day=/.test(page.url()), `${K} Back restores the previous view`);
    await page.goto(base + '/universe-heatmap.php'); await page.focus('#uh-entity'); await page.keyboard.type('Model X');
    check(await tabTo(() => document.activeElement.tagName === 'BUTTON' && /Apply/.test(document.activeElement.textContent), 12), `${K} the Apply button is reachable by Tab`);
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); check(/entity=modelx/.test(page.url()), `${K} the company filter is applied with the keyboard only`);
    await page.goto(base + '/universe-heatmap.php?view=month&month=2024-03'); check(await page.locator('#uh-table table').count() >= 1, `${K} the table alternative exists`);
    await page.goto(base + '/universe-lineage.php?entity=modelx');
    check(await tabTo(() => document.activeElement.dataset && document.activeElement.dataset.ulToggle === 'technical_ancestry'), `${K} lineage: the relationship toggle is reachable`);
    await page.keyboard.press('Space'); check(await page.locator('[data-kind="technical_ancestry"].isOff').count() > 0, `${K} Space hides the technical-ancestry lines`);
    await page.keyboard.press('Space'); check(await page.locator('[data-kind="technical_ancestry"].isOff').count() === 0, `${K} Space shows them again`);
    check(await tabTo(() => document.activeElement.classList.contains('ulNodeLink')), `${K} lineage: a graph node is reachable by Tab`);
    await page.keyboard.press('Enter'); check(/#rel-[0-9a-f]{32}/.test(page.url()), `${K} Enter on a graph node jumps to its commit entry`);
    await page.focus('#ul-a'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Tab'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
    await page.keyboard.press('Tab'); await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    check(/a=[0-9a-f]{32}&b=[0-9a-f]{32}/.test(page.url()) && await page.locator('#diff table').count() === 1, `${K} lineage: the version diff form works with the keyboard only`);
    await page.goto(base + '/universe-arena.php'); await page.focus('#ua-0'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('Tab'); await page.keyboard.press('ArrowDown'); await page.keyboard.press('ArrowDown');
    check(await tabTo(() => document.activeElement.tagName === 'BUTTON' && /Compare/.test(document.activeElement.textContent), 6), `${K} arena: Compare is reachable by Tab`);
    await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]); check(/universe-arena\.php\?e=acme%2Cevil-co|universe-arena\.php\?e=acme,evil-co/.test(page.url()) && await page.locator('h1').innerText().then(t => /Acme Labs vs/.test(t)), `${K} arena: a comparison is opened with the keyboard only (${page.url()})`);
    await page.goto(base + '/universe-arena.php'); await page.focus('#ua-0'); await page.keyboard.press('ArrowDown'); await page.focus('#ua-1'); await page.keyboard.press('ArrowDown');
    check(/different entities/.test(await page.locator('[data-ua-msg]').innerText()), `${K} arena: choosing the same entity twice is explained`);
    await ctx.close();
  }

  // ---- no JavaScript ----
  {
    const ctx = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } }); const page = await ctx.newPage(); const J = '[no-JS]';
    await page.goto(base + '/universe-lineage.php?entity=modelx'); check(await page.locator('svg.ulSvg').count() === 2 && await page.locator('a.ulNodeLink').count() >= 2, `${J} both SVG graphs are present without JavaScript`);
    await page.goto(base + '/universe-heatmap.php'); await page.locator('a.uhCell[data-date="2024-03-15"]').click(); await page.waitForLoadState('load');
    check(/day=2024-03-15/.test(page.url()) && await page.locator('#day-panel li.upCard').count() === 3, `${J} a day link opens the panel by a normal page load`);
    await page.goto(base + '/universe-arena.php'); await page.selectOption('#ua-0', 'modelx'); await page.selectOption('#ua-1', 'modely'); await Promise.all([page.waitForNavigation(), page.locator('.uaForm button[type=submit]').click()]);
    check(/e=modelx%2Cmodely|e=modelx,modely/.test(page.url()) && await page.locator('#ua-fact-h').count() === 1, `${J} the arena form works`);
    await ctx.close();
  }
  // ---- reduced motion ----
  {
    const ctx = await browser.newContext({ reducedMotion: 'reduce', viewport: { width: 1280, height: 900 } }); const page = await ctx.newPage();
    await page.goto(base + '/universe-heatmap.php');
    check(await page.evaluate(() => parseFloat(getComputedStyle(document.querySelector('a.uhCell')).transitionDuration)) < 0.001 && await page.evaluate(() => getComputedStyle(document.documentElement).scrollBehavior) === 'auto', '[reduced-motion] no transitions and no smooth scrolling');
    await ctx.close();
  }
  await browser.close(); fx.stdin.end(); await new Promise(r => { fx.on('exit', r); setTimeout(r, 15000); });
  console.log(`\naxe: ${axeTotals.pages} pages checked, ${axeTotals.violations} violations`);
  console.log(`PASSED: ${pass}  FAILED: ${fail}`); process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
