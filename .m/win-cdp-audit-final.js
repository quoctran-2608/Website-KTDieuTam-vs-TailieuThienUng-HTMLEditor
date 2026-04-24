
const { chromium } = require('D:\\WORKING\\KetoanThienUng\\Ketoandieutam.com\\node_modules\\playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const url='file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/ung-vien-tuyen-dung.html';
  const cases=[{name:'desktop',viewport:{width:1440,height:2200}},{name:'mobile',viewport:{width:390,height:2200}}];
  for (const c of cases) {
    const context=await browser.newContext({viewport:c.viewport});
    const page=await context.newPage();
    await page.goto(url,{waitUntil:'load'});
    await page.waitForTimeout(2200);
    const initial=await page.evaluate(()=>({
      overflowX: document.documentElement.scrollWidth-document.documentElement.clientWidth,
      countText: document.getElementById('recruiterCandidateCount')?.textContent.trim(),
      opsPanelExists: !!document.querySelector('.jobs-recruiter-ops-panel'),
      opsInsideFocus: !!document.querySelector('#recruiterFocusPanel .jobs-recruiter-focus-ops'),
      focusHidden: document.getElementById('recruiterFocusPanel')?.hidden,
      shellColumns: getComputedStyle(document.querySelector('.jobs-dashboard-shell')).gridTemplateColumns,
      mainColumns: getComputedStyle(document.querySelector('.jobs-dashboard-main')).gridTemplateColumns,
      cards: document.querySelectorAll('.jobs-recruiter-application-card').length
    }));
    await page.click('.jobs-recruiter-manage-btn');
    await page.waitForTimeout(500);
    const opened=await page.evaluate(()=>({
      focusHidden: document.getElementById('recruiterFocusPanel')?.hidden,
      shortlistItems: document.querySelectorAll('#recruiterShortlistList li').length,
      activityItems: document.querySelectorAll('#recruiterActivityFeed li').length,
      bodyLocked: document.body.classList.contains('is-recruiter-focus-open')
    }));
    console.log('=== '+c.name+' ===');
    console.log(JSON.stringify({initial,opened},null,2));
    await context.close();
  }
  await browser.close();
})();
