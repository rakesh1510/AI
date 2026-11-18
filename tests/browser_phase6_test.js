// Phase 6 browser tests: public services / partners / advertiser forms (desktop, mobile, no-JS) and the CRM interface (sign-in, keyboard, responsive, axe).
// Usage: node tests/browser_phase6_test.js <baseUrl> <screenshotDir> <prefix>   (needs the isolated test DB; creates and removes its own synthetic CRM user)
const { chromium, devices } = require('playwright');
const { execSync } = require('child_process');
const fs = require('fs');
const base = (process.argv[2] || 'http://127.0.0.1:8002').replace(/\/$/, ''); const shotDir = process.argv[3] || './shots'; const prefix = process.argv[4] || 'p6';
const axePath = process.env.AXE_PATH || require.resolve('axe-core/axe.min.js'); fs.mkdirSync(shotDir, { recursive: true });
let pass = 0, fail = 0; const check = (c, m) => { if (c) pass++; else { fail++; console.log('  FAIL: ' + m); } };
const PW = 'browser-test-password-1';
function cli(args, input) { return execSync(`php scripts/crm_user.php ${args}`, { input, encoding: 'utf8', env: process.env, cwd: require('path').join(__dirname, '..') }); }
async function noHScroll(page) { return page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1); }
async function axe(page) { await page.addStyleTag({ content: '*,*::before,*::after{transition:none!important;animation:none!important}' }); await page.evaluate(() => document.querySelectorAll('.reveal').forEach(e => e.classList.add('in'))); await page.waitForTimeout(400); await page.addScriptTag({ path: axePath });
  return page.evaluate(async () => (await axe.run(document, { runOnly: { type: 'tag', values: ['wcag2a', 'wcag2aa', 'wcag21aa', 'wcag22aa'] } })).violations.map(v => v.id + ':' + v.nodes.length)); }
