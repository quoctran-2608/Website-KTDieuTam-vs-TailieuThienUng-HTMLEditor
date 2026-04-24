
const { chromium } = require('D:\\WORKING\\KetoanThienUng\\Ketoandieutam.com\\node_modules\\playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const url = 'file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/tai-khoan-ung-vien.html';
  const cases = [
    {name:'desktop', viewport:{width:1440,height:2200}},
    {name:'mobile', viewport:{width:390,height:2200}}
  ];
  for (const c of cases) {
    const context = await browser.newContext({ viewport: c.viewport });
    const page = await context.newPage();
    const logs=[];
    page.on('console', msg => logs.push(msg.type()+': '+msg.text()));
    page.on('pageerror', err => logs.push('pageerror: '+err.message));
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(1800);
    const data = await page.evaluate(() => {
      const $ = s => document.querySelector(s);
      const rect = el => el ? (() => { const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; })() : null;
      return {
        title: document.title,
        bodyClass: document.body.className,
        overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        hero: rect($('.jobs-hero')),
        mainPanel: rect($('.jobs-dashboard-panel')),
        h1: ($('h1')||{}).textContent || '',
        h2Count: document.querySelectorAll('h2').length,
      };
    });
    console.log('=== '+c.name+' ===');
    console.log(JSON.stringify({data, logsCount: logs.length, logs: logs.slice(0,10)}, null, 2));
    await context.close();
  }
  await browser.close();
})();
