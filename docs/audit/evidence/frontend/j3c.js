// J3c: AI draft (fallback because Groq unreachable), then add allergenic food.
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const [email, id] = process.argv.slice(2);
  const { browser, page } = await open();
  const apiCalls = [];
  page.on('response', async (r) => { if (/ai|draft|suggest/i.test(r.url()) && r.request().method() === 'POST') { apiCalls.push(r.status() + ' ' + r.url() + ' ' + (await r.text().catch(() => '')).slice(0, 600)); } });
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  await page.goto(`${BASE}/ar/dashboard/patients/${id}/plan`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  const t0 = Date.now();
  await page.getByRole('button', { name: 'اقترح خطة مبدئية' }).click();
  await page.waitForTimeout(1000);
  await shot(page, 'j3-05-ai-draft-clicked-ar');
  await page.waitForLoadState('networkidle'); await page.waitForTimeout(4000);
  console.log('draft wait ms', Date.now() - t0);
  await shot(page, 'j3-06-ai-draft-result-ar');
  await shot(page, 'j3-06b-ai-draft-result-ar-full', { fullPage: true });
  fs.writeFileSync(__dirname + '/j3-draft-text.txt', await page.innerText('body'));
  console.log(apiCalls.join('\n'));
  dumpLog('j3c');
  await browser.close();
})();
