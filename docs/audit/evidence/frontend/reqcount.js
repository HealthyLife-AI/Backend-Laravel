// Count API requests per dashboard page load.
const { open, BASE } = require('./lib');
const login = require('./login');
(async () => {
  const [email, pid] = process.argv.slice(2);
  const { browser, page } = await open();
  await login(page, BASE, email, 'Audit-Pass-2026!x');
  for (const p of ['/ar/dashboard', '/ar/dashboard/patients', `/ar/dashboard/patients/${pid}`, `/ar/dashboard/patients/${pid}/plan`]) {
    const reqs = [];
    const h = (r) => { if (/:8000\/api|\/api\/auth/.test(r.url())) reqs.push(r.url().replace(/.*(api\/v1|api\/auth)/, '$1').split('?')[0]); };
    page.on('request', h);
    await page.goto(BASE + p); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
    page.off('request', h);
    console.log(p, reqs.length, [...new Set(reqs)].length + ' distinct');
  }
  await browser.close();
})();
