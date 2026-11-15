import { chromium } from '/opt/npm-tools/node_modules/playwright/index.mjs';
const BASE = 'http://127.0.0.1:8099';
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const pages = ['/', '/attendance', '/payroll', '/payroll/me', '/approvals', '/sequences', '/reports', '/reports/builder/new', '/r/leads/1', '/r/deals/1', '/r/tickets/1', '/r/projects/1', '/r/quotes/1', '/r/contracts/1', '/r/invoices/1', '/r/contacts/1', '/r/tasks/1', '/r/employees/1',
  '/settings/payments', '/settings/inbox', '/settings/whatsapp', '/settings/approvals', '/settings/ai', '/settings/modules', '/settings/system', '/help'];
const problems = [];
for (const [vp, dark] of [[{ width: 1366, height: 860 }, false], [{ width: 390, height: 844 }, true]]) {
  const ctx = await browser.newContext({ viewport: vp, colorScheme: dark ? 'dark' : 'light' });
  const page = await ctx.newPage();
  let cur = '';
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') problems.push(`[${vp.width}] ${cur} console.${m.type()}: ${m.text().slice(0, 200)}`); });
  page.on('pageerror', (e) => problems.push(`[${vp.width}] ${cur} PAGEERROR ${e.message.slice(0, 200)}`));
  await page.goto(BASE + '/login'); await page.fill('#email', 'admin@example.com'); await page.fill('#password', 'Str0ngPassw0rd!');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  for (const p of pages) {
    cur = p;
    const r = await page.goto(BASE + p, { waitUntil: 'networkidle' });
    await page.waitForTimeout(250);
    if (r.status() >= 400) problems.push(`[${vp.width}] ${p} HTTP ${r.status()}`);
    const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    if (over > 2) problems.push(`[${vp.width}] ${p} horizontal overflow ${over}px`);
    const name = (vp.width < 500 ? 'm' : 'd') + p.replace(/[\/?=]+/g, '_');
    if (['/attendance', '/payroll', '/settings/ai', '/settings/modules', '/r/leads/1', '/reports/builder/new', '/approvals', '/r/tasks/1'].includes(p)) await page.screenshot({ path: `/home/claude/shots/v2/${name}.png`, fullPage: vp.width < 500 });
  }
  await ctx.close();
}
// Portal (separate login) + public pages
const ctx = await browser.newContext({ viewport: { width: 390, height: 844 } });
const page = await ctx.newPage(); let cur = '';
page.on('pageerror', (e) => problems.push(`[portal] ${cur} PAGEERROR ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error') problems.push(`[portal] ${cur} ${m.text().slice(0, 200)}`); });
await page.goto(BASE + '/portal/login'); cur = '/portal/login';
await page.fill('input[name=email]', 'portal-a@example.com'); await page.fill('input[name=password]', 'P0rtalPassw0rd!');
await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
for (const p of ['/portal', '/portal/invoices', '/portal/quotes', '/portal/projects', '/portal/tickets', '/portal/files']) {
  cur = p; const r = await page.goto(BASE + p, { waitUntil: 'networkidle' });
  if (r.status() >= 400) problems.push(`[portal] ${p} HTTP ${r.status()}`);
  const over = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
  if (over > 2) problems.push(`[portal] ${p} overflow ${over}px`);
  if (p === '/portal' || p === '/portal/invoices') await page.screenshot({ path: `/home/claude/shots/v2/portal${p.replace(/\//g, '_')}.png`, fullPage: true });
}
console.log(problems.length ? problems.join('\n') : 'NO PROBLEMS');
await browser.close();
