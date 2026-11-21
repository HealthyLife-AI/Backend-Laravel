// J3b: add confirmed milk allergy via UI, then open plan page and explore AI draft.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const [email, id] = process.argv.slice(2);
  const { browser, page } = await open();
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/health-profile`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  const sec = page.locator('section', { hasText: 'الحساسيات وعدم التحمّل' }).last();
  await sec.scrollIntoViewIfNeeded();
  const opts = await sec.locator('select').first().locator('option').allInnerTexts();
  console.log('allergy groups', opts.join(' | '));
  await sec.getByRole('button', { name: 'إضافة' }).click();
  await page.waitForTimeout(1500);
  await sec.screenshot({ path: __dirname + '/j3-03-allergy-added-ar.png' });
  console.log('allergy section:', (await sec.innerText()).replace(/\n/g, ' / ').slice(0, 300));
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/plan`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  await shot(page, 'j3-04-plan-empty-ar', { fullPage: true });
  const btns = await page.$$eval('button', (els) => els.map((e) => e.innerText.trim().replace(/\s+/g, ' ')).filter(Boolean));
  fs.writeFileSync(__dirname + '/j3-plan-page.txt', (await page.innerText('body')) + '\n---\n' + btns.join('\n'));
  dumpLog('j3b');
  await browser.close();
})();
