// J4: book an appointment from the patient page in a browser set to Asia/Gaza; compare displayed times.
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const login = require('./login');
const BASE = 'http://127.0.0.1:3000';
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2, timezoneId: 'Asia/Gaza', locale: 'ar' });
  const page = await ctx.newPage();
  await login(page, BASE, process.argv[2], 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/3`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  await page.getByRole('button', { name: 'حجز موعد' }).first().click(); await page.waitForTimeout(2500);
  const dlg = page.locator('[role=dialog]');
  const times = await dlg.locator('[role=group]').nth(1).locator('button').allInnerTexts().catch(() => []);
  console.log('slot buttons shown (first 4):', times.slice(0, 4).join(' | '));
  console.log('dialog tz text:', ((await dlg.innerText()).match(/[^\n]*(توقيت|Riyadh|الرياض)[^\n]*/g) || []).join(' / '));
  await page.screenshot({ path: __dirname + '/j4-01-book-dialog-gaza-tz-ar.png' });
  await dlg.locator('[role=group]').nth(1).locator('button').first().click();
  const submit = dlg.getByRole('button', { name: /حجز|تأكيد/ }).last();
  await submit.click(); await page.waitForTimeout(2500);
  await page.goto(`${BASE}/ar/dashboard/patients/3`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  const card = (await page.innerText('main')).match(/[^\n]*(الموعد القادم|موعد)[^\n]*\n[^\n]*\n[^\n]*/g);
  console.log('patient card:', (card || []).slice(0, 3).join(' // '));
  await page.screenshot({ path: __dirname + '/j4-02-patient-card-gaza-tz-ar.png' });
  await page.goto(`${BASE}/ar/dashboard/appointments`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  console.log('appointments page:', (await page.innerText('main')).replace(/\n+/g, ' / ').slice(0, 700));
  await page.screenshot({ path: __dirname + '/j4-03-appointments-gaza-tz-ar.png' });
  await browser.close();
})();
