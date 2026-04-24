
const { chromium } = require('D:\\WORKING\\KetoanThienUng\\Ketoandieutam.com\\node_modules\\playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const url='file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/ung-vien-tuyen-dung.html';
  const cases=[
    {name:'desktop',viewport:{width:1440,height:2200}},
    {name:'tablet',viewport:{width:1024,height:1800}},
    {name:'mobile',viewport:{width:390,height:2200}}
  ];
  for (const c of cases) {
    const context=await browser.newContext({viewport:c.viewport});
    const page=await context.newPage();
    await page.goto(url,{waitUntil:'load'});
    await page.waitForTimeout(2300);
    const pre = await page.evaluate(() => {
      const first=document.querySelector('.jobs-recruiter-application-card');
      return {
        overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        firstText: first ? first.innerText.replace(/\s+/g,' ').trim().slice(0,240) : '',
        firstJobLabel: first?.querySelector('.jobs-recruiter-application-job .jobs-dashboard-note')?.textContent || '',
        firstJobLink: first?.querySelector('.jobs-recruiter-application-job .job-source-link')?.textContent || '',
      };
    });
    await page.click('.jobs-recruiter-manage-btn');
    await page.waitForTimeout(600);
    const post = await page.evaluate(() => {
      const panel=document.getElementById('recruiterFocusPanel');
      const focusOps=document.querySelector('#recruiterFocusPanel .jobs-recruiter-focus-ops');
      const opsGrid=document.querySelector('#recruiterFocusPanel .jobs-recruiter-ops-grid');
      const over=[];
      panel.querySelectorAll('*').forEach(el=>{
        const r=el.getBoundingClientRect();
        if (r.right - panel.getBoundingClientRect().right > 1) {
          over.push({tag:el.tagName, cls:el.className, text:(el.textContent||'').trim().slice(0,40), diff:Math.round((r.right-panel.getBoundingClientRect().right)*10)/10});
        }
      });
      const r=panel.getBoundingClientRect();
      return {
        panelRect:{x:r.x,y:r.y,width:r.width,height:r.height},
        panelScroll:{clientW:panel.clientWidth,scrollW:panel.scrollWidth,clientH:panel.clientHeight,scrollH:panel.scrollHeight},
        panelStyle:{display:getComputedStyle(panel).display,position:getComputedStyle(panel).position,zIndex:getComputedStyle(panel).zIndex},
        focusOpsRect: focusOps ? (()=>{const rr=focusOps.getBoundingClientRect(); return {width:rr.width,height:rr.height};})() : null,
        opsGridCols: opsGrid ? getComputedStyle(opsGrid).gridTemplateColumns : null,
        opsCardWidths: opsGrid ? Array.from(opsGrid.children).map(el=>el.getBoundingClientRect().width) : [],
        overInside: over.slice(0,10),
        bodyLocked: document.body.classList.contains('is-recruiter-focus-open')
      };
    });
    console.log('=== '+c.name+' ===');
    console.log(JSON.stringify({pre, post}, null, 2));
    await context.close();
  }
  await browser.close();
})();
