// J5 + EN J1: add patient in EN (password masked in screenshot), end follow-up for patient 3, posts/new, phone viewport.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
(async () => {
  const email = process.argv[2];
  let { browser, page } = await open();
  await login(page, BASE, email, 'Audit-Pass-2026!x', 'en');
  await page.goto(BASE + '/en/dashboard/patients/new'); await page.waitForLoadState('networkidle');
  await page.fill('input[name=name]', 'Audit Patient EN');
  await page.fill('input[name=phone]', '0599' + Date.now().toString().slice(-6));
  await page.click('button[type=submit]'); await page.waitForTimeout(2500);
  const wa = await page.$eval('a[href*="wa.me"]', (a) => decodeURIComponent(a.href)).catch(() => null);
  console.log('EN wa text:', wa && wa.replace(/(Password: )\S+/, '$1<MASKED>'));
  await shot(page, 'j1-08-credentials-en-masked', { mask: [page.locator('span.font-mono').nth(1)] });
  // J5: end follow-up on patient 3
  await page.goto(BASE + '/en/dashboard/patients/3'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  await page.getByRole('button', { name: /settings/i }).last().click().catch(() => {});
  const end = page.getByRole('button', { name: /End follow-up/i }).first();
  console.log('end follow-up buttons', await end.count());
  if (await end.count()) { await end.click(); await page.waitForTimeout(800); await shot(page, 'j5-01-end-followup-confirm-en');
    const confirm = page.getByRole('button', { name: /End follow-up/i }).last(); await confirm.click(); await page.waitForTimeout(2000); await shot(page, 'j5-02-archived-en'); }
  console.log('after archive:', (await page.innerText('main')).split('\n').filter((l) => /archiv|ended|resume|follow/i.test(l)).slice(0, 5).join(' | '));
  await page.goto(BASE + '/en/dashboard/posts/new'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  await shot(page, 'j5-03-post-new-en');
  dumpLog('j5'); await browser.close();
  ({ browser, page } = await open({ phone: true }));
  await login(page, BASE, email, 'Audit-Pass-2026!x', 'ar');
  await page.waitForTimeout(1500); await shot(page, 'phone-01-dashboard-ar');
  await page.goto(BASE + '/ar/dashboard/patients/3/plan'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000); await shot(page, 'phone-02-plan-ar');
  const sw = await page.evaluate(() => document.documentElement.scrollWidth); console.log('phone plan scrollWidth', sw);
  await browser.close();
})();
