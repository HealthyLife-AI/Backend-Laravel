// J4: appointment booked 10:00 Asia/Riyadh (+03:00); how is it shown in a browser on Asia/Gaza (+02:00 in Nov)?
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const login = require('./login');
const BASE = 'http://127.0.0.1:3000';
(async () => {
  const browser = await chromium.launch();
  const ctx = await browser.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2, timezoneId: 'Asia/Gaza' });
  const page = await ctx.newPage();
  await login(page, BASE, process.argv[2], 'Audit-Pass-2026!x');
  for (const [p, n] of [['/ar/dashboard/patients/3', 'j4-02-patient-card-gaza-tz-ar'], ['/ar/dashboard/appointments', 'j4-03-appointments-gaza-tz-ar'], ['/ar/dashboard', 'j4-04-home-gaza-tz-ar']]) {
    await page.goto(BASE + p); await page.waitForLoadState('networkidle'); await page.waitForTimeout(2500);
    const txt = await page.innerText('main');
    console.log(p, '=>', (txt.match(/[^\n]*(\d{1,2}:\d{2}|ص|م)[^\n]*/g) || []).filter((l) => /\d{1,2}:\d{2}/.test(l)).slice(0, 4).join(' | '));
    await page.screenshot({ path: `${__dirname}/${n}.png` });
  }
  await browser.close();
})();
