// Real-browser functional + layout tests (Playwright / Chromium), desktop and mobile.
// Usage: node tests/browser_test.js <baseUrl> <screenshotDir> [label]
// Runs against an isolated local server + test database only.
const { chromium, devices } = require('playwright');
const fs = require('fs');

const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, '');
const shotDir = process.argv[3] || './screenshots';
const label = process.argv[4] || 'after';
fs.mkdirSync(shotDir, { recursive: true });

let pass = 0, fail = 0;
const results = [];
function check(ok, msg) { ok ? pass++ : fail++; results.push(`${ok ? 'PASS' : 'FAIL'} ${msg}`); if (!ok) console.log('  FAIL:', msg); }

async function run(profileName, contextOpts) {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ ...contextOpts, deviceScaleFactor: contextOpts.deviceScaleFactor ?? 1 });
  // Block third-party requests (favicon CDN) so the test is deterministic and offline-safe.
  await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
  const page = await ctx.newPage();
  const consoleErrors = [];
  page.on('console', m => { if (m.type() === 'error' && !/ERR_FAILED|net::|Failed to load resource/.test(m.text())) consoleErrors.push(m.text()); });
  page.on('pageerror', e => consoleErrors.push(String(e)));
  // Sub-resource failures (images, CSS, JS, XHR) on our own host; the 404 test document itself is expected.
  page.on('response', r => { if (r.status() >= 400 && r.request().resourceType() !== 'document' && r.url().startsWith(base)) consoleErrors.push(`${r.status()} ${r.url()}`); });
  const P = profileName;

  const noHorizontalScroll = async (what) => {
    const { sw, cw } = await page.evaluate(() => ({ sw: document.documentElement.scrollWidth, cw: document.documentElement.clientWidth }));
    check(sw <= cw + 1, `[${P}] ${what}: no horizontal overflow (scrollWidth ${sw} vs ${cw})`);
  };
  const shot = async (name) => page.screenshot({ path: `${shotDir}/${label}-${P}-${name}.png`, fullPage: false });

  // Homepage
  let resp = await page.goto(base + '/', { waitUntil: 'load' });
  check(resp.status() === 200, `[${P}] homepage 200`);
  check(await page.locator('h1').count() === 1, `[${P}] homepage single h1`);
  await noHorizontalScroll('homepage');
  await shot('home');

  // Navigation
  const navLinks = await page.locator('nav[aria-label="Main navigation"] a').count();
  check(navLinks >= 7, `[${P}] main nav has links (${navLinks})`);
  const catNav = page.locator('nav[aria-label="Main navigation"] a[href="/categories.php"]');
  await catNav.scrollIntoViewIfNeeded();
  await Promise.all([page.waitForURL(/categories\.php/), catNav.click()]);
  check(/categories\.php/.test(page.url()), `[${P}] nav → categories works`);
  await noHorizontalScroll('categories');
  await shot('categories');

  // Category page via card link
  await Promise.all([page.waitForURL(/category\.php\?name=/), page.locator('a[href*="category.php?name=Writing"]').first().click()]);
  check(await page.locator('h1').innerText() === 'Writing AI tools', `[${P}] category page heading`);
  check(await page.locator('nav.breadcrumbs').count() === 1, `[${P}] category breadcrumbs visible`);
  await noHorizontalScroll('category');
  await shot('category');

  // Tool page via card
  await Promise.all([page.waitForURL(/tool\.php\?id=/), page.locator('.grid .card h2 a').first().click()]);
  check(/tool\.php\?id=/.test(page.url()), `[${P}] category → tool navigation`);
  check(await page.locator('h1').count() === 1, `[${P}] tool page h1`);
  await noHorizontalScroll('tool');

  // Search (homepage form)
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.fill('#homeSearch', 'chat');
  await Promise.all([page.waitForURL(/\?q=chat/), page.locator('form.heroSearch button[type=submit]').click()]);
  const resultsText = await page.locator('section.card h2:has-text("Browse tools") + p').innerText();
  check(/Showing \d+ results/.test(resultsText) && !/Showing 0 results/.test(resultsText), `[${P}] search returns results (${resultsText})`);
  check(await page.locator('meta[name=robots][content*="noindex"]').count() === 1, `[${P}] search results are noindex`);
  // Category filter
  await page.goto(base + '/', { waitUntil: 'load' });
  await page.selectOption('#homeCat', 'Coding');
  await Promise.all([page.waitForURL(/cat=Coding/), page.locator('form.heroSearch button[type=submit]').click()]);
  const badges = await page.locator('section.card:has(h2:has-text("Browse tools")) .badges a.badge').allInnerTexts();
  check(badges.length > 0 && badges.every(b => b === 'Coding'), `[${P}] category filter returns only Coding (${badges.length})`);

  // Tool page: ChatGPT, vote via AJAX
  await page.goto(base + '/tool.php?id=chatgpt', { waitUntil: 'load' });
  await shot('tool-chatgpt');
  const likeBox = page.locator('.toolStatsAjax').first();
  const before = parseInt(await likeBox.locator('.js-like-count').innerText(), 10);
  const [voteResp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('vote-tool-ajax.php')),
    likeBox.locator('button[data-vote=up]').click(),
  ]);
  const voteJson = await voteResp.json();
  check(voteResp.status() === 200 && voteJson.success === true, `[${P}] like vote saved via AJAX`);
  await page.waitForTimeout(200);
  const after = parseInt(await likeBox.locator('.js-like-count').innerText(), 10);
  check(after === before + 1, `[${P}] like counter updated ${before} → ${after}`);

  // Generic-profile tool shows honesty notice
  await page.goto(base + '/tool.php?id=monica', { waitUntil: 'load' });
  check(await page.locator('.noticeBox').count() === 1, `[${P}] generic tool profile shows verification notice`);
  await shot('tool-generic');

  // Rating form
  await page.goto(base + '/tool.php?id=chatgpt', { waitUntil: 'load' });
  await page.selectOption('#rateStars', '5');
  const [rateResp] = await Promise.all([page.waitForNavigation(), page.locator('form[action="/rate.php"] button[type=submit]').click()]);
  check(rateResp && rateResp.status() < 400, `[${P}] rating form submits (status ${rateResp && rateResp.status()})`);

  // Comment form
  await page.goto(base + '/tool.php?id=chatgpt', { waitUntil: 'load' });
  const commentText = `Browser test comment ${P} ${Date.now()}`;
  await page.fill('#commentName', 'QA Bot');
  await page.fill('#commentText', commentText);
  await Promise.all([page.waitForURL(/tool\.php\?id=chatgpt/), page.locator('form[action="/submit-comment.php"] button').click()]);
  check(await page.locator(`text=${commentText}`).count() === 1, `[${P}] comment posted and displayed`);

  // Outbound "Open tool" link → click tracking + redirect.php (third-party destination blocked)
  const [trackResp] = await Promise.all([
    page.waitForResponse(r => r.url().includes('track-click.php')),
    page.locator('a[href*="redirect.php?id=chatgpt"]').first().click(),
  ]);
  check(trackResp.status() === 200, `[${P}] click tracking endpoint OK`);
  await page.waitForTimeout(300);
  for (const p of ctx.pages()) if (p !== page) await p.close();

  // Compare
  await page.goto(base + '/compare.php', { waitUntil: 'load' });
  await page.selectOption('#toolA', 'chatgpt');
  await page.selectOption('#toolB', 'claude');
  await Promise.all([page.waitForURL(/compare\.php\?a=chatgpt&b=claude/), page.locator('.compare-form-actions button').click()]);
  check((await page.locator('h1').innerText()).includes('ChatGPT vs Claude'), `[${P}] compare shows ChatGPT vs Claude`);
  check(await page.locator('.compare-card').count() === 2, `[${P}] compare renders two cards`);
  check(await page.locator('text=Best overall value').count() === 0, `[${P}] no unsupported "Best overall value" claim`);
  await noHorizontalScroll('compare');
  await shot('compare');

  // ---- Phase 2 journeys ----
  // Guides: nav → hub → guide, comparison table readable without page-level horizontal scroll
  const guidesNav = page.locator('nav[aria-label="Main navigation"] a[href="/guides.php"]');
  check(await guidesNav.count() === 1, `[${P}] main nav links to Guides`);
  await page.goto(base + '/', { waitUntil: 'load' });
  await guidesNav.scrollIntoViewIfNeeded();
  await Promise.all([page.waitForURL(/guides\.php/), guidesNav.click()]);
  check(await page.locator('.grid .card').count() >= 4, `[${P}] guides hub lists at least 4 guides`);
  await noHorizontalScroll('guides hub');
  await Promise.all([page.waitForURL(/guide\.php\?slug=/), page.locator('.grid .card h2 a').first().click()]);
  check(await page.locator('h1').count() === 1, `[${P}] guide has one h1`);
  check(await page.locator('table.compareTable tbody tr').count() >= 3, `[${P}] guide comparison table has rows`);
  check(await page.locator('.noticeBox:has-text("not benchmarked")').count() === 1, `[${P}] guide discloses that quality is not benchmarked`);
  await noHorizontalScroll('guide page');
  await shot('guide');
  const tableScrolls = await page.locator('.tableWrap').first().evaluate(e => e.scrollWidth >= e.clientWidth);
  check(tableScrolls, `[${P}] comparison table sits in a scroll container`);
  await Promise.all([page.waitForURL(/tool\.php\?id=/), page.locator('table.compareTable tbody th a').first().click()]);
  check(await page.locator('section.profileBox').count() === 1, `[${P}] guide row opens a reviewed tool profile`);

  // Reviewed tool page: evidence block, expandable sources, alternatives link
  await page.goto(base + '/tool.php?id=chatgpt', { waitUntil: 'load' });
  check(await page.locator('.profileBox .factList').count() === 1, `[${P}] reviewed tool shows facts list`);
  await page.locator('.profileBox details summary').click();
  check(await page.locator('.profileBox details a[href^="https://"]').first().isVisible(), `[${P}] sources expand and show vendor links`);
  await noHorizontalScroll('reviewed tool');
  await shot('tool-reviewed');
  const altLink = page.locator('a:has-text("Full comparison of ChatGPT alternatives")');
  await altLink.scrollIntoViewIfNeeded();
  await Promise.all([page.waitForURL(/alternatives\.php\?tool=chatgpt/), altLink.click()]);
  check((await page.locator('h1').innerText()).includes('ChatGPT alternatives'), `[${P}] tool page → alternatives page`);
  check(await page.locator('table.compareTable').count() === 1, `[${P}] alternatives page has comparison table`);
  await noHorizontalScroll('alternatives');
  await shot('alternatives');
  await Promise.all([page.waitForURL(/compare\.php\?a=/), page.locator('a:has-text(" vs ")').first().click()]);
  check(await page.locator('.compare-card').count() === 2, `[${P}] alternatives page → head-to-head comparison`);

  // Subcategory navigation
  await page.goto(base + '/category.php?name=Productivity', { waitUntil: 'load' });
  const chip = page.locator('nav[aria-label="Subcategories"] a:has-text("Meetings & transcription")');
  await chip.scrollIntoViewIfNeeded();
  await Promise.all([page.waitForURL(/sub=meetings-transcription/), chip.click()]);
  check((await page.locator('h1').innerText()).startsWith('Meetings & transcription'), `[${P}] subcategory chip opens subcategory page`);
  check(await page.locator('.grid .card').count() >= 4, `[${P}] subcategory lists tools`);
  await noHorizontalScroll('subcategory');
  await shot('subcategory');

  // Methodology + legacy redirect
  await page.goto(base + '/methodology.php', { waitUntil: 'load' });
  check((await page.locator('h1').innerText()).includes('reviews AI tools'), `[${P}] methodology page renders`);
  await noHorizontalScroll('methodology');
  resp = await page.goto(base + '/category.php?name=SEO', { waitUntil: 'load' });
  check(/name=Marketing/.test(page.url()), `[${P}] legacy SEO category redirects to Marketing`);

  // Comparisons hub links
  await page.goto(base + '/comparisons.php', { waitUntil: 'load' });
  check(await page.locator('.grid .card').count() >= 6, `[${P}] comparisons hub lists matchups`);

  // Leaderboard / top
  for (const path of ['/top.php', '/leaderboard.php', '/blog.php', '/post.php?slug=best-ai-tools-2026', '/ai-use-case-explorer.php', '/ai-use-case.php?slug=write-blog-posts']) {
    resp = await page.goto(base + path, { waitUntil: 'load' });
    check(resp.status() === 200, `[${P}] ${path} 200`);
    await noHorizontalScroll(path);
  }
  await page.goto(base + '/leaderboard.php'); await shot('leaderboard');

  // Contact form (local SMTP sink only)
  await page.goto(base + '/contact.php', { waitUntil: 'load' });
  await page.fill('input[name=name]', 'QA Bot');
  await page.fill('input[name=email]', 'qa@example.test');
  await page.fill('input[name=subject]', 'Browser test');
  await page.fill('textarea[name=message]', 'This is an automated browser test message.');
  await Promise.all([page.waitForURL(/contact\.php\?(sent|err)=/), page.locator('form[action="contact_send.php"] button[type=submit]').click()]);
  check(/sent=1/.test(page.url()), `[${P}] contact form sent (${page.url().replace(base, '')})`);

  // Submit tool form
  await page.goto(base + '/submit.php', { waitUntil: 'load' });
  await page.fill('input[name=name]', 'QA Test Tool');
  await page.selectOption('select[name=category]', { index: 1 });
  await page.fill('input[name=website]', 'https://example.test');
  await page.fill('input[name=short]', 'A tool submitted by the automated browser test.');
  await Promise.all([page.waitForNavigation(), page.locator('form[method=post] button[type=submit]').first().click()]);
  check(await page.locator('text=/submitted|received|thank/i').count() > 0, `[${P}] submit-tool form accepted`);

  // 404 page
  resp = await page.goto(base + '/definitely-missing-page', { waitUntil: 'load' });
  check(resp.status() === 404, `[${P}] unknown URL returns 404`);
  await shot('404');

  // Mobile: nav must remain usable (links reachable)
  if (P === 'mobile') {
    await page.goto(base + '/', { waitUntil: 'load' });
    const vis = await page.locator('nav[aria-label="Main navigation"]').isVisible();
    check(vis, `[${P}] main nav visible on mobile`);
    const headerH = await page.locator('header.nav').evaluate(e => e.getBoundingClientRect().height);
    check(headerH <= 200, `[${P}] mobile header height ${Math.round(headerH)}px ≤ 200px`);
    const tapTargets = await page.$$eval('nav[aria-label="Main navigation"] > a, nav[aria-label="Main navigation"] button', els => els.map(e => e.getBoundingClientRect().height));
    check(tapTargets.every(h => h >= 40), `[${P}] nav tap targets ≥40px (${tapTargets.map(Math.round).join(',')})`);
  }

  check(consoleErrors.length === 0, `[${P}] no JS console errors (${consoleErrors.slice(0, 3).join(' | ')})`);
  await browser.close();
}

(async () => {
  await run('desktop', { viewport: { width: 1366, height: 900 } });
  await run('mobile', { ...devices['Pixel 7'] });
  fs.writeFileSync(`${shotDir}/${label}-browser-results.txt`, results.join('\n') + `\nPASSED: ${pass} FAILED: ${fail}\n`);
  console.log(`PASSED: ${pass}  FAILED: ${fail}`);
  process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(2); });
