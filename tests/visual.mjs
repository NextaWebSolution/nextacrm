import { chromium } from '/opt/npm-tools/node_modules/playwright/index.mjs';
const BASE = 'http://127.0.0.1:8099';
const browser = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const errors = [];
async function session(viewport, dark) {
  const ctx = await browser.newContext({ viewport, colorScheme: dark ? 'dark' : 'light', deviceScaleFactor: 1 });
  const page = await ctx.newPage();
  page.on('console', (m) => { if (m.type() === 'error' || m.type() === 'warning') errors.push(`[${viewport.width}] ${page.url()} :: ${m.text()}`); });
  page.on('pageerror', (e) => errors.push(`[${viewport.width}] ${page.url()} :: PAGEERROR ${e.message}`));
  await page.goto(BASE + '/login');
  await page.fill('#email', 'admin@example.com');
  await page.fill('#password', 'Str0ngPassw0rd!');
  await Promise.all([page.waitForNavigation(), page.click('button[type=submit]')]);
  return { ctx, page };
}
const shot = async (page, name, path, full = false) => {
  await page.goto(BASE + path, { waitUntil: 'networkidle' });
  await page.waitForTimeout(400);
  await page.screenshot({ path: `/home/claude/shots/${name}.png`, fullPage: full });
};
// Desktop light
let { ctx, page } = await session({ width: 1440, height: 900 }, false);
await shot(page, 'd-dashboard', '/', true);
await shot(page, 'd-leads', '/r/leads');
await shot(page, 'd-deals-board', '/r/deals');
await shot(page, 'd-tasks-board', '/r/tasks?view=board');
await shot(page, 'd-project', '/r/projects/1', true);
await shot(page, 'd-invoice', '/r/invoices/1', true);
await shot(page, 'd-ticket', '/r/tickets/1', true);
await shot(page, 'd-lead', '/r/leads/1');
await shot(page, 'd-calendar', '/calendar');
await shot(page, 'd-editor', '/documents/invoice/new');
await shot(page, 'd-settings-roles', '/settings/roles/2');
await shot(page, 'd-storage', '/settings/storage');
await shot(page, 'd-workflow', '/settings/workflows/new');
await shot(page, 'd-report', '/reports/sales');
// interactions: palette, drawer, filter
await page.goto(BASE + '/r/leads'); await page.keyboard.press('Control+k'); await page.keyboard.type('acme'); await page.waitForTimeout(900);
await page.screenshot({ path: '/home/claude/shots/d-palette.png' });
await page.keyboard.press('Escape');
await page.click('main button:has-text("New lead")'); await page.waitForTimeout(900);
await page.screenshot({ path: '/home/claude/shots/d-drawer.png' });
await page.fill('#f_name', 'Visual Test'); await page.fill('#f_email', 'visual@test.com');
await page.click('form[data-entity=leads] button[type=submit]'); await page.waitForTimeout(1200);
await page.screenshot({ path: '/home/claude/shots/d-after-create.png' });
// kanban drag
await page.goto(BASE + '/r/leads?view=board', { waitUntil: 'networkidle' });
const card = page.locator('.kanban-card').first(); const target = page.locator('[data-col="Qualified"] .kanban-cards');
const cb = await card.boundingBox(); const tb = await target.boundingBox();
await page.mouse.move(cb.x + 20, cb.y + 15); await page.mouse.down(); await page.mouse.move(cb.x + 40, cb.y + 30, { steps: 5 });
await page.mouse.move(tb.x + 50, tb.y + 30, { steps: 15 }); await page.mouse.up(); await page.waitForTimeout(1000);
await page.screenshot({ path: '/home/claude/shots/d-after-drag.png' });
await ctx.close();
// Dark desktop
({ ctx, page } = await session({ width: 1440, height: 900 }, true));
await page.evaluate(() => fetch('/x/preferences', { method: 'POST', headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': document.querySelector('meta[name=csrf-token]').content }, body: JSON.stringify({ theme: 'system' }) }));
await shot(page, 'k-dashboard', '/', true);
await shot(page, 'k-deals', '/r/deals');
await shot(page, 'k-invoice', '/r/invoices/1');
await ctx.close();
// Mobile
({ ctx, page } = await session({ width: 390, height: 844 }, false));
await shot(page, 'm-dashboard', '/', true);
await shot(page, 'm-leads', '/r/leads');
await shot(page, 'm-deals', '/r/deals');
await shot(page, 'm-lead', '/r/leads/1', true);
await page.goto(BASE + '/'); await page.click('nav[aria-label="Quick navigation"] button:has-text("More")'); await page.waitForTimeout(400);
await page.screenshot({ path: '/home/claude/shots/m-drawer.png' });
await ctx.close();
// login + installer-like guest pages
const g = await browser.newContext({ viewport: { width: 1280, height: 800 } }); const gp = await g.newPage();
gp.on('console', (m) => { if (m.type() === 'error') errors.push('[guest] ' + m.text()); });
await gp.goto(BASE + '/login'); await gp.screenshot({ path: '/home/claude/shots/g-login.png' });
await browser.close();
console.log('CONSOLE ERRORS:', errors.length); errors.slice(0, 40).forEach((e) => console.log(e));
