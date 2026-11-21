// Login helper via UI.
module.exports = async function login(page, BASE, email, password, locale = 'ar') {
  await page.goto(`${BASE}/${locale}/login`);
  await page.waitForLoadState('networkidle');
  await page.fill('input[name=email]', email);
  await page.fill('input[name=password]', password);
  await page.click('button[type=submit]');
  await page.waitForURL(/dashboard/, { timeout: 20000 });
  await page.waitForLoadState('networkidle');
};
