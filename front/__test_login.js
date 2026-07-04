const { chromium } = require('./node_modules/playwright');

(async () => {
  const browser = await chromium.launch({ headless: false, slowMo: 500 });
  const page = await browser.newPage();

  await page.goto('http://localhost:5173/auth/login');
  await page.evaluate(() => { localStorage.clear(); sessionStorage.clear(); });
  await page.reload();
  await page.waitForLoadState('networkidle');
  console.log('Initial URL:', page.url());

  await page.fill('input[type="email"], input[name="email"]', 'lucasgrzina+1@gmail.com');
  await page.fill('input[type="password"], input[name="password"]', 'Valen2010!');
  console.log('Credentials filled, submitting...');
  await page.click('button[type="submit"]');

  await page.waitForURL(url => !url.toString().includes('/auth/login'), { timeout: 10000 });
  console.log('After submit URL:', page.url());
  await page.waitForTimeout(2000);
  console.log('FINAL URL:', page.url());

  await browser.close();
})();
