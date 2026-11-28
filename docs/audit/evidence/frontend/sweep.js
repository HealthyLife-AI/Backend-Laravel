// sweep.js <email> <tag> [patientId]: render every dashboard page AR+EN, screenshot, console errors,
// wrong-script text (Latin words on /ar, Arabic on /en), physical-direction hints.
const { open, shot, log, BASE } = require('./lib');
const login = require('./login');
const fs = require('fs');
(async () => {
  const [email, tag, pid] = process.argv.slice(2);
  const paths = ['/dashboard', '/dashboard/patients', '/dashboard/patients/new', '/dashboard/appointments', '/dashboard/plans', '/dashboard/foods', '/dashboard/alerts', '/dashboard/requests', '/dashboard/posts', '/dashboard/posts/new', '/dashboard/settings'];
  if (pid) paths.push(`/dashboard/patients/${pid}`, `/dashboard/patients/${pid}/health-profile`, `/dashboard/patients/${pid}/plan`, `/dashboard/patients/${pid}/plan/print`);
  const out = [];
  for (const loc of ['ar', 'en']) {
    const { browser, page } = await open();
    await login(page, BASE, email, 'Audit-Pass-2026!x', loc);
    for (const p of paths) {
      const start = log.length;
      await page.goto(`${BASE}/${loc}${p}`); await page.waitForLoadState('networkidle').catch(() => {}); await page.waitForTimeout(4500);
      const name = `i4-${tag}-${loc}${p.replace(/\//g, '_').replace(/_dashboard/, '') || '_home'}`;
      await shot(page, name);
      const main = await page.locator('main').innerText().catch(async () => page.innerText('body'));
      const wrong = loc === 'ar'
        ? [...new Set((main.match(/\b[A-Za-z][A-Za-z ]{3,}[A-Za-z]\b/g) || []).filter((w) => !/HealthyLife|English|PDF|USDA|WhatsApp|BMI|AI/.test(w)))].slice(0, 15)
        : [...new Set(main.match(/[؀-ۿ][؀-ۿ\s]{2,}/g) || [])].slice(0, 15);
      const ungrouped = [...new Set(main.match(/(?<![\d.,])\d{4,}(?![\d.,])/g) || [])].filter((n) => !/^20\d\d$/.test(n)).slice(0, 10);
      const errs = log.slice(start).filter((x) => !/GL Driver|preloaded|auth\/refresh|401 \(Unauth/.test(x.text)).map((x) => x.kind + ' ' + x.text.slice(0, 160));
      out.push({ page: `/${loc}${p}`, wrongScript: wrong, ungrouped, errors: [...new Set(errs)] });
    }
    await browser.close();
  }
  fs.writeFileSync(`${__dirname}/i4-${tag}-report.json`, JSON.stringify(out, null, 1));
  for (const o of out) if (o.wrongScript.length || o.errors.length || o.ungrouped.length) console.log(JSON.stringify(o));
})();
