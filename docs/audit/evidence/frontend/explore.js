// explore.js <email> <path> <name> [locale] : login, open path, full screenshot, dump buttons/inputs/text
const { open, shot, dumpLog, BASE } = require('./lib');
const login = require('./login');
(async () => {
  const [email, p, name, locale = 'ar', phone] = process.argv.slice(2);
  const { browser, page } = await open({ phone: phone === 'phone' });
  await login(page, BASE, email, 'Audit-Pass-2026!x', locale);
  await page.goto(BASE + p); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2000);
  await shot(page, name, { fullPage: true });
  const btns = await page.$$eval('button,a', (els) => els.map((e) => e.innerText.trim().replace(/\s+/g, ' ')).filter(Boolean));
  const inputs = await page.$$eval('input,select,textarea', (els) => els.map((e) => [e.name, e.type, e.placeholder, e.getAttribute('aria-label')].join('|')));
  require('fs').writeFileSync(__dirname + '/' + name + '.txt', (await page.innerText('body')) + '\n---BUTTONS\n' + btns.join('\n') + '\n---INPUTS\n' + inputs.join('\n'));
  const l = dumpLog(name).filter((x) => !/GL Driver|preloaded|auth\/refresh|401 \(Unauth/.test(x.text));
  console.log(JSON.stringify(l));
  await browser.close();
})();
