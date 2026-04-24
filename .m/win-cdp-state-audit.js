
const { chromium } = require('D:\\WORKING\\KetoanThienUng\\Ketoandieutam.com\\node_modules\\playwright');
(async() => {
  const browser = await chromium.connectOverCDP('http://127.0.0.1:9222');
  const context = await browser.newContext({ viewport: { width: 1440, height: 2200 } });
  const page = await context.newPage();
  async function load(url) {
    await page.goto(url, { waitUntil: 'load' });
    await page.waitForTimeout(2500);
  }
  await load('file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/ung-vien-tuyen-dung.html?status=tu_choi&job_id=ke-toan-noi-bo-cong-ty-tnhh-abc&q=pham');
  const paramsState = await page.evaluate(() => ({
    search: document.getElementById('recruiterCandidateSearch')?.value,
    job: document.getElementById('recruiterCandidateJob')?.value,
    status: document.getElementById('recruiterCandidateStatus')?.value,
    sort: document.getElementById('recruiterCandidateSort')?.value,
    count: document.getElementById('recruiterCandidateCount')?.textContent.trim(),
    cards: document.querySelectorAll('.jobs-recruiter-application-card').length
  }));
  await load('file:///D:/WORKING/KetoanThienUng/Ketoandieutam.com/ung-vien-tuyen-dung.html');
  await page.fill('#recruiterCandidateSearch', 'zzz-khong-ton-tai');
  await page.waitForTimeout(300);
  const emptyState = await page.evaluate(() => ({
    count: document.getElementById('recruiterCandidateCount')?.textContent.trim(),
    emptyHidden: document.getElementById('recruiterCandidateEmpty')?.hidden,
    emptyText: document.getElementById('recruiterCandidateEmpty')?.textContent.trim(),
    focusName: document.getElementById('recruiterFocusName')?.textContent.trim(),
    focusMeta: document.getElementById('recruiterFocusMeta')?.textContent.trim(),
    focusLink: document.getElementById('recruiterFocusProfileLink')?.getAttribute('href'),
    focusStatusValue: document.getElementById('recruiterFocusStatus')?.value,
    focusStatusDisabled: document.getElementById('recruiterFocusStatus')?.disabled,
    shortlistDisabled: document.getElementById('recruiterFocusShortlistBtn')?.disabled,
    noteDisabled: document.getElementById('recruiterFocusNoteBtn')?.disabled,
    selectedCards: document.querySelectorAll('.jobs-recruiter-application-card.is-selected').length,
    visibleCards: document.querySelectorAll('.jobs-recruiter-application-card').length
  }));
  console.log(JSON.stringify({ paramsState, emptyState }, null, 2));
  await context.close();
  await browser.close();
})();
