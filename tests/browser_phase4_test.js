// Phase 4 browser tests: recommendation wizard, problem pages, evidence/feature matrix, advertiser page, newsletter archive and changelog.
// Usage: node tests/browser_phase4_test.js <baseUrl> <screenshotDir> [label]
const { chromium, devices } = require('playwright');
const fs = require('fs');

const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, '');
const shotDir = process.argv[3] || './screenshots';
const label = process.argv[4] || 'p4';
fs.mkdirSync(shotDir, { recursive: true });
let pass = 0, fail = 0; const results = [];
function check(ok, msg) { ok ? pass++ : fail++; results.push(`${ok ? 'PASS' : 'FAIL'} ${msg}`); if (!ok) console.log('  FAIL:', msg); }

async function newCtx(browser, opts, extra = {}) {
  const ctx = await browser.newContext({ ...opts, ...extra });
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
  return ctx;
}
const noHScroll = (page) => page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1);

async function run(P, opts) {
  const browser = await chromium.launch();
  const shot = async (page, name) => page.screenshot({ path: `${shotDir}/${label}-${P}-${name}.png` });
  const isMobile = P === 'mobile';
  let ctx = await newCtx(browser, opts);
  let page = await ctx.newPage();
  const errors = [];
  page.on('pageerror', e => errors.push(String(e)));
  page.on('console', m => { if (m.type() === 'error' && !/Failed to load resource|ERR_FAILED|net::/.test(m.text())) errors.push(m.text()); });

  // ---------- Recommendation wizard ----------
  let r = await page.goto(base + '/recommend.php', { waitUntil: 'load' });
  check(r.status() === 200 && await page.locator('h1').count() === 1, `[${P}] wizard page loads with one h1`);
  check(await page.locator('fieldset').count() === 6 && await page.locator('fieldset legend').count() === 6, `[${P}] six questions, each in a fieldset with a legend`);
  check(await page.locator('meta[name=robots]').getAttribute('content').then(c => /index/.test(c) && !/noindex/.test(c)), `[${P}] the empty wizard is indexable`);
  check(await noHScroll(page), `[${P}] wizard form has no horizontal scrolling`);
  // The wizard shows one question at a time (stepper). Required questions block "Next"; the form still submits as a plain GET.
  const nextBtn = page.locator('[data-wiz-next]');
  await nextBtn.click();                                   // role (optional) -> objective
  await nextBtn.click();                                   // objective is required
  check(await page.locator('.wizErr:visible').count() === 1 && page.url().indexOf('go=1') === -1, `[${P}] required questions block an empty step`);
  // Keyboard: radios reachable and selectable with the keyboard
  await page.locator('#objective-meetings').focus();
  await page.keyboard.press('Space');
  check(await page.locator('#objective-meetings').isChecked(), `[${P}] a goal can be chosen with the keyboard`);
  await nextBtn.click();                                   // -> budget
  await page.locator('label[for=budget-free_only]').click();
  await nextBtn.click();                                   // -> experience
  await nextBtn.click();                                   // -> team
  await page.locator('label[for=team-small]').click();
  await nextBtn.click();                                   // -> integrations
  await page.locator('label[for=int-zoom]').click();
  await page.locator('fieldset', { has: page.locator('#role') }).evaluate(() => {});   // role select stays in the DOM
  await page.selectOption('#role', 'manager', { force: true });
  await shot(page, 'wizard-form');
  await Promise.all([page.waitForURL(/recommend\.php\?.*go=1/), page.locator('form[data-wizard] button[type=submit]').click()]);
  await page.locator('#results').waitFor({ state: 'visible' });
  const items = await page.locator('.recItem').count();
  check(items >= 3 && items <= 5, `[${P}] three to five recommendations are shown (${items})`);
  check(await page.locator('.recItem').first().locator('h4:has-text("Why it matches")').count() === 1, `[${P}] each recommendation explains why it matches`);
  check(await page.locator('.recItem .evBadge').count() === items, `[${P}] each recommendation shows its evidence level`);
  check(await page.locator('.recItem h4:has-text("Pricing, as stated in sources")').count() === items, `[${P}] each recommendation shows pricing as stated in sources`);
  check(await page.locator('#wfTitle').count() === 1 && await page.locator('#wfTitle').locator('xpath=..').locator('li').count() >= 5, `[${P}] a getting-started workflow is shown`);
  check(await page.locator('meta[name=robots]').getAttribute('content').then(c => /noindex/.test(c)), `[${P}] result URLs are noindex`);
  check(await page.locator('details:has-text("How your answers were used")').count() === 1, `[${P}] the scoring explanation is available`);
  const resText = await page.locator('#results').innerText();
  check(!/sponsored|affiliate link/i.test(resText.replace(/Not used:[^\n]*/g, '')), `[${P}] results contain no paid placements`);
  check(await noHScroll(page), `[${P}] results have no horizontal scrolling`);
  const focusOk = await page.evaluate(() => { const el = document.querySelector('#results'); return !!el && el.getAttribute('tabindex') === '-1'; });
  check(focusOk, `[${P}] results container can receive focus after submit`);
  await shot(page, 'wizard-results');
  // Honest empty case
  await page.goto(base + '/recommend.php?go=1&objective=code&budget=free_only&experience=beginner', { waitUntil: 'load' });
  check(await page.locator('.recItem').count() >= 1 && /30 minutes/.test(await page.locator('#wfTitle').locator('xpath=..').innerText()), `[${P}] beginner answers add a simple first step`);
  await page.goto(base + '/recommend.php?go=1&objective=%3Cscript%3Ealert(1)%3C%2Fscript%3E&budget=free_only', { waitUntil: 'load' });
  check(/Choose what you want to do/.test(await page.locator('main').innerText()) && await page.locator('main script').count() === 0, `[${P}] hostile input is rejected and not rendered as markup`);

  // ---------- Problem pages ----------
  r = await page.goto(base + '/solutions.php', { waitUntil: 'load' });
  check(r.status() === 200 && await page.locator('.catGrid .catTile').count() >= 6, `[${P}] problem hub lists problems`);
  await page.locator('.catTile h2 a').first().click();
  await page.waitForLoadState('load');
  check(await page.locator('h1').count() === 1 && await page.locator('#lookFor').count() === 1 && await page.locator('.tCard__why').count() >= 1, `[${P}] problem page shows criteria and "why this result" for each tool`);
  check(await page.locator('.tCard__why').first().innerText().then(t => /Why this result:/.test(t) && t.length > 30), `[${P}] relevance explanations have content`);
  r = await page.goto(base + '/solutions.php?problem=answer-customer-questions', { waitUntil: 'load' });
  check(await page.locator('meta[name=robots]').getAttribute('content').then(c => /noindex/.test(c)), `[${P}] a problem page with fewer than three reviewed tools is noindex`);
  r = await page.goto(base + '/solutions.php?problem=transcribe-meetings-and-interviews', { waitUntil: 'load' });
  check(await page.locator('meta[name=robots]').getAttribute('content').then(c => !/noindex/.test(c)), `[${P}] a problem page with at least three reviewed tools is indexable`);
  check(await noHScroll(page), `[${P}] problem page has no horizontal scrolling`);
  await shot(page, 'problem-page');
  r = await page.goto(base + '/solutions.php?problem=nope', { waitUntil: 'load' });
  check(r.status() === 404, `[${P}] unknown problem returns 404`);

  // ---------- Search explanations ----------
  await page.goto(base + '/search.php?q=transcribe', { waitUntil: 'load' });
  check(await page.locator('.tCard__why').count() >= 3 && /Related term/.test(await page.locator('main').innerText()), `[${P}] search results explain why and label related terms`);

  // ---------- Comparison: evidence + feature matrix ----------
  await page.goto(base + '/compare.php?a=otter-ai&b=fireflies-ai', { waitUntil: 'load' });
  check(await page.locator('table.matrix').count() >= 1 && await page.locator('table.matrix th[scope=row]').count() >= 6, `[${P}] comparison has a feature matrix with row headers`);
  check(await page.locator('table.matrix .cellState.s-documented').count() >= 2, `[${P}] documented features are marked`);
  await page.goto(base + '/compare.php?a=otter-ai&b=circleback-ai', { waitUntil: 'load' });
  const unreviewed = await page.locator('table.matrix td:has-text("Not reviewed")').count();
  check(unreviewed >= 1 && await page.locator('table.matrix td:has-text("Unknown")').count() + unreviewed >= 3, `[${P}] unknown or unreviewed values are explicit, never blank`);
  check((await page.locator('table.matrix .cellState').allInnerTexts()).every(t => t.trim() !== 'No' && t.trim() !== 'None'), `[${P}] no matrix cell states a bare "No" for a feature (only Documented, Unknown, Not reviewed, None listed)`);
  check(await noHScroll(page), `[${P}] comparison page has no horizontal scrolling`);
  if (isMobile) {
    const display = await page.locator('table.matrix tbody tr').first().evaluate(el => getComputedStyle(el).display);
    check(display === 'block', `[mobile] the matrix stacks into readable cards on narrow screens`);
    check(await page.locator('table.matrix td').first().evaluate(el => getComputedStyle(el, '::before').content).then(c => c !== 'none' && c !== 'normal'), `[mobile] stacked cells carry their tool name as a label`);
  }
  await shot(page, 'compare-matrix');

  // ---------- Tool page evidence ----------
  await page.goto(base + '/tool.php?id=otter-ai', { waitUntil: 'load' });
  check(await page.locator('.evPanel').count() === 1 && /Documented capabilities/.test(await page.locator('.evPanel').innerText()), `[${P}] tool profile shows the evidence panel`);
  check(await page.locator('.evPanel').innerText().then(t => /Not yet verified by an editor/.test(t)), `[${P}] the panel says plainly that no editor has verified the profile`);
  await page.goto(base + '/alternatives.php?tool=otter-ai', { waitUntil: 'load' });
  check(await page.locator('p.altWhy').count() >= 2, `[${P}] alternatives explain why they are listed`);

  // ---------- Advertiser page ----------
  await page.goto(base + '/advertise.php', { waitUntil: 'load' });
  const advText = await page.locator('main').innerText();
  check(await page.locator('table.pkgTable tbody tr').count() === 6, `[${P}] package comparison lists six packages`);
  check(!/€|\b199\b|\b499\b/.test(advText), `[${P}] no unapproved euro prices are public`);
  check(await page.locator('details.faqItem').count() >= 6, `[${P}] advertiser FAQ is present`);
  await page.locator('details.faqItem summary').first().click();
  check(await page.locator('details.faqItem').first().evaluate(d => d.open), `[${P}] FAQ items open and close`);
  check(await page.locator('#adv-package').count() === 1 && await page.locator('#adv-goal').count() === 1, `[${P}] qualification fields are on the form`);
  check(!/trusted by|testimonial|guaranteed|case stud/i.test(advText), `[${P}] no testimonials, guarantees or case studies`);
  check(await noHScroll(page), `[${P}] advertiser page has no horizontal scrolling`);
  await shot(page, 'advertise');
  // Deep link + attribution carried into the form
  await page.goto(base + '/advertise.php?package=newsletter_sponsor&ref=tool-page&tool=otter-ai#request', { waitUntil: 'load' });
  check(await page.locator('#adv-package').inputValue() === 'newsletter_sponsor' && await page.locator('input[name=attr_source]').inputValue() === 'tool-page' && await page.locator('input[name=ref_tool]').inputValue() === 'otter-ai', `[${P}] deep links preselect the package and carry attribution`);

  // ---------- Pages added by P4/P5 ----------
  for (const [url, h1] of [['/changelog.php', /updated|changes|change log/i], ['/newsletter-archive.php', /newsletter|archive/i]]) {
    r = await page.goto(base + url, { waitUntil: 'load' });
    check(r.status() === 200 && await page.locator('h1').first().innerText().then(t => h1.test(t)), `[${P}] ${url} loads`);
    check(await noHScroll(page), `[${P}] ${url} has no horizontal scrolling`);
  }

  check(errors.length === 0, `[${P}] no JavaScript errors on any page (${errors.slice(0, 2).join(' | ')})`);
  await ctx.close();

  // ---------- No JavaScript ----------
  ctx = await newCtx(browser, opts, { javaScriptEnabled: false });
  page = await ctx.newPage();
  await page.goto(base + '/recommend.php?go=1&objective=research&budget=flexible', { waitUntil: 'load' });
  check(await page.locator('.recItem:visible').count() >= 3, `[${P}] no-JS: the wizard works server-side`);
  await page.goto(base + '/compare.php?a=chatgpt&b=claude', { waitUntil: 'load' });
  check(await page.locator('table.matrix:visible').count() >= 1, `[${P}] no-JS: the feature matrix is visible`);
  await ctx.close();

  await browser.close();
}

(async () => {
  await run('desktop', { viewport: { width: 1366, height: 900 }, userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/130.0.0.0 Safari/537.36' });
  await run('mobile', { ...devices['Pixel 7'] });
  fs.writeFileSync(`${shotDir}/${label}-browser-results.txt`, results.join('\n') + `\nPASSED: ${pass} FAILED: ${fail}\n`);
  console.log(`PASSED: ${pass}  FAILED: ${fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
