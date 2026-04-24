
const { chromium } = require('D:\\WORKING\\KetoanThienUng\\Ketoandieutam.com\\node_modules\\playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const pageUrl = 'file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/ung-vien-tuyen-dung.html';
  const cases = [
    { name: 'desktop', viewport: { width: 1440, height: 2200 } },
    { name: 'tablet', viewport: { width: 1024, height: 1800 } },
    { name: 'mobile', viewport: { width: 390, height: 2200 } }
  ];
  for (const c of cases) {
    const context = await browser.newContext({ viewport: c.viewport });
    const page = await context.newPage();
    await page.goto(pageUrl, { waitUntil: 'load' });
    await page.waitForTimeout(2200);
    const initial = await page.evaluate(() => {
      const $ = s => document.querySelector(s);
      const $$ = s => Array.from(document.querySelectorAll(s));
      const rect = el => el ? (()=>{ const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; })() : null;
      const style = (el, props) => el ? (()=>{ const cs=getComputedStyle(el); const o={}; props.forEach(p=>o[p]=cs[p]); return o; })() : null;
      return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        scroll: { docW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth, overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth },
        shellStyle: style(document.querySelector('.jobs-dashboard-shell'), ['gridTemplateColumns','gap']),
        mainStyle: style(document.querySelector('.jobs-dashboard-main'), ['gridTemplateColumns','gridTemplateAreas','gap']),
        railRect: rect(document.querySelector('.jobs-recruiter-side-rail')),
        railStyle: style(document.querySelector('.jobs-recruiter-side-rail'), ['position','top']),
        listRect: rect(document.getElementById('recruiterCandidateList')),
        focusCardRect: rect(document.getElementById('recruiterFocusCard')),
        filterGridStyle: style(document.querySelector('.jobs-recruiter-filter-grid'), ['gridTemplateColumns','gap']),
        filterFields: document.querySelector('.jobs-recruiter-filter-grid') ? Array.from(document.querySelector('.jobs-recruiter-filter-grid').children).map(el => ({ label: ((el.querySelector('span')||{}).textContent||'').trim(), rect: rect(el) })) : [],
        cardCount: document.querySelectorAll('.jobs-recruiter-application-card').length,
        countText: document.getElementById('recruiterCandidateCount')?.textContent.trim()
      };
    });
    await page.click('.jobs-recruiter-manage-btn');
    await page.waitForTimeout(700);
    const afterClick = await page.evaluate(() => ({
      scrollY: window.scrollY,
      focusTop: document.getElementById('recruiterFocusCard')?.getBoundingClientRect().top,
      activeElement: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : '',
      selectedCards: document.querySelectorAll('.jobs-recruiter-application-card.is-selected').length,
      hint: document.getElementById('recruiterFocusHint')?.textContent.trim()
    }));
    console.log('=== ' + c.name + ' ===');
    console.log(JSON.stringify({ initial, afterClick }, null, 2));
    await context.close();
  }
  await browser.close();
})();