(async () => {
  try { execSync('php -r \'require "helpers.php"; db_connection_available(); $p=db(); foreach (["crm_audit_log","crm_outreach_drafts","crm_prospects","crm_partners","crm_campaigns","crm_revenue_entries","crm_contracts","crm_proposal_items","crm_proposal_versions","crm_proposals","crm_tasks","crm_activities","crm_stage_history","crm_opportunities","crm_leads","crm_contacts","crm_companies","crm_users"] as $t) $p->exec("DELETE FROM $t");\'', { cwd: require('path').join(__dirname, '..'), env: process.env }); } catch (e) {}
  const out = cli('add browseruser "Browser Tester" owner', PW); check(/Created user/.test(out), 'the CLI creates a CRM user (password from standard input)');
  const short = (() => { try { cli('add x2user "X" owner', 'short'); return false; } catch (e) { return true; } })(); check(short, 'the CLI refuses a password shorter than 12 characters');
  const bad = (() => { try { cli('add browseruser "Dup" owner', PW); return false; } catch (e) { return true; } })(); check(bad, 'the CLI refuses a duplicate username');
  const browser = await chromium.launch();
  for (const [prof, opts] of [['desktop', { viewport: { width: 1366, height: 900 } }], ['mobile', devices['Pixel 7']]]) {
    const ctx = await browser.newContext(opts); await ctx.route(/^https?:\/\/(?!127\.0\.0\.1)/, r => r.abort()); const page = await ctx.newPage(); const P = `[${prof}]`;
    // ---- services
    await page.goto(base + '/services.php', { waitUntil: 'load' });
    check(await page.locator('h1').first().innerText().then(t => /AI development services/i.test(t)), `${P} services page heading`);
    check(await page.locator('.cardGrid .card').count() === 10, `${P} ten services are listed`);
    check(!(await page.content()).match(/testimonial|trusted by|clients include/i), `${P} no client or testimonial claims`);
    check(await noHScroll(page), `${P} services page has no horizontal scrolling`);
    await page.locator('.cardGrid .card').first().click(); await page.waitForLoadState('load'); check(/services\.php\?s=custom-ai-agents/.test(page.url()) && await page.locator('#projectForm').count() === 1, `${P} a service page opens with the guided form preselected`);
    check(await page.locator('#pj-service').inputValue() === 'custom-ai-agents', `${P} the service is preselected in the form`);
    check(await page.locator('link[rel=canonical]').getAttribute('href').then(h => /services\.php\?s=custom-ai-agents$/.test(h)), `${P} the service page has a self-canonical URL`);
    check(await page.locator('#projectForm label').count() >= 9 && await page.locator('#projectForm [required]').count() >= 6, `${P} every project form field has a label and required fields are marked`);
    await page.screenshot({ path: `${shotDir}/${prefix}-services-${prof}.png`, fullPage: true });
    // keyboard: reach the submit button with Tab only
    await page.locator('#pj-service').focus(); let reached = false; for (let i = 0; i < 25; i++) { await page.keyboard.press('Tab'); if (await page.evaluate(() => document.activeElement && document.activeElement.tagName === 'BUTTON' && /Send project/.test(document.activeElement.textContent))) { reached = true; break; } } check(reached, `${P} the form can be completed and submitted by keyboard`);
    // server-side validation shows errors and keeps input
    await page.waitForTimeout(2100); await page.fill('#pj-name', 'Browser Person'); await page.fill('#pj-email', 'not-an-email'); await page.fill('#pj-message', 'short'); await page.locator('#projectForm button[type=submit]').click(); await page.waitForLoadState('load');
    check(await page.locator('.fieldError').count() >= 2 && await page.locator('#pj-name').inputValue() === 'Browser Person', `${P} invalid input shows field errors and keeps what was typed`);
    check(await page.locator('#pj-email[aria-invalid="true"]').count() === 1, `${P} invalid fields are marked aria-invalid`);
    // ---- partners and advertise
    await page.goto(base + '/partners.php', { waitUntil: 'load' }); check(/no verified partners/i.test(await page.locator('#listTitle').locator('xpath=..').innerText()), `${P} partners page shows the honest empty state`); check(await noHScroll(page), `${P} partners page has no horizontal scrolling`);
    check(await page.locator('#pa-cat option').count() === 8, `${P} seven partner categories plus the placeholder`);
    await page.goto(base + '/advertise.php', { waitUntil: 'load' }); check(await page.locator('#definitions dt').count() >= 5 && /Sponsored/.test(await page.locator('#definitions').innerText()), `${P} advertiser reporting definitions and the sponsored label are shown`); check(await noHScroll(page), `${P} advertiser page has no horizontal scrolling`);
    check(await page.locator('#adv-type option').count() === 5, `${P} advertiser form offers placement, category, custom, newsletter and affiliate inquiries`);
    // ---- CRM
    await page.goto(base + '/crm.php', { waitUntil: 'load' }); check(/CRM sign-in/.test(await page.locator('h1').first().innerText()) && await page.locator('meta[name=robots]').getAttribute('content').then(c => /noindex/.test(c)), `${P} the CRM shows only a sign-in form and is noindex`);
    check((await axe(page)).length === 0, `${P} sign-in page: no axe violations`);
    await page.waitForTimeout(1500); await page.fill('#crmUser', 'browseruser'); await page.fill('#crmPw', PW); await Promise.all([page.waitForNavigation(), page.keyboard.press('Enter')]);
    check(await page.locator('text=Sign out').count() >= 1, `${P} signing in works (keyboard Enter submits)`);
    for (const v of ['dashboard', 'leads', 'opportunities', 'proposals', 'contracts', 'campaigns', 'partners', 'prospects', 'outreach', 'companies', 'tasks', 'audit', 'users']) {
      await page.goto(`${base}/crm.php?v=${v}`, { waitUntil: 'load' }); check(await page.locator('h2').count() >= 1 && !(await page.content()).match(/Fatal error|Warning:|Notice:/), `${P} CRM view '${v}' renders`);
      check(await noHScroll(page), `${P} CRM view '${v}' has no horizontal scrolling`);
      const ax = await axe(page); check(ax.length === 0, `${P} CRM view '${v}': no axe violations ${JSON.stringify(ax)}`); }
    await page.goto(`${base}/crm.php?v=dashboard`, { waitUntil: 'load' }); check(/nothing to report|No inquiries/i.test(await page.locator('main').innerText()), `${P} the empty dashboard says there is nothing to report instead of showing zeros as results`); await page.screenshot({ path: `${shotDir}/${prefix}-crm-dashboard-${prof}.png`, fullPage: true });
    await ctx.close();
  }
  // no-JS
  const nj = await browser.newContext({ javaScriptEnabled: false, viewport: { width: 1280, height: 900 } }); const jp = await nj.newPage();
  await jp.goto(base + '/services.php?s=api-integrations'); check(await jp.locator('#projectForm').count() === 1 && await jp.locator('#pj-service').inputValue() === 'api-integrations', '[no-JS] the project form works and is preselected without JavaScript');
  await jp.goto(base + '/partners.php'); check(await jp.locator('#partnerForm').count() === 1, '[no-JS] the partnership form is present without JavaScript');
  await nj.close(); await browser.close();
  try { cli('deactivate browseruser'); execSync('php -r \'require "helpers.php"; db_connection_available(); $p=db(); foreach (["crm_audit_log","crm_outreach_drafts","crm_prospects","crm_partners","crm_campaigns","crm_revenue_entries","crm_contracts","crm_proposal_items","crm_proposal_versions","crm_proposals","crm_tasks","crm_activities","crm_stage_history","crm_opportunities","crm_leads","crm_contacts","crm_companies","crm_users"] as $t) $p->exec("DELETE FROM $t");\'', { cwd: require('path').join(__dirname, '..'), env: process.env }); } catch (e) {}
  console.log(`\nPASSED: ${pass}  FAILED: ${fail}`); process.exit(fail ? 1 : 0);
})().catch(e => { console.error(e); process.exit(1); });
