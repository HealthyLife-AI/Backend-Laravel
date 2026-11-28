// BE-17 e2e, Arabic: sign up -> banner -> blocked add-patient -> resend -> open the e-mailed link -> verified -> add patient works.
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const fs = require('fs');
const base = process.argv[2]; const logFile = process.argv[3]; const out = '/tmp/claude-0/e2e/shots/ev-';
const links = () => [...fs.readFileSync(logFile, 'utf8').matchAll(/http:\/\/127\.0\.0\.1:3030\/(?:ar|en)\/verify-email\?[^\s)"<>\]]+/g)].map((m) => m[0].replace(/&amp;/g, '&').replace(/=\r?\n/g, ''));
(async () => {
  const b = await chromium.launch(); const ctx = await b.newContext({ viewport: { width: 1280, height: 800 }, deviceScaleFactor: 2 }); const page = await ctx.newPage();
  const api = []; page.on('response', (r) => { if (/auth\/email|\/clients$/.test(r.url()) && r.request().method() !== 'GET') api.push(`${r.status()} ${r.request().method()} ${r.url().replace(/https?:\/\/[^/]+/, '')}`); });
  const email = `ev.${Date.now().toString().slice(-6)}@example.test`;
  await page.goto(`${base}/ar/register`); await page.waitForLoadState('networkidle');
  await page.fill('input[name=name]', 'أخصائية تجربة'); await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', 'Audit-Pass-2026!x'); await page.fill('input[name=password_confirmation]', 'Audit-Pass-2026!x');
  await page.click('button[type=submit]'); await page.waitForURL(/dashboard/, { timeout: 20000 }); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  const banner = page.locator('[role=status]', { hasText: 'أكّد بريدك الإلكتروني' });
  console.log('1 banner visible after sign-up:', await banner.count() > 0);
  await page.screenshot({ path: out + '1-banner-ar.png' });
  console.log('1b e-mails in log after sign-up:', links().length, '| link locale:', (links()[0] || '').match(/\/(ar|en)\//)?.[1]);
  // blocked: add a patient
  await page.getByRole('button', { name: 'إضافة مريض' }).first().click().catch(async () => { await page.getByRole('link', { name: 'إضافة مريض' }).first().click(); });
  await page.waitForTimeout(800);
  await page.fill('input[name=name]', 'مريض تجريبي'); await page.fill('input[name=phone]', '0599123456');
  await page.click('button[type=submit]'); await page.waitForTimeout(2500);
  await page.screenshot({ path: out + '2-add-blocked-ar.png' });
  console.log('2 add-patient answers:', api.filter((x) => /clients$/.test(x)).join(' | '));
  // resend
  await page.goto(`${base}/ar/dashboard`); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1200);
  await page.getByRole('button', { name: 'أرسل لي رابطًا جديدًا' }).click(); await page.waitForTimeout(2000);
  console.log('3 resend:', api.filter((x) => /resend/.test(x)).join(' | '), '| e-mails now:', links().length, '| says sent:', (await page.getByText('الرابط الجديد في طريقه إليك').count()) > 0);
  await page.screenshot({ path: out + '3-resent-ar.png' });
  // open the NEWEST link (the older one is still valid too: the token is stateless)
  const link = links().pop(); const first = links()[0];
  const bad = link.replace(/token=[a-f0-9]+/, 'token=' + '0'.repeat(64));
  const p2 = await ctx.newPage(); await p2.goto(bad); await p2.waitForLoadState('networkidle'); await p2.waitForTimeout(1500);
  console.log('4a tampered link page says:', (await p2.getByText('هذا الرابط لا يعمل').count()) > 0 ? 'invalid (correct)' : 'NOT invalid');
  await p2.screenshot({ path: out + '4a-invalid-link-ar.png' });
  await p2.goto(link.replace('http://127.0.0.1:3030', base)); await p2.waitForLoadState('networkidle'); await p2.waitForTimeout(2000);
  console.log('4b real link page says:', (await p2.getByText('تم تأكيد بريدك الإلكتروني').count()) > 0 ? 'confirmed' : 'NOT confirmed', '| API:', api.filter((x) => /email\/verify|verify-email/.test(x)).join(' | '));
  await p2.screenshot({ path: out + '4b-confirmed-ar.png' });
  // reload the dashboard: banner gone, add patient works
  await page.reload(); await page.waitForLoadState('networkidle'); await page.waitForTimeout(1500);
  console.log('5 banner after reload:', await page.locator('[role=status]', { hasText: 'أكّد بريدك الإلكتروني' }).count() > 0 ? 'STILL SHOWN' : 'gone');
  await page.getByRole('button', { name: 'إضافة مريض' }).first().click().catch(async () => { await page.getByRole('link', { name: 'إضافة مريض' }).first().click(); });
  await page.waitForTimeout(800); await page.fill('input[name=name]', 'مريض تجريبي'); await page.fill('input[name=phone]', '0599123456');
  await page.click('button[type=submit]'); await page.waitForTimeout(2500);
  console.log('6 add-patient answers:', api.filter((x) => /clients$/.test(x)).join(' | '));
  await page.screenshot({ path: out + '6-after-verify-ar.png' });
  await b.close();
})().catch((e) => { console.error(e.message.slice(0, 500)); process.exit(1); });
