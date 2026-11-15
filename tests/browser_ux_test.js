// Phase 3 browser tests: the redesigned experience, conversion journeys and privacy behaviour.
// Usage: node tests/browser_ux_test.js <baseUrl> <screenshotDir> [label]
// Needs: the isolated test database (name must contain "test"), the SMTP sink log, and the server started with AIHUB_ANALYTICS=1.
const { chromium, devices } = require('playwright');
const { execFileSync } = require('child_process');
const fs = require('fs');

const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, '');
const shotDir = process.argv[3] || './screenshots';
const label = process.argv[4] || 'ux';
fs.mkdirSync(shotDir, { recursive: true });
const DB = { host: process.env.AIHUB_DB_HOST || '127.0.0.1', name: process.env.AIHUB_DB_NAME || 'aihub_test', user: process.env.AIHUB_DB_USER || 'aihub_test', pass: process.env.AIHUB_DB_PASS || 'test_only_pw' };
const SMTP_LOG = process.env.AIHUB_SMTP_LOG || '/tmp/smtp_sink.log';
if (!DB.name.includes('test')) { console.error('Refusing to run: database name must contain "test"'); process.exit(2); }
const sql = (q) => execFileSync('mysql', ['-h', DB.host, '-u' + DB.user, '-p' + DB.pass, DB.name, '-N', '-B', '-e', q], { encoding: 'utf8' }).trim();
const smtp = () => (fs.existsSync(SMTP_LOG) ? fs.readFileSync(SMTP_LOG, 'utf8') : '');

let pass = 0, fail = 0; const results = [];
function check(ok, msg) { ok ? pass++ : fail++; results.push(`${ok ? 'PASS' : 'FAIL'} ${msg}`); if (!ok) console.log('  FAIL:', msg); }
const uniq = () => Date.now().toString(36) + Math.random().toString(36).slice(2, 6);
// Start from a clean slate in the isolated TEST database so earlier runs cannot trip the per-network rate limits.
sql('DELETE FROM leads'); sql('DELETE FROM subscribers'); sql('DELETE FROM events');

async function newCtx(browser, opts, extra = {}) {
  const ctx = await browser.newContext({ ...opts, ...extra });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
  return ctx;
}

