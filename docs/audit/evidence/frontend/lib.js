// Shared Playwright helpers for the FE audit. Records console errors + failed requests per page.
const { chromium } = require('/home/user/Frontend-Nextjs/node_modules/playwright');
const path = require('path');
const fs = require('fs');
const OUT = __dirname;
const BASE = process.env.FE_BASE || 'http://127.0.0.1:3000';
const log = [];
async function open({ phone = false } = {}) {
  const browser = await chromium.launch();
  const context = await browser.newContext({
    viewport: phone ? { width: 390, height: 844 } : { width: 1280, height: 800 },
    deviceScaleFactor: 2,
  });
  const page = await context.newPage();
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') log.push({ url: page.url(), kind: 'console.' + m.type(), text: m.text().slice(0, 300) }); });
  page.on('pageerror', (e) => log.push({ url: page.url(), kind: 'pageerror', text: String(e).slice(0, 300) }));
  page.on('requestfailed', (r) => log.push({ url: page.url(), kind: 'requestfailed', text: r.method() + ' ' + r.url() + ' ' + (r.failure() || {}).errorText }));
  page.on('response', (r) => { if (r.status() >= 400) log.push({ url: page.url(), kind: 'http' + r.status(), text: r.request().method() + ' ' + r.url() }); });
  return { browser, context, page };
}
async function shot(page, name, opts = {}) {
  const file = path.join(OUT, name + '.png');
  await page.screenshot({ path: file, fullPage: false, ...opts });
  return file;
}
function dumpLog(name) { fs.writeFileSync(path.join(OUT, name + '-console.json'), JSON.stringify(log, null, 1)); return log; }
module.exports = { open, shot, dumpLog, BASE, OUT, log };
