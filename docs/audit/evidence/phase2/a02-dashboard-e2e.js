// A-02 dashboard check: add milk to an ACTIVE plan of a patient with a confirmed milk allergy, then save.
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const [base, tag] = process.argv.slice(2);
(async () => {
  const browser = await chromium.launch();
  const page = await (await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 })).newPage();
  const puts = []; const errors = [];
  page.on('response', async (r) => { if (r.request().method() === 'PUT' && /meal-plans\/\d+$/.test(r.url())) { let b = ''; try { b = await r.text(); } catch {} puts.push(`${r.status()} ${(b.match(/"code":"[a-z_]+"/) || ['(no code)'])[0]}`); } });
  page.on('pageerror', (e) => errors.push(String(e).slice(0, 200)));
  await page.goto(`${base}/ar/login`); await page.waitForLoadState('networkidle');
  await page.fill('input[name=email]', 'e2e.nutri@example.test'); await page.fill('input[name=password]', 'E2e-Local-Only-1!');
  await page.click('button[type=submit]'); await page.waitForURL(/dashboard/, { timeout: 20000 });
  await page.goto(`${base}/ar/dashboard/patients/1/plan`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  await page.getByRole('button', { name: 'إضافة طعام لوجبة الفطور' }).click(); await page.waitForTimeout(600);
  const search = page.locator('input[placeholder*="البحث في قاعدة"]').first();
  await search.click(); await search.fill('حليب كامل'); await page.waitForTimeout(2500);
  await page.getByText('حليب كامل الدسم', { exact: true }).first().click(); await page.waitForTimeout(1500);
  const add = page.getByRole('button', { name: /^إضافة$/ }).first(); if (await add.count()) { await add.click(); await page.waitForTimeout(1000); }
  await page.getByRole('button', { name: 'حفظ الخطة' }).click(); await page.waitForTimeout(3000);
  await page.screenshot({ path: `/tmp/claude-0/e2e/shots/a02-${tag}-1-after-save.png` });
  const dialog = page.locator('[role=dialog]');
  const hasDialog = await dialog.count() > 0 && await dialog.isVisible();
  console.log(`[${tag}] PUT after save: ${puts.join(' | ')}`);
  console.log(`[${tag}] dialog: ${hasDialog ? (await dialog.innerText()).replace(/\n+/g, ' / ').slice(0, 400) : 'none'}`);
  const alertText = (await page.locator('[role=alert]').allInnerTexts()).join(' / ').slice(0, 300);
  console.log(`[${tag}] alerts on page: ${alertText || 'none'}`);
  if (hasDialog) {
    await dialog.locator('input[type=checkbox]').first().check();
    await dialog.getByRole('button', { name: 'حفظ التعديل' }).click(); await page.waitForTimeout(3000);
    await page.screenshot({ path: `/tmp/claude-0/e2e/shots/a02-${tag}-2-after-confirm.png` });
    console.log(`[${tag}] PUT after confirm: ${puts.slice(1).join(' | ')}`);
    console.log(`[${tag}] notice: ${(await page.getByText('تم حفظ الخطة.').count()) ? 'تم حفظ الخطة.' : 'no saved notice'}`);
  }
  console.log(`[${tag}] page errors: ${errors.length ? errors.join(' | ') : 'none'}`);
  await browser.close();
})().catch((e) => { console.error(e.message.slice(0, 400)); process.exit(1); });