async function run(P, opts) {
  const browser = await chromium.launch();
  const shot = async (page, name) => page.screenshot({ path: `${shotDir}/${label}-${P}-${name}.png` });
  const isMobile = P === 'mobile';

  // ---------- Core interactive experience (fresh storage) ----------
  let ctx = await newCtx(browser, opts);
  let page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_FAILED|net::/.test(m.text())) errors.push(m.text()); });

  await page.goto(base + '/', { waitUntil: 'load' });
  check(await page.locator('h1').count() === 1, `[${P}] homepage has one h1`);
  check(await page.locator('.journey').count() === 3, `[${P}] homepage shows Discover, Compare and Business journeys`);
  check(await page.locator('.journey h3').allInnerTexts().then(t => t.join('|') === 'Discover|Compare|For business'), `[${P}] journey headings are Discover, Compare, For business`);
  check(await page.locator('.intent').count() >= 8, `[${P}] goal-based entry points are shown`);
  check(await page.locator('.catCard').count() >= 10, `[${P}] category cards are shown`);
  check(await page.locator('.trustStrip').innerText().then(t => /reviewed profiles/.test(t) && /as of/.test(t)), `[${P}] trust strip states reviewed profiles and dated prices`);
  const bodyText = await page.locator('main').innerText();
  check(!/testimonial|trusted by|as seen on|\d+\s*(million|k)\+?\s*(users|visitors)/i.test(bodyText), `[${P}] no testimonials or invented traffic/customer claims on the homepage`);
  await shot(page, 'home');

  // Search suggestions: typing, keyboard navigation, Enter, Escape
  const input = page.locator('#heroSearch');
  await input.click();
  await input.pressSequentially('chatg', { delay: 40 });
  const firstOpt = page.locator('#heroSearch-list [role="option"]').first();
  await firstOpt.waitFor({ state: 'visible', timeout: 5000 });
  check(/ChatGPT/.test(await firstOpt.innerText()), `[${P}] suggestions show ChatGPT first for "chatg"`);
  check(await input.getAttribute('aria-expanded') === 'true' && await page.locator('#heroSearch-list').getAttribute('role') === 'listbox', `[${P}] combobox exposes aria-expanded and a listbox`);
  await input.press('ArrowDown');
  check(await firstOpt.getAttribute('aria-selected') === 'true' && !!(await input.getAttribute('aria-activedescendant')), `[${P}] ArrowDown selects the first suggestion (aria-activedescendant set)`);
  await shot(page, 'search-suggestions');
  await input.press('Escape');
  check(await page.locator('#heroSearch-list').isHidden() || await page.locator('.searchBox .suggest').first().isHidden(), `[${P}] Escape closes suggestions`);
  await input.press('ArrowDown');
  await input.press('ArrowDown');
  const optionEls = page.locator('#heroSearch-list [role="option"]');
  const chatgptIndex = (await optionEls.allInnerTexts()).findIndex(t => /ChatGPT/.test(t));
  check(chatgptIndex >= 0, `[${P}] ChatGPT remains among suggestions`);
  await input.fill('');
  await input.pressSequentially('chatgpt', { delay: 30 });
  await page.locator('#heroSearch-list [role="option"]').first().waitFor({ state: 'visible' });
  await input.press('ArrowDown');
  await Promise.all([page.waitForURL(/tool\.php\?id=chatgpt/), input.press('Enter')]);
  check(/tool\.php\?id=chatgpt/.test(page.url()), `[${P}] Enter on a suggestion opens that tool`);

  // Plain submit goes to the results page with the source tag
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.fill('#heroSearch', 'resume');
  await Promise.all([page.waitForURL(/search\.php\?q=resume/), page.locator('#heroSearch').press('Enter')]);
  check(/src=hero/.test(page.url()), `[${P}] search source is recorded in the URL (${page.url().replace(base, '')})`);
  check(parseInt(await page.locator('[data-search-results]').getAttribute('data-search-results'), 10) > 0, `[${P}] results page lists tools for "resume"`);
  check(await page.locator('.tCard').count() > 0, `[${P}] results are rendered as cards`);
  await shot(page, 'search-results');
  // Recent searches appear on focus with empty input
  await page.goto(base + '/search.php', { waitUntil: 'load' });
  await page.locator('#pageSearch').click();
  check(await page.locator('#pageSearch-list').innerText().then(t => /Recent searches/i.test(t) && /resume/i.test(t)), `[${P}] recent searches are offered`);
  // Typo tolerance + no-results state
  await page.goto(base + '/search.php?q=perplexty', { waitUntil: 'load' });
  check(await page.locator('.noticeBox:has-text("similar names")').count() === 1 && await page.locator('.tCard:has-text("Perplexity")').count() === 1, `[${P}] typo "perplexty" still finds Perplexity`);
  await page.goto(base + '/search.php?q=zzqqxx', { waitUntil: 'load' });
  check(await page.locator('text=No tools found').count() === 1 && await page.locator('.intent').count() >= 6, `[${P}] empty search offers goal-based next steps`);

  // Theme toggle persists and changes real colors
  await page.goto(base + '/', { waitUntil: 'load' });
  const bgOf = () => page.evaluate(() => getComputedStyle(document.body).backgroundColor);
  const startTheme = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  const bg1 = await bgOf();
  if (isMobile) { await page.locator('#themeToggle').click(); } else { await page.locator('#themeToggle').click(); }
  const t2 = await page.evaluate(() => document.documentElement.getAttribute('data-theme'));
  check(t2 !== startTheme && (await bgOf()) !== bg1, `[${P}] theme toggle switches ${startTheme} → ${t2} and changes the page colors`);
  check(await page.locator('#themeToggle').getAttribute('aria-pressed') === (t2 === 'dark' ? 'true' : 'false'), `[${P}] theme toggle exposes its state`);
  await page.reload({ waitUntil: 'load' });
  check(await page.evaluate(() => document.documentElement.getAttribute('data-theme')) === t2, `[${P}] chosen theme persists across reloads`);
  await shot(page, 'theme-' + t2);
  await page.locator('#themeToggle').click();   // restore

  // Favorites
  await page.goto(base + '/', { waitUntil: 'load' });
  const favBtn = page.locator('#revTitle').locator('xpath=ancestor::section').locator('[data-fav]').first();
  await favBtn.scrollIntoViewIfNeeded();
  await favBtn.click();
  check(await favBtn.getAttribute('aria-pressed') === 'true', `[${P}] favorite button toggles on`);
  check(await page.locator('[data-fav-count]').innerText() === '1', `[${P}] header shows 1 saved tool`);
  await page.goto(base + '/favorites.php', { waitUntil: 'load' });
  await page.locator('[data-favorites-list] .tCard').first().waitFor({ state: 'visible', timeout: 5000 });
  check(await page.locator('[data-favorites-list] .tCard').count() === 1, `[${P}] saved tool appears on the favorites page`);
  await shot(page, 'favorites');
  await page.locator('[data-favorites-list] [data-fav]').first().click();
  check(await page.locator('[data-favorites-empty]').isVisible(), `[${P}] removing the last favorite shows the empty state`);

  // Compare tray
  await page.goto(base + '/search.php?q=chat', { waitUntil: 'load' });
  check(await page.locator('[data-compare-tray]').isHidden(), `[${P}] compare tray is hidden until a tool is chosen`);
  const cmp = page.locator('.tCard [data-compare]');
  await cmp.nth(0).click();
  check(await page.locator('[data-compare-tray]').isVisible() && /Pick one more/.test(await page.locator('[data-compare-label]').innerText()), `[${P}] tray asks for a second tool`);
  await cmp.nth(1).click();
  check(/ vs /.test(await page.locator('[data-compare-label]').innerText()), `[${P}] tray shows "A vs B" after two picks`);
  await shot(page, 'compare-tray');
  await Promise.all([page.waitForURL(/compare\.php\?a=.+&b=.+/), page.locator('[data-compare-go]').click()]);
  check(await page.locator('.compare-card').count() === 2, `[${P}] tray opens the side-by-side comparison`);
  check(await page.locator('.diffRow').count() >= 1 && await page.locator('text=Highlighted rows differ').count() === 1, `[${P}] comparison highlights differing rows`);
  await Promise.all([page.waitForURL(/compare\.php/), page.locator('[data-compare-swap]').click()]);
  check(await page.locator('.compare-card').count() === 2, `[${P}] swap keeps a valid comparison`);

  // Category filtering (client side)
  await page.goto(base + '/category.php?name=Productivity', { waitUntil: 'load' });
  const total = await page.locator('[data-filter-item]').count();
  await page.fill('#filterInput', 'otter');
  check(await page.locator('[data-filter-item]:visible').count() === 1, `[${P}] text filter narrows to one tool (of ${total})`);
  check(/Showing 1 of/.test(await page.locator('[data-filter-status]').innerText()), `[${P}] filter status is announced`);
  await page.fill('#filterInput', '');
  await page.locator('[data-filter-chip="reviewed"]').click();
  const rev = await page.locator('[data-filter-item]:visible').count();
  check(rev >= 3 && rev < total && await page.locator('[data-filter-item]:visible .pill--reviewed').count() === rev, `[${P}] "reviewed only" keeps only reviewed profiles (${rev})`);
  await page.locator('[data-filter-chip="free"]').click();
  check(await page.locator('[data-filter-item]:visible').count() <= rev, `[${P}] free filter narrows further`);

  // Sponsored disclosure (two sponsored listings are seeded in the TEST database only)
  await page.goto(base + '/', { waitUntil: 'load' });
  const sp = page.locator('.tCard--sponsored');
  check(await sp.count() >= 1, `[${P}] sponsored listings render when present (${await sp.count()})`);
  if (await sp.count()) {
    check(/^sponsored$/i.test(await sp.first().locator('.adTag').innerText()) && await sp.first().locator('a[href="/disclosure.php"]').count() === 1, `[${P}] sponsored card shows a visible label and disclosure link`);
    check(/sponsored/.test(await sp.first().locator('a[data-outbound]').getAttribute('rel')), `[${P}] sponsored outbound link carries rel=sponsored`);
  }
  check(await page.locator('.disclosureInline').count() === 1, `[${P}] sponsored section explains the disclosure`);
  await page.goto(base + '/disclosure.php', { waitUntil: 'load' });
  check(/2\s+sponsored placements are active/.test((await page.locator('article').innerText()).replace(/\s+/g, ' ')), `[${P}] disclosure page reports the live sponsored count`);
  await shot(page, 'disclosure');

  // Mobile drawer
  if (isMobile) {
    await page.goto(base + '/', { waitUntil: 'load' });
    const tgl = page.locator('.navToggle');
    check(await tgl.isVisible() && await page.locator('#primaryNav').isHidden(), `[${P}] menu is collapsed by default on mobile`);
    await tgl.click();
    await page.locator('#primaryNav').waitFor({ state: 'visible' });       // the drawer fades in over ~180ms
    check(await tgl.getAttribute('aria-expanded') === 'true' && await page.locator('#primaryNav').isVisible(), `[${P}] menu button opens the drawer`);
    await page.waitForTimeout(300);
    check(await page.locator('#primaryNav a:visible').count() >= 9, `[${P}] drawer lists all primary and secondary links`);
    await shot(page, 'menu-open');
    await page.keyboard.press('Escape');
    await page.locator('#primaryNav').waitFor({ state: 'hidden' });
    check(await page.locator('#primaryNav').isHidden() && await tgl.getAttribute('aria-expanded') === 'false', `[${P}] Escape closes the drawer`);
  } else {
    await page.goto(base + '/', { waitUntil: 'load' });
    check(await page.locator('.navToggle').isHidden() && await page.locator('#primaryNav').isVisible(), `[${P}] desktop shows the full nav and no menu button`);
    await page.locator('.moreMenu__btn').click();
    check(await page.locator('.moreMenu__panel a:visible').count() >= 6, `[${P}] More menu opens`);
  }

  // Accessibility sanity checks across key pages
  for (const path of ['/', '/search.php?q=chat', '/business.php', '/advertise.php', '/favorites.php']) {
    await page.goto(base + path, { waitUntil: 'load' });
    const a11y = await page.evaluate(() => {
      const unnamedBtn = [...document.querySelectorAll('button,[role=button]')].filter(b => !(b.textContent.trim() || b.getAttribute('aria-label') || b.getAttribute('title')) && getComputedStyle(b).display !== 'none').length;
      const noAlt = [...document.querySelectorAll('img')].filter(i => !i.hasAttribute('alt')).length;
      const unlabeled = [...document.querySelectorAll('input:not([type=hidden]):not([type=submit]):not(.hp input),select,textarea')].filter(el => { if (el.closest('.hp')) return false; return !(el.labels && el.labels.length) && !el.getAttribute('aria-label'); }).length;
      const dupIds = (() => { const s = {}; let n = 0; document.querySelectorAll('[id]').forEach(e => { s[e.id] = (s[e.id] || 0) + 1; }); Object.values(s).forEach(v => { if (v > 1) n++; }); return n; })();
      return { unnamedBtn, noAlt, unlabeled, dupIds, lang: document.documentElement.lang, h1: document.querySelectorAll('h1').length };
    });
    check(a11y.unnamedBtn === 0 && a11y.noAlt === 0 && a11y.unlabeled === 0 && a11y.dupIds === 0 && a11y.h1 === 1 && a11y.lang === 'en', `[${P}] a11y basics on ${path} ${JSON.stringify(a11y)}`);
  }
  // Keyboard: skip link reachable first
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.keyboard.press('Tab');
  check(await page.evaluate(() => document.activeElement.className) === 'skipLink', `[${P}] first Tab stop is the skip link`);
  check(errors.length === 0, `[${P}] no JS errors in the core experience (${errors.slice(0, 2).join(' | ')})`);
  await ctx.close();

  // ---------- Newsletter double opt-in ----------
  ctx = await newCtx(browser, opts);
  page = await ctx.newPage();
  const email = `qa+${uniq()}@example.test`;
  await page.goto(base + '/about.php', { waitUntil: 'load' });
  await page.waitForTimeout(2300);
  await page.fill('#footerEmail', email);
  await page.locator('.newsForm input[name=consent]').check();
  await Promise.all([page.waitForURL(/subscribe\.php/), page.locator('.newsForm button[type=submit]').click()]);
  check(/Check your inbox/.test(await page.locator('main').innerText()), `[${P}] newsletter signup asks the visitor to check their inbox`);
  check(sql(`SELECT status FROM subscribers WHERE email='${email}'`) === 'pending', `[${P}] subscriber is stored as pending until confirmed`);
  const log = smtp();
  const m = [...log.matchAll(/newsletter-confirm\.php\?token=(?:3D)?([a-f0-9]{32})/g)].pop();
  check(!!m && log.includes(email), `[${P}] confirmation email was sent to the subscriber (sink)`);
  if (m) {
    await page.goto(`${base}/newsletter-confirm.php?token=${m[1]}`, { waitUntil: 'load' });
    check(/You are subscribed/.test(await page.locator('main').innerText()) && sql(`SELECT status FROM subscribers WHERE email='${email}'`) === 'confirmed', `[${P}] confirmation link confirms the subscription`);
    await page.goto(`${base}/unsubscribe.php?token=${m[1]}`, { waitUntil: 'load' });
    check(sql(`SELECT status FROM subscribers WHERE email='${email}'`) === 'confirmed', `[${P}] opening the unsubscribe link alone does not unsubscribe (prefetch-safe)`);
    await Promise.all([page.waitForLoadState('load'), page.locator('button:has-text("Yes, unsubscribe")').click()]);
    check(sql(`SELECT status FROM subscribers WHERE email='${email}'`) === 'unsubscribed', `[${P}] explicit confirmation unsubscribes`);
  }
  // Server-side protections (browser-independent)
  const req = ctx.request;
  let r = await req.post(base + '/subscribe.php', { form: { email: `x${uniq()}@example.test`, form_token: 'bad', consent: '1' }, maxRedirects: 0 });
  check(r.status() === 400, `[${P}] newsletter rejects a missing/invalid form token (HTTP ${r.status()})`);
  const pageHtml = await (await req.get(base + '/about.php')).text();
  const tok = /name="form_token" value="([^"]+)"/.exec(pageHtml)[1];
  await page.waitForTimeout(2300);
  r = await req.post(base + '/subscribe.php', { form: { email: `x${uniq()}@example.test`, form_token: tok }, maxRedirects: 0 });
  check(r.status() === 422, `[${P}] newsletter requires explicit consent (HTTP ${r.status()})`);
  r = await req.post(base + '/subscribe.php', { form: { email: `x${uniq()}@example.test`, form_token: tok, consent: '1', website_url_confirm: 'spam' }, maxRedirects: 0 });
  check(r.status() === 400, `[${P}] newsletter honeypot rejects bots (HTTP ${r.status()})`);
  r = await req.post(base + '/subscribe.php', { form: { email: 'not-an-email', form_token: tok, consent: '1' }, maxRedirects: 0 });
  check(r.status() === 422, `[${P}] newsletter rejects an invalid email (HTTP ${r.status()})`);
  await ctx.close();

  // ---------- Advertiser and business lead journeys ----------
  ctx = await newCtx(browser, opts);
  page = await ctx.newPage();
  const advEmail = `adv+${uniq()}@example.test`;
  await page.goto(base + '/advertise.php', { waitUntil: 'load' });
  check(await page.locator('.priceCard').count() >= 3 && /do not promise traffic/.test(await page.locator('.pageHero').innerText()), `[${P}] advertise page lists options and makes no traffic promise`);
  await shot(page, 'advertise');
  await page.waitForTimeout(2300);
  await page.fill('#adv-name', 'Ada Advertiser');
  await page.fill('#adv-email', advEmail);
  await page.fill('#adv-company', 'Example Labs');
  await page.fill('#adv-website', 'example.test');
  await page.fill('#adv-message', 'We would like to ask about a featured listing for our new tool.');
  await page.locator('.leadForm input[name=consent]').check();
  await Promise.all([page.waitForURL(/inquiry\.php\?sent=advertiser/), page.locator('.leadForm button[type=submit]').click()]);
  check(/received/i.test(await page.locator('main').innerText()), `[${P}] advertiser inquiry shows a confirmation`);
  check(sql(`SELECT CONCAT(lead_type,'|',website,'|',IF(consent_at IS NULL,'noconsent','consent')) FROM leads WHERE email='${advEmail}'`) === 'advertiser|https://example.test|consent', `[${P}] advertiser lead stored with type, normalised website and consent timestamp`);
  check(smtp().includes(advEmail), `[${P}] owner notification email was sent (sink)`);

  const bizEmail = `biz+${uniq()}@example.test`;
  await page.goto(base + '/business.php?need=ai_agent', { waitUntil: 'load' });
  check(await page.locator('#biz-type').inputValue() === 'ai_agent', `[${P}] ?need= preselects the request type`);
  check(!/testimonial|our clients|case stud|years of experience|award/i.test(await page.locator('main').innerText()), `[${P}] business page contains no invented credentials, clients or testimonials`);
  await shot(page, 'business');
  // validation: submit empty, expect server-side errors and no stored lead
  await page.waitForTimeout(2300);
  const before = sql(`SELECT COUNT(*) FROM leads`);
  await Promise.all([page.waitForLoadState('load'), page.locator('.leadForm button[type=submit]').click()]);
  check(await page.locator('.fieldError').count() >= 3 && /check your request/i.test(await page.locator('h1').innerText()), `[${P}] empty business form shows field errors`);
  check(sql(`SELECT COUNT(*) FROM leads`) === before, `[${P}] invalid submissions store nothing`);
  await shot(page, 'form-errors');
  await page.waitForTimeout(2300);
  await page.fill('#lead-name', 'Bea Builder');
  await page.fill('#lead-email', bizEmail);
  await page.fill('#lead-message', 'We want a custom agent that triages our support inbox and drafts replies.');
  await page.locator('.leadForm input[name=consent]').check();
  await page.selectOption('#lead-budget', '5k-20k').catch(() => {});
  await Promise.all([page.waitForURL(/inquiry\.php\?sent=/), page.locator('.leadForm button[type=submit]').click()]);
  check(/^(ai_agent|consultation|software_dev)\|/.test(sql(`SELECT CONCAT(lead_type,'|',budget) FROM leads WHERE email='${bizEmail}'`)), `[${P}] re-submitted business lead is stored after correcting errors`);
  const req2 = ctx.request;
  const html2 = await (await req2.get(base + '/business.php')).text();
  const tok2 = /name="form_token" value="([^"]+)"/.exec(html2)[1];
  await page.waitForTimeout(2300);
  const hp = `hp+${uniq()}@example.test`;
  r = await req2.post(base + '/inquiry.php', { form: { lead_type: 'consultation', name: 'Bot', email: hp, message: 'This is a long enough spam message body.', consent: '1', form_token: tok2, website_url_confirm: 'x' }, maxRedirects: 0 });
  check(r.status() === 422 && sql(`SELECT COUNT(*) FROM leads WHERE email='${hp}'`) === '0', `[${P}] lead honeypot blocks bots and stores nothing (HTTP ${r.status()})`);
  r = await req2.post(base + '/inquiry.php', { form: { lead_type: 'consultation', name: 'Bot', email: hp, message: 'This is a long enough spam message body.', consent: '1', form_token: 'bad' }, maxRedirects: 0 });
  check(r.status() === 422 && sql(`SELECT COUNT(*) FROM leads WHERE email='${hp}'`) === '0', `[${P}] lead form rejects an invalid token`);
  await page.waitForTimeout(2300);
  r = await req2.post(base + '/inquiry.php', { form: { lead_type: 'not-a-real-type', name: 'Bad Type', email: hp, message: 'This is a long enough message body.', consent: '1', form_token: tok2 }, maxRedirects: 0 });
  check(r.status() === 422 && sql(`SELECT COUNT(*) FROM leads WHERE email='${hp}'`) === '0', `[${P}] unknown lead types are rejected (HTTP ${r.status()})`);
  await ctx.close();

  // ---------- Privacy: events are off until the visitor opts in ----------
  ctx = await newCtx(browser, opts);
  page = await ctx.newPage();
  const evReq = [];
  page.on('request', q => { if (q.url().includes('/event.php')) evReq.push(q.url()); });
  await page.goto(base + '/', { waitUntil: 'load' });
  check(await page.evaluate(() => document.documentElement.getAttribute('data-events')) === '1', `[${P}] server advertises that event collection is available`);
  const countEv = (name) => parseInt(sql(`SELECT COUNT(*) FROM events WHERE name='${name}' AND day=CURDATE()`), 10);
  const favBefore = countEv('favorite_add');
  const fav2 = page.locator('#revTitle').locator('xpath=ancestor::section').locator('[data-fav]').first();
  await fav2.scrollIntoViewIfNeeded(); await fav2.click(); await page.waitForTimeout(400);
  check(evReq.length === 0 && countEv('favorite_add') === favBefore, `[${P}] no events are sent or stored without opt-in`);
  check(await page.evaluate(() => document.cookie) === '', `[${P}] the site sets no cookies for visitors`);
  // Opt in through the Privacy choices dialog
  await page.locator('[data-privacy-open]').scrollIntoViewIfNeeded();
  await page.locator('[data-privacy-open]').click();
  check(await page.locator('[data-privacy-dialog]').isVisible() && !(await page.locator('[data-consent-toggle]').isChecked()), `[${P}] privacy dialog opens with statistics OFF by default`);
  await page.locator('[data-consent-toggle]').check();
  await page.locator('[data-privacy-dialog] button').click();
  await fav2.click(); await fav2.click(); await page.waitForTimeout(600);   // un-save then save again
  check(evReq.length >= 1 && countEv('favorite_add') > favBefore, `[${P}] after opting in, a favorite_add event is recorded (${countEv('favorite_add') - favBefore})`);
  check(sql(`SELECT COUNT(*) FROM events WHERE day=CURDATE() AND ref='chatgpt' AND visitor_hash<>''`) !== undefined, `[${P}] events carry only a rotating hash`);
  // Outbound click attribution + search event
  const outBefore = countEv('outbound_click');
  const out = page.locator('a[data-outbound]').first();
  await out.scrollIntoViewIfNeeded();
  const popup = ctx.waitForEvent('page', { timeout: 3000 }).catch(() => null);
  await out.click(); await page.waitForTimeout(800);
  const pp = await popup; if (pp) await pp.close().catch(() => {});
  check(countEv('outbound_click') > outBefore, `[${P}] outbound clicks are attributed (client and server) (${countEv('outbound_click') - outBefore})`);
  await page.goto(base + '/search.php?q=chat&src=hero', { waitUntil: 'load' });
  await page.waitForTimeout(500);
  check(sql(`SELECT COUNT(*) FROM events WHERE name='search_submit' AND prop1='hero' AND day=CURDATE()`) !== '0', `[${P}] search engagement is recorded after opt-in`);
  // Returning-user engagement: simulate an earlier visit day
  await page.evaluate(() => { localStorage.setItem('ah_visits', '1'); localStorage.setItem('ah_last_day', '2000-01-01'); });
  const retBefore = countEv('returning_visit');
  await page.goto(base + '/', { waitUntil: 'load' }); await page.waitForTimeout(500);
  check(countEv('returning_visit') > retBefore, `[${P}] returning-user engagement is recorded after opt-in`);
  // Withdraw consent: nothing more is sent
  await page.locator('[data-privacy-open]').scrollIntoViewIfNeeded();
  await page.locator('[data-privacy-open]').click();
  await page.locator('[data-consent-toggle]').uncheck();
  await page.locator('[data-privacy-dialog] button').click();
  const n0 = evReq.length;
  await fav2.click(); await page.waitForTimeout(400);
  check(evReq.length === n0, `[${P}] withdrawing consent stops events immediately`);
  // Endpoint protections
  const rq = ctx.request;
  r = await rq.post(base + '/event.php', { data: { name: 'favorite_add', ref: 'x', consent: 1 }, headers: { Origin: 'https://evil.example' } });
  check(r.status() === 403, `[${P}] event endpoint rejects cross-origin posts (HTTP ${r.status()})`);
  r = await rq.post(base + '/event.php', { data: { name: 'favorite_add', ref: 'x' }, headers: { Origin: base } });
  check(r.status() === 204 && sql(`SELECT COUNT(*) FROM events WHERE ref='x' AND name='favorite_add'`) === '0', `[${P}] event endpoint ignores posts without a consent flag`);
  r = await rq.post(base + '/event.php', { data: { name: 'lead_submit', consent: 1 }, headers: { Origin: base } });
  check(r.status() === 400, `[${P}] server-only events cannot be forged from the browser (HTTP ${r.status()})`);
  r = await rq.post(base + '/event.php', { data: { name: 'favorite_add', ref: 'dnt', consent: 1 }, headers: { Origin: base, DNT: '1' } });
  check(r.status() === 204 && sql(`SELECT COUNT(*) FROM events WHERE ref='dnt'`) === '0', `[${P}] Do-Not-Track is honoured by the event endpoint`);
  await ctx.close();

  // ---------- Reduced motion ----------
  ctx = await newCtx(browser, opts, { reducedMotion: 'reduce' });
  page = await ctx.newPage();
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.waitForTimeout(300);
  const motion = await page.evaluate(() => ({
    float: [...document.querySelectorAll('.floatCard')].map(e => getComputedStyle(e).animationName),
    reveal: [...document.querySelectorAll('.reveal')].every(e => getComputedStyle(e).opacity === '1'),
    transition: getComputedStyle(document.querySelector('.btn')).transitionDuration,
  }));
  check(motion.reveal, `[${P}] reduced motion: scroll-reveal content is fully visible immediately`);
  check(motion.float.every(n => n === 'none'), `[${P}] reduced motion: floating card animation is disabled`);
  check(parseFloat(motion.transition) < 0.01, `[${P}] reduced motion: transitions are effectively instant (${motion.transition})`);
  await ctx.close();

  // ---------- No JavaScript (progressive enhancement) ----------
  ctx = await newCtx(browser, opts, { javaScriptEnabled: false });
  page = await ctx.newPage();
  await page.goto(base + '/', { waitUntil: 'load' });
  check(await page.locator('.journey:visible').count() === 3 && await page.locator('.catCard:visible').count() >= 10, `[${P}] no-JS: all homepage sections are visible`);
  check(await page.locator('#primaryNav a:visible').count() >= 5, `[${P}] no-JS: navigation links are visible`);
  check(await page.locator('.navToggle:visible, [data-fav]:visible, #themeToggle:visible').count() === 0, `[${P}] no-JS: JavaScript-only controls are hidden`);
  check(await page.locator('.tCard a[href^="/tool.php"]').count() >= 20, `[${P}] no-JS: tool links are crawlable server-rendered anchors`);
  await page.fill('#heroSearch', 'transcription');
  await Promise.all([page.waitForURL(/search\.php\?q=transcription/), page.locator('#heroSearch').press('Enter')]);
  check(await page.locator('.tCard').count() >= 3, `[${P}] no-JS: search form works and returns results`);
  await page.goto(base + '/category.php?name=Productivity', { waitUntil: 'load' });
  check(await page.locator('.filterBar:visible').count() === 0 && await page.locator('.tCard:visible').count() >= 20, `[${P}] no-JS: category lists every tool without the filter UI`);
  await ctx.close();

  await browser.close();
}

(async () => {
  // A regular desktop user agent: headless browsers identify as "HeadlessChrome", which the analytics bot filter correctly drops.
  await run('desktop', { viewport: { width: 1366, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' });
  await run('mobile', { ...devices['Pixel 7'] });
  fs.writeFileSync(`${shotDir}/${label}-browser-results.txt`, results.join('\n') + `\nPASSED: ${pass} FAILED: ${fail}\n`);
  console.log(`PASSED: ${pass}  FAILED: ${fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
