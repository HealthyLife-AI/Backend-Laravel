const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
(async () => {
  const { browser, page } = await open();
  await login(page, BASE, process.argv[2], 'Audit-Pass-2026!x', 'en');
  await page.goto(BASE + '/en/dashboard/patients/3'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  const tabs = await page.$$eval('[role=tab]', (t) => t.map((x) => x.innerText.trim()));
  console.log('tabs', tabs.join('|'));
  await page.locator('[role=tab]', { hasText: 'Settings' }).click(); await page.waitForTimeout(800);
  await page.getByRole('button', { name: 'End follow-up' }).click(); await page.waitForTimeout(800);
  await shot(page, 'j5-01-end-followup-confirm-en');
  await page.getByRole('button', { name: /Yes, end follow-up/i }).click(); await page.waitForTimeout(2500);
  await shot(page, 'j5-02-archived-en');
  console.log((await page.innerText('main')).split('\n').filter((l) => /archiv|ended|resume|no longer|app/i.test(l)).slice(0, 6).join(' | '));
  await page.goto(BASE + '/en/dashboard/patients'); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  console.log('list:', (await page.innerText('main')).replace(/\n+/g, ' / ').slice(0, 400));
  dumpLog('j5b'); await browser.close();
})();
