// Dashboard smoke: public pages, sign-in via the BFF, nutritionist pages, sign-out. Arabic first, then English.
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const base = process.argv[2];
const results = []; let failures = 0;
function check(name, ok, detail = '') { results.push(`${ok ? 'PASS' : 'FAIL'}  ${name}${detail ? '  — ' + detail : ''}`); if (!ok) failures++; }
(async () => {
  const browser = await chromium.launch();
  for (const loc of ['ar', 'en']) {
    const context = await browser.newContext({ viewport: { width: 1280, height: 800 } });
    const page = await context.newPage();
    const errors = []; const bad = [];
    page.on('pageerror', (e) => errors.push(String(e).slice(0, 160)));
    page.on('console', (m) => { if (m.type() === 'error' && !/401|favicon|Failed to load resource/.test(m.text())) errors.push(m.text().slice(0, 160)); });
    page.on('response', (r) => { const u = r.url(); if (r.status() >= 500) bad.push(`${r.status()} ${u.replace(base, '')}`); });
    const dir = loc === 'ar' ? 'rtl' : 'ltr';
    for (const path of ['', '/privacy', '/login', '/register']) {
      const r = await page.goto(`${base}/${loc}${path}`); await page.waitForLoadState('networkidle');
      check(`[${loc}] public ${path || '/'} renders`, r.status() === 200 && (await page.locator('html').getAttribute('dir')) === dir, `status ${r.status()}`);
    }
    await page.goto(`${base}/${loc}/login`); await page.waitForLoadState('networkidle');
    await page.fill('input[name=email]', 'e2e.nutri@example.test'); await page.fill('input[name=password]', 'E2e-Local-Only-1!');
    await page.click('button[type=submit]'); await page.waitForURL(/dashboard/, { timeout: 20000 }); await page.waitForLoadState('networkidle');
    check(`[${loc}] sign-in through the BFF reaches the dashboard`, /dashboard/.test(page.url()));
    const cookies = await context.cookies();
    const refresh = cookies.find((c) => c.name === 'hl_refresh');
    check(`[${loc}] refresh cookie is HttpOnly`, !!refresh && refresh.httpOnly, refresh ? `sameSite=${refresh.sameSite}` : 'no cookie');
    for (const [name, path] of [['home', ''], ['patients', '/patients'], ['patient page', '/patients/1'], ['plan designer', '/patients/1/plan'], ['alerts', '/alerts'], ['foods', '/foods']]) {
      const r = await page.goto(`${base}/${loc}/dashboard${path}`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(800);
      const body = (await page.locator('main, body').first().innerText()).slice(0, 4000);
      check(`[${loc}] dashboard ${name}`, r.status() === 200 && !/Application error|Internal Server Error|undefined/.test(body), `status ${r.status()}`);
    }
    // a BFF refresh after a reload keeps the session
    await page.goto(`${base}/${loc}/dashboard`); await page.waitForLoadState('networkidle');
    check(`[${loc}] session survives a reload`, /dashboard/.test(page.url()) && !/login/.test(page.url()));
    check(`[${loc}] no page errors or console errors`, errors.length === 0, errors.slice(0, 2).join(' | '));
    check(`[${loc}] no 5xx responses`, bad.length === 0, bad.slice(0, 3).join(' | '));
    if (loc === 'ar') await page.screenshot({ path: `/tmp/claude-0/e2e/shots/smoke-${process.argv[3]}-ar-dashboard.png` });
    await context.close();
  }
  await browser.close();
  console.log(results.join('\n')); console.log(`\n${results.length - failures}/${results.length} checks passed`);
  process.exit(failures ? 1 : 0);
})().catch((e) => { console.error(e.message.slice(0, 400)); process.exit(2); });
