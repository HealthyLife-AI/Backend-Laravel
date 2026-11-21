// J3e: add "حليب كامل الدسم" to breakfast for a patient with confirmed milk allergy; activate; record dialog.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const [email, id] = process.argv.slice(2);
  const { browser, page } = await open();
  const calls = [];
  page.on('response', async (r) => { if (/meal-plans/.test(r.url()) && r.request().method() !== 'GET' && !/allergen-check/.test(r.url())) calls.push(r.status() + ' ' + r.request().method() + ' ' + r.url().replace(/.*v1/, '') + ' ' + (await r.text().catch(() => '')).replace(/.*"allergen_conflicts":/, 'allergen_conflicts:').slice(0, 300)); });
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/plan`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  await page.getByRole('button', { name: 'إضافة طعام لوجبة الفطور' }).click(); await page.waitForTimeout(800);
  const s = page.locator('input[placeholder*="البحث في قاعدة"]').first();
  await s.fill('فتة حمص'); await page.waitForTimeout(2500);
  await page.getByText('فتة حمص', { exact: true }).first().click(); await page.waitForTimeout(1500);
  await shot(page, 'j3-11-tagged-selected-ar');
  const btns = await page.$$eval('button', (els) => els.map((e) => e.innerText.trim()).filter(Boolean));
  console.log('buttons near:', btns.filter((b) => /إضافة|أضف/.test(b)).join(' | '));
  const add = page.getByRole('button', { name: /^(إضافة|أضف)/ }).filter({ hasNotText: 'نشاط' }).filter({ hasNotText: 'لوجبة' });
  console.log('add count', await add.count());
  if (await add.count()) { await add.first().click(); await page.waitForTimeout(1500); }
  await shot(page, 'j3-12-tagged-added-ar');
  await page.getByRole('button', { name: 'حفظ الخطة' }).click(); await page.waitForTimeout(2500);
  await shot(page, 'j3-13-tagged-after-save-ar'); console.log('dialog after save:', (await page.locator('[role=dialog]').innerText().catch(() => 'NO DIALOG')).replace(/\n/g,' / ').slice(0,900));
  await page.getByRole('button', { name: 'خطة نشطة' }).click(); await page.waitForTimeout(2500);
  await shot(page, 'j3-14-tagged-dialog-ar');
  const dt = await page.locator('[role=dialog]').innerText().catch(() => 'NO DIALOG');
  console.log('dialog:', dt.replace(/\n/g, ' / ').slice(0, 900));
  console.log(calls.join('\n'));
  fs.writeFileSync(__dirname + '/j3f-body.txt', await page.innerText('body'));
  dumpLog('j3f');
  await browser.close();
})();
