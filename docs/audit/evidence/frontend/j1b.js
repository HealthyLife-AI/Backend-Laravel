const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const email = process.argv[2]; const pw = 'Audit-Pass-2026!x';
  const { browser, page, context } = await open();
  await login(page, BASE, email, pw);
  let clicks = 0;
  await page.getByRole('button', { name: 'إضافة مريض' }).first().click().catch(async () => { await page.getByRole('link', { name: 'إضافة مريض' }).first().click(); });
  clicks++;
  await page.waitForTimeout(800);
  await shot(page, 'j1-04-add-patient-dialog-ar');
  const fields = await page.$$eval('input,select', (els) => els.map((e) => [e.tagName, e.name, e.type, e.getAttribute('inputmode'), e.value]));
  console.log('fields', JSON.stringify(fields));
  await page.fill('input[name=name]', 'مريض تجريبي ' + Date.now().toString().slice(-4)); clicks++;
  // Arabic-Indic digits in phone
  await page.fill('input[name=phone]', '٠٥٩٩١' + Date.now().toString().slice(-5).replace(/\d/g, (d) => '٠١٢٣٤٥٦٧٨٩'[d])); clicks++;
  await shot(page, 'j1-05-add-patient-filled-ar');
  await page.click('button[type=submit]'); clicks++;
  await page.waitForTimeout(2500);
  await shot(page, 'j1-06-credentials-ar');
  const wa = await page.$eval('a[href*="wa.me"]', (a) => a.href).catch(() => null);
  const txt = await page.innerText('body');
  fs.writeFileSync(__dirname + '/j1-credentials-text.txt', txt.slice(0, 3000));
  console.log('clicks to first patient', clicks);
  console.log('wa', wa ? decodeURIComponent(wa).replace(/(كلمة المرور: |Password: )\S+/g, '$1<MASKED>') : null);
  // go to patient
  const link = await page.$('a[href*="/health-profile"]');
  if (link) { await link.click(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500); await shot(page, 'j1-07-health-profile-ar'); console.log('url', page.url()); }
  dumpLog('j1b');
  await browser.close();
})();
