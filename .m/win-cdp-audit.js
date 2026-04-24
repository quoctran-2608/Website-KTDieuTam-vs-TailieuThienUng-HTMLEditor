
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
    const logs = [];
    page.on('console', msg => logs.push(msg.type()+': '+msg.text()));
    page.on('pageerror', err => logs.push('pageerror: '+err.message));
    await page.goto(pageUrl, { waitUntil: 'load' });
    await page.waitForTimeout(2500);
    const initial = await page.evaluate(() => {
      const $ = s => document.querySelector(s);
      const $$ = s => Array.from(document.querySelectorAll(s));
      const rect = el => el ? (()=>{ const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; })() : null;
      const style = (el, props) => el ? (()=>{ const cs=getComputedStyle(el); const o={}; props.forEach(p=>o[p]=cs[p]); return o; })() : null;
      return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        scroll: { docW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth, overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth },
        shellRect: rect($('.jobs-dashboard-shell')),
        shellStyle: style($('.jobs-dashboard-shell'), ['display','gridTemplateColumns','gap']),
        sidebarRect: rect($('.jobs-dashboard-sidebar')),
        sidebarStyle: style($('.jobs-dashboard-sidebar'), ['position','top']),
        filterGridRect: rect($('.jobs-recruiter-filter-grid')),
        filterGridStyle: style($('.jobs-recruiter-filter-grid'), ['display','gridTemplateColumns','gap']),
        filterFields: $('.jobs-recruiter-filter-grid') ? Array.from($('.jobs-recruiter-filter-grid').children).map(el => ({ label: ((el.querySelector('span')||{}).textContent||'').trim(), rect: rect(el) })) : [],
        listRect: rect($('#recruiterCandidateList')),
        focusCardRect: rect($('#recruiterFocusCard')),
        opsGridRect: rect($('.jobs-recruiter-ops-grid')),
        opsCards: $$('.jobs-recruiter-ops-card').map(el => ({ heading: (el.querySelector('h3')||{}).textContent || '', rect: rect(el) })),
        cards: $$('.jobs-recruiter-application-card').length,
        countText: ($('#recruiterCandidateCount')||{}).textContent || '',
        emptyHidden: $('#recruiterCandidateEmpty') ? $('#recruiterCandidateEmpty').hidden : null,
        menuLinks: $$('.jobs-dashboard-menu a').map(a => a.textContent.trim())
      };
    });
    await page.click('.jobs-recruiter-manage-btn');
    await page.waitForTimeout(700);
    const afterClick = await page.evaluate(() => ({
      scrollY: window.scrollY,
      focusHint: (document.getElementById('recruiterFocusHint')||{}).textContent || '',
      focusName: (document.getElementById('recruiterFocusName')||{}).textContent || '',
      focusStatus: (document.getElementById('recruiterFocusStatus')||{}).value || '',
      activeElement: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : '',
      selectedCards: document.querySelectorAll('.jobs-recruiter-application-card.is-selected').length,
      focusCardTop: document.getElementById('recruiterFocusCard') ? document.getElementById('recruiterFocusCard').getBoundingClientRect().top : null
    }));
    console.log('=== ' + c.name + ' ===');
    console.log(JSON.stringify({ initial, afterClick, logs }, null, 2));
    await context.close();
  }
  await browser.close();
})();
