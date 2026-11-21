// J1: stranger signs up as nutritionist (AR), first-run, adds first patient, reads WhatsApp link.
const { open, shot, dumpLog, BASE } = require('./lib');
(async () => {
  const { browser, page } = await open();
  let clicks = 0; const steps = [];
  const stamp = Date.now().toString().slice(-6);
  const email = `fe.audit.${stamp}@example.test`;
  await page.goto(BASE + '/ar');
  await page.waitForLoadState('networkidle');
  await shot(page, 'j1-01-landing-ar');
  // find sign-up CTA
  const links = await page.$$eval('a', (as) => as.map((a) => [a.textContent.trim().slice(0, 40), a.getAttribute('href')]).filter(x => x[1]));
  steps.push({ landingLinks: links.slice(0, 40) });
  await page.goto(BASE + '/ar/register'); clicks++;
  await page.waitForLoadState('networkidle');
  await shot(page, 'j1-02-register-ar');
  await page.fill('input[name=name]', 'أخصائية تجربة'); 
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'Audit-Pass-2026!x');
  await page.fill('input[name=password_confirmation]', 'Audit-Pass-2026!x');
  clicks += 4;
  await page.click('button[type=submit]'); clicks++;
  await page.waitForURL(/dashboard/, { timeout: 20000 }).catch(() => {});
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(1500);
  steps.push({ afterRegisterUrl: page.url() });
  await shot(page, 'j1-03-first-run-ar');
  await shot(page, 'j1-03b-first-run-ar-full', { fullPage: true });
  const bodyText = await page.innerText('body');
  require('fs').writeFileSync(__dirname + '/j1-first-run-text.txt', bodyText);
  console.log(JSON.stringify({ email, clicks, steps }, null, 1));
  require('fs').writeFileSync(__dirname + '/j1-account.json', JSON.stringify({ email, password: 'see script (test-only)' }));
  dumpLog('j1a');
  await browser.close();
})();
