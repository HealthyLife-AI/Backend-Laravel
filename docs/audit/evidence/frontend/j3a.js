// J3a: health profile with Arabic-Indic digits, then add a milk allergy via UI.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
(async () => {
  const [email, id] = process.argv.slice(2);
  const { browser, page } = await open();
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/health-profile`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  const nums = page.locator('input[type=number]');
  console.log('number inputs', await nums.count());
  // weight: type Arabic-Indic digits via keyboard
  await nums.nth(0).click(); await page.keyboard.type('٨٢٫٥');
  console.log('weight value after typing ٨٢٫٥ =', JSON.stringify(await nums.nth(0).inputValue()));
  await nums.nth(0).fill('');
  // Try fill (IME/paste-like)
  try { await nums.nth(0).fill('٨٢'); console.log('fill accepted, value=', JSON.stringify(await nums.nth(0).inputValue())); } catch (e) { console.log('fill rejected:', String(e).split('\n')[0]); }
  await shot(page, 'sweep-01-weight-arabic-digits-ar');
  await nums.nth(0).fill('82.5'); await nums.nth(1).fill('165'); await nums.nth(2).fill('35');
  await page.getByRole('button', { name: /حفظ/ }).first().click();
  await page.waitForTimeout(2500);
  await shot(page, 'j3-02-health-profile-saved-ar', { fullPage: true });
  const txt = await page.innerText('body');
  console.log((txt.match(/[^\n]*سعر[^\n]*/g) || []).slice(0, 5));
  // allergies editor might be here
  const addAllergy = page.getByRole('button', { name: /حساسية/ });
  console.log('allergy buttons', await addAllergy.count());
  require('fs').writeFileSync(__dirname + '/j3a-health.txt', txt);
  dumpLog('j3a');
  await browser.close();
})();
