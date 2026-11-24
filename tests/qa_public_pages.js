// Public-page QA over the whole inventory (tests/public_pages.json).
//   node tests/qa_public_pages.js check <baseUrl>                       layout/overflow/typography checks at 1920/1440/1280/768/390/360, light + dark
//   node tests/qa_public_pages.js shots <baseUrl> <outDir> [widths] [themes] [jpegQuality]   full-page screenshots
// Runs against a local `php -S`. No database is needed; pages that need one render their empty state.
const { chromium } = require('playwright');
const fs = require('fs'); const path = require('path');
const pages = JSON.parse(fs.readFileSync(path.join(__dirname, 'public_pages.json'), 'utf8'));
const [, , mode = 'check', baseArg = 'http://127.0.0.1:8123', outDir = '', widthsArg = '', themesArg = '', qArg = '60'] = process.argv;
const base = baseArg.replace(/\/$/, '');
const allWidths = [1920, 1440, 1280, 768, 390, 360];
const widths = widthsArg ? widthsArg.split(',').map(Number) : allWidths;
const themes = themesArg ? themesArg.split(',') : ['light', 'dark'];
const heightFor = w => (w >= 1280 ? 900 : w >= 768 ? 1024 : 844);

(async () => {
  const browser = await chromium.launch();
  let fail = 0, checks = 0; const log = [];
  for (const w of widths) for (const theme of themes) {
    const ctx = await browser.newContext({ viewport: { width: w, height: heightFor(w) }, colorScheme: theme, reducedMotion: 'reduce' });
    const page = await ctx.newPage();
    for (const [name, url] of pages) {
      const r = await page.goto(base + url, { waitUntil: 'load' }).catch(() => null);
      await page.waitForTimeout(250);
      if (mode === 'shots') {
        const dir = path.join(outDir, `${w}-${theme}`); fs.mkdirSync(dir, { recursive: true });
        await page.screenshot({ path: path.join(dir, name + '.jpg'), fullPage: true, type: 'jpeg', quality: Number(qArg) });
        continue;
      }
      const m = await page.evaluate(() => {
        const W = document.documentElement.clientWidth;
        const vis = e => { const b = e.getBoundingClientRect(); const cs = getComputedStyle(e); return b.width > 0 && b.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none'; };
        const tiny = [];
        const walker = document.createTreeWalker(document.querySelector('main') || document.body, NodeFilter.SHOW_TEXT);
        let n; while ((n = walker.nextNode())) { const t = n.nodeValue.trim(); if (t.length < 3) continue; const el = n.parentElement; if (!el || !vis(el) || el.closest('script,style,noscript,[hidden],.sr,svg')) continue; const fs = parseFloat(getComputedStyle(el).fontSize); if (fs < 12.5) { tiny.push(el.tagName + ':' + fs + ':' + t.slice(0, 20)); if (tiny.length > 3) break; } }
        const small = [...document.querySelectorAll('.siteHeader a, .siteHeader button, .siteHeader summary, main .btn, footer .btn')].filter(e => vis(e) && !e.closest('[hidden]')).filter(e => { const b = e.getBoundingClientRect(); return b.height < 43.5 && !e.closest('.sr'); }).map(e => (e.className || e.tagName) + ':' + Math.round(e.getBoundingClientRect().height)).slice(0, 3);
        const emptySections = [...document.querySelectorAll('main section, main .wrap')].filter(e => vis(e) && e.getBoundingClientRect().height < 20 && e.children.length === 0 && !e.textContent.trim()).length;
        return { overflow: document.documentElement.scrollWidth - W, h1: document.querySelectorAll('h1').length, header: !!document.querySelector('.siteHeader'), footer: !!document.querySelector('.siteFooter'),
          uni: [...document.querySelectorAll('header a, footer a, nav a')].filter(a => /universe|graph\.php|heatmap|lineage|battle/i.test(a.getAttribute('href') || '')).length,
          tiny, small, emptySections, fontLoaded: document.fonts && [...document.fonts].some(f => f.family.includes('Space Grotesk') && f.status === 'loaded'), bodyFont: getComputedStyle(document.body).fontFamily.slice(0, 20) };
      });
      const tag = `[${w}/${theme}] ${name}`;
      const chk = (ok, msg) => { checks++; if (!ok) { fail++; log.push(`FAIL ${tag}: ${msg}`); } };
      chk(m.overflow <= 0, `horizontal overflow ${m.overflow}px`);
      chk(m.h1 === 1 || (name === 'recommend-results' && m.h1 >= 1) || ['pricing-comparison'].includes(name) && m.h1 >= 1, `h1 count ${m.h1}`);
      chk(m.header && m.footer, 'header/footer missing');
      chk(m.uni === 0, 'Universe link in header/footer');
      chk(m.tiny.length === 0, `text below 12.5px: ${m.tiny.join(' | ')}`);
      chk(m.small.length === 0, `header/button target under 44px: ${m.small.join(' | ')}`);
      chk(m.emptySections === 0, `empty sections ${m.emptySections}`);
      if (r && r.status() >= 500) chk(false, 'HTTP ' + r.status());
    }
    await ctx.close();
  }
  await browser.close();
  if (mode === 'check') { console.log(log.join('\n')); console.log(`public pages QA: ${checks - fail} passed, ${fail} failed (${pages.length} pages x ${widths.length} widths x ${themes.length} themes)`); process.exit(fail ? 1 : 0); }
})();
