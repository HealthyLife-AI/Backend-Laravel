// J3d: add allergenic food (milk) to the draft, observe warnings, then activate -> ack dialog.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const [email, id] = process.argv.slice(2);
  const { browser, page } = await open();
  const calls = [];
  page.on('response', async (r) => { if (/meal-plans/.test(r.url()) && r.request().method() !== 'GET') calls.push(r.status() + ' ' + r.request().method() + ' ' + r.url().replace(/.*v1/, '') + ' ' + (await r.text().catch(() => '')).slice(0, 300)); });
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/plan`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  await page.getByRole('button', { name: 'إضافة طعام لوجبة الفطور' }).click(); await page.waitForTimeout(800); await shot(page, 'dbg-after-add'); console.log((await page.$$eval('input', es => es.map(e => e.type + ':' + e.placeholder))).join(' ; '));
  const search = page.locator('input[placeholder*="البحث في قاعدة"]').first();
  console.log('search inputs', await page.locator('input[placeholder*="البحث في قاعدة"]').count());
  await search.click(); await search.fill('حليب'); await page.waitForTimeout(2500);
  await shot(page, 'j3-07-milk-search-ar');
  const list = page.locator('[role=listbox], ul[role=listbox]').first();
  console.log('listbox text:', (await list.innerText().catch(() => 'none')).replace(/\n/g, ' / ').slice(0, 500));
  const opt = page.locator('[role=option]').first();
  if (await opt.count()) { await opt.click(); await page.waitForTimeout(1500); }
  await shot(page, 'j3-08-milk-selected-ar');
  // click add button near the search if any
  const addBtn = page.getByRole('button', { name: /^إضافة$/ }).first();
  if (await addBtn.count()) { await addBtn.click(); await page.waitForTimeout(1500); }
  await shot(page, 'j3-09-milk-added-ar', { fullPage: false });
  // activate
  await page.getByRole('button', { name: 'اعتماد وتفعيل' }).click(); await page.waitForTimeout(2500);
  await shot(page, 'j3-10-activate-dialog-ar');
  const dlg = page.locator('[role=dialog]');
  const dtext = (await dlg.innerText().catch(() => 'no dialog'));
  console.log('dialog:', dtext.replace(/\n/g, ' / ').slice(0, 900));
  fs.writeFileSync(__dirname + '/j3-dialog.txt', dtext);
  console.log(calls.join('\n'));
  dumpLog('j3d');
  await browser.close();
})();
