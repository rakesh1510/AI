// Captures screenshots of key pages in light/dark themes on desktop and mobile.
// Usage: node tests/capture_screens.js <baseUrl> <outDir> [label] [pageFilter]
const { chromium, devices } = require('playwright');
const fs = require('fs');
const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, '');
const out = process.argv[3] || './screens'; const label = process.argv[4] || 'p3'; const filter = process.argv[5] || '';
fs.mkdirSync(out, { recursive: true });
const pages = [
  ['home', '/'], ['search', '/search.php?q=transcribe'], ['tool', '/tool.php?id=chatgpt'], ['category', '/category.php?name=Productivity&sub=meetings-transcription'],
  ['compare', '/compare.php?a=chatgpt&b=claude'], ['business', '/business.php'], ['advertise', '/advertise.php'], ['guide', '/guide.php?slug=best-ai-chatbots-and-assistants'],
  ['favorites', '/favorites.php'], ['categories', '/categories.php'], ['disclosure', '/disclosure.php'],
].filter(p => !filter || p[0] === filter);
(async () => {
  const browser = await chromium.launch();
  for (const theme of ['light', 'dark']) for (const [prof, opts, full] of [['desktop', { viewport: { width: 1366, height: 900 } }, false], ['tablet', devices['iPad Mini'], false], ['mobile', devices['Pixel 7'], false]]) {
    const ctx = await browser.newContext({ ...opts, colorScheme: theme, deviceScaleFactor: prof === 'desktop' ? 1 : 1 });
    await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort());
    const page = await ctx.newPage();
    await page.addInitScript(t => { try { localStorage.setItem('ah_theme', t); } catch (e) {} }, theme);
    for (const [name, path] of pages) {
      await page.goto(base + path, { waitUntil: 'load' });
      await page.waitForTimeout(2800); // lets the reveal fallback run so full-page captures show everything
      await page.screenshot({ path: `${out}/${label}-${theme}-${prof}-${name}.png`, fullPage: name === 'home' ? false : false });
      if (name === 'home') await page.screenshot({ path: `${out}/${label}-${theme}-${prof}-home-full.png`, fullPage: true });
    }
    await ctx.close();
  }
  await browser.close();
  console.log('done');
})();
