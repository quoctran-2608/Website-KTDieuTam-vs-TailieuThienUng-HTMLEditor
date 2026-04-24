
const { chromium } = require('playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = browser.contexts()[0] || await browser.newContext({ viewport:{ width:1440, height:2200 } });
  const page = context.pages()[0] || await context.newPage();
  const logs=[];
  page.on('console', msg => logs.push(msg.type()+': '+msg.text()));
  page.on('pageerror', err => logs.push('pageerror: '+err.message));
  await page.setViewportSize({ width:1440, height:2200 });
  await page.goto('http://127.0.0.1:4173/ung-vien-tuyen-dung.html', { waitUntil:'networkidle' });
  const data = await page.evaluate(() => ({
    title: document.title,
    cards: document.querySelectorAll('.jobs-recruiter-application-card').length,
    shell: getComputedStyle(document.querySelector('.jobs-dashboard-shell')).gridTemplateColumns,
    overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
    firstText: document.querySelector('.jobs-recruiter-application-card')?.innerText.slice(0,180),
    footer: !!document.getElementById('siteFooter')
  }));
  console.log(JSON.stringify({data, logs}, null, 2));
  await browser.close();
})();
