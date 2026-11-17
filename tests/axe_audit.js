// Runs the axe-core accessibility engine (the rules Lighthouse uses) in a real browser on key pages, in light and dark themes.
// Usage: AXE_PATH=/path/to/axe.min.js node tests/axe_audit.js <baseUrl> [--strict]
const { chromium, devices } = require('playwright');
const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, '');
const axePath = process.env.AXE_PATH || require.resolve('axe-core/axe.min.js');
const pages = ['/', '/search.php?q=transcribe', '/category.php?name=Productivity', '/tool.php?id=chatgpt', '/compare.php?a=chatgpt&b=claude', '/business.php', '/advertise.php',
  '/guide.php?slug=best-ai-chatbots-and-assistants', '/favorites.php', '/disclosure.php', '/categories.php', '/leaderboard.php', '/top.php',
  '/recommend.php', '/recommend.php?go=1&objective=meetings&budget=free_only&team=small&integrations%5B%5D=zoom', '/solutions.php', '/solutions.php?problem=transcribe-meetings-and-interviews',
  '/compare.php?a=otter-ai&b=fireflies-ai', '/pricing-comparison.php?group=meetings-transcription', '/changelog.php', '/alternatives.php?tool=otter-ai', '/newsletter-archive.php'];
(async () => {
  const browser = await chromium.launch(); let total = 0; const byRule = {};
  for (const theme of ['light', 'dark']) for (const [prof, opts] of [['desktop', { viewport: { width: 1366, height: 900 } }], ['mobile', devices['Pixel 7']]]) {
    const ctx = await browser.newContext({ ...opts, colorScheme: theme }); await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
    await ctx.addInitScript(t => { try { localStorage.setItem('ah_theme', t); } catch (e) {} }, theme);
    const page = await ctx.newPage();
    for (const path of pages) {
      await page.goto(base + path, { waitUntil: 'load' }); await page.waitForTimeout(2700);
      await page.addScriptTag({ path: axePath });
      const res = await page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa', 'best-practice'] }, resultTypes: ['violations'] })).violations.map(v => ({ id: v.id, impact: v.impact, n: v.nodes.length, sample: v.nodes.slice(0, 2).map(n => n.target.join(' ') + ' :: ' + (n.failureSummary || '').split('\n')[1]) })));
      for (const v of res) { if (['color-contrast', 'target-size', 'aria-allowed-attr', 'label', 'button-name', 'link-name', 'image-alt', 'heading-order', 'duplicate-id', 'duplicate-id-aria', 'aria-valid-attr-value', 'aria-required-children', 'list'].includes(v.id) || v.impact === 'critical' || v.impact === 'serious') { total += v.n; byRule[v.id] = (byRule[v.id] || 0) + v.n; console.log(`${theme}/${prof} ${path}: ${v.id} (${v.impact}) x${v.n} ${v.sample.join(' | ')}`); } }
    }
    await ctx.close();
  }
  await browser.close();
  console.log(`\naxe violations on key rules/impacts: ${total} ${JSON.stringify(byRule)}`);
  process.exit(total && process.argv.includes('--strict') ? 1 : 0);
})();
