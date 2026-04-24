
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
    const data = await page.evaluate(() => {
      const $ = s => document.querySelector(s);
      const $$ = s => Array.from(document.querySelectorAll(s));
      const rect = el => el ? (()=>{ const r=el.getBoundingClientRect(); return {x:r.x,y:r.y,width:r.width,height:r.height,right:r.right,bottom:r.bottom}; })() : null;
      const style = (el, props) => el ? (()=>{ const cs=getComputedStyle(el); const o={}; props.forEach(p=>o[p]=cs[p]); return o; })() : null;
      const list = $('#recruiterCandidateList');
      const cards = $$('.jobs-recruiter-application-card').slice(0,3).map(el => ({
        rect: rect(el),
        actionRects: Array.from(el.querySelectorAll('.jobs-recruiter-application-actions > *')).map(n => ({ text: n.textContent.trim(), rect: rect(n) })),
        text: (el.innerText||'').replace(/\s+/g,' ').trim().slice(0,220)
      }));
      return {
        viewport: { width: window.innerWidth, height: window.innerHeight },
        scroll: { docW: document.documentElement.scrollWidth, clientW: document.documentElement.clientWidth, overflowX: document.documentElement.scrollWidth-document.documentElement.clientWidth, docH: document.documentElement.scrollHeight },
        shell: { rect: rect($('.jobs-dashboard-shell')), style: style($('.jobs-dashboard-shell'), ['gridTemplateColumns','gap']) },
        main: { rect: rect($('.jobs-dashboard-main')), style: style($('.jobs-dashboard-main'), ['gridTemplateColumns','gridTemplateAreas','gap']) },
        filter: { rect: rect($('#recruiterCandidateFilterForm')), grid: style($('.jobs-recruiter-filter-grid'), ['gridTemplateColumns','gap']) },
        list: { rect: rect(list) },
        rail: { rect: rect($('.jobs-recruiter-side-rail')), style: style($('.jobs-recruiter-side-rail'), ['position','top']) },
        focus: { rect: rect($('#recruiterFocusCard')), style: style($('#recruiterFocusCard'), ['position']) },
        ops: { rect: rect($('.jobs-recruiter-ops-grid')), style: style($('.jobs-recruiter-ops-grid'), ['gridTemplateColumns','gap']) },
        cards,
        countText: $('#recruiterCandidateCount')?.textContent.trim(),
        menuHeight: rect($('.jobs-dashboard-menu'))?.height,
        breadcrumbsHeight: rect($('.jobs-breadcrumbs'))?.height
      };
    });
    await page.click('.jobs-recruiter-manage-btn');
    await page.waitForTimeout(500);
    const after = await page.evaluate(() => ({
      scrollY: window.scrollY,
      focusTop: document.getElementById('recruiterFocusCard')?.getBoundingClientRect().top,
      activeElement: document.activeElement ? (document.activeElement.id || document.activeElement.tagName) : '',
      selected: document.querySelectorAll('.jobs-recruiter-application-card.is-selected').length
    }));
    console.log('=== ' + c.name + ' ===');
    console.log(JSON.stringify({ data, after }, null, 2));
    await context.close();
  }
  await browser.close();
})();
