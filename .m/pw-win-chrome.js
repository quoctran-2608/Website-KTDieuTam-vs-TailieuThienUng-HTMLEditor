
const { chromium } = require('playwright');
(async() => {
  const browser = await chromium.launch({
    headless: true,
    executablePath: '/mnt/c/Program Files/Google/Chrome/Application/chrome.exe'
  });
  const page = await browser.newPage({ viewport: { width: 1440, height: 2200 } });
  const logs=[];
  page.on('console', msg => logs.push(msg.type()+': '+msg.text()));
  page.on('pageerror', err => logs.push('pageerror: '+err.message));
  await page.goto('http://127.0.0.1:4173/ung-vien-tuyen-dung.html', { waitUntil: 'networkidle' });
  const data = await page.evaluate(() => ({
    title: document.title,
    cards: document.querySelectorAll('.jobs-recruiter-application-card').length,
    shell: getComputedStyle(document.querySelector('.jobs-dashboard-shell')).gridTemplateColumns,
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    firstText: document.querySelector('.jobs-recruiter-application-card')?.innerText.slice(0,180)
  }));
  console.log(JSON.stringify({data, logs}, null, 2));
  await browser.close();
})();
