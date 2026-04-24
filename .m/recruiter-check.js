
const { chromium } = require('playwright');
(async() => {
  const browser = await chromium.launch({ headless: true });
  const cases = [
    { name: 'desktop', viewport: { width: 1440, height: 2200 } },
    { name: 'tablet', viewport: { width: 1024, height: 1800 } },
    { name: 'mobile', viewport: { width: 390, height: 2200 } }
  ];
  for (const c of cases) {
    const page = await browser.newPage({ viewport: c.viewport });
    const consoleMsgs = [];
    page.on('console', msg => consoleMsgs.push(msg.type() + ': ' + msg.text()));
    page.on('pageerror', err => consoleMsgs.push('pageerror: ' + err.message));
    await page.goto('http://127.0.0.1:4173/ung-vien-tuyen-dung.html', { waitUntil: 'networkidle' });
    await page.screenshot({ path: `.m/recruiter-${c.name}.png`, fullPage: true });
    const report = await page.evaluate(() => {
      const $ = s => document.querySelector(s);
      const $$ = s => Array.from(document.querySelectorAll(s));
      const rect = el => {
        if (!el) return null;
        const r = el.getBoundingClientRect();
        return { x: r.x, y: r.y, width: r.width, height: r.height, right: r.right, bottom: r.bottom };
      };
      const style = (el, props) => {
        if (!el) return null;
        const cs = getComputedStyle(el);
        const out = {};
        for (const p of props) out[p] = cs[p];
        return out;
      };
      const shell = $('.jobs-dashboard-shell');
      const sidebar = $('.jobs-dashboard-sidebar');
      const main = $('.jobs-dashboard-main');
      const filterGrid = $('.jobs-recruiter-filter-grid');
      const cards = $$('.jobs-recruiter-application-card').slice(0, 4).map((el, i) => {
        const actions = el.querySelector('.jobs-recruiter-application-actions');
        return {
          i,
          rect: rect(el),
          headRect: rect(el.querySelector('.jobs-recruiter-application-head')),
          bodyRect: rect(el.querySelector('.jobs-recruiter-application-body')),
          actionsRect: rect(actions),
          actionsChildren: actions ? Array.from(actions.children).map(n => ({ tag: n.tagName, text: n.textContent.trim(), rect: rect(n) })) : [],
          text: (el.innerText || '').replace(/\s+/g, ' ').trim().slice(0, 240)
        };
      });
      const overwide = [];
      $$('body *').forEach((el) => {
        const r = el.getBoundingClientRect();
        if (r.width > window.innerWidth + 2) {
          overwide.push({ tag: el.tagName, cls: el.className, width: r.width, text: (el.textContent || '').trim().slice(0, 60) });
        }
      });
      return {
        title: document.title,
        viewport: { width: window.innerWidth, height: window.innerHeight },
        scroll: {
          docW: document.documentElement.scrollWidth,
          clientW: document.documentElement.clientWidth,
          overflowX: document.documentElement.scrollWidth - document.documentElement.clientWidth,
          docH: document.documentElement.scrollHeight
        },
        shellRect: rect(shell),
        shellStyle: style(shell, ['display', 'gridTemplateColumns', 'gap']),
        sidebarRect: rect(sidebar),
        sidebarStyle: style(sidebar, ['position', 'top']),
        mainRect: rect(main),
        filterGridRect: rect(filterGrid),
        filterGridStyle: style(filterGrid, ['display', 'gridTemplateColumns', 'gap']),
        filterFields: filterGrid ? Array.from(filterGrid.children).map(el => ({ label: ((el.querySelector('span') || {}).textContent || '').trim(), rect: rect(el) })) : [],
        focusCardRect: rect($('#recruiterFocusCard')),
        opsGridRect: rect($('.jobs-recruiter-ops-grid')),
        opsCards: $$('.jobs-recruiter-ops-card').map(el => ({ text: (el.querySelector('h3')||{}).textContent || '', rect: rect(el) })),
        dialogHidden: $('#recruiterNoteDialog') ? $('#recruiterNoteDialog').hidden : null,
        shortlistCount: $$('#recruiterShortlistList li').length,
        activityCount: $$('#recruiterActivityFeed li').length,
        cardCount: $$('.jobs-recruiter-application-card').length,
        cards,
        overwide: overwide.slice(0, 20)
      };
    });
    console.log('=== ' + c.name + ' ===');
    console.log(JSON.stringify(report, null, 2));
    if (consoleMsgs.length) {
      console.log('--- console ---');
      console.log(consoleMsgs.join('\n'));
    }
    await page.close();
  }
  await browser.close();
})();
