import { chromium } from '/opt/npm-tools/node_modules/playwright/index.mjs';
const BASE = 'http://127.0.0.1:8099', OUT = '/home/claude/nextacrm/.github/assets/screenshots';
const b = await chromium.launch({ executablePath: '/usr/bin/google-chrome', args: ['--no-sandbox'] });
const c = await b.newContext({ viewport: { width: 1440, height: 900 }, colorScheme: 'light' });
const p = await c.newPage();
await p.goto(BASE + '/login'); await p.fill('#email', 'admin@example.com'); await p.fill('#password', 'Str0ngPassw0rd!');
await Promise.all([p.waitForNavigation(), p.click('button[type=submit]')]);
const go = async (path, name) => { await p.goto(BASE + path, { waitUntil: 'networkidle' }); await p.waitForTimeout(700); await p.screenshot({ path: `${OUT}/${name}.png` }); console.log(name); };
await go('/attendance', 'attendance');
await go('/payroll/1', 'payroll');
await go('/settings/modules', 'modules');
await go('/settings/payments', 'payment-settings');
// report builder: pick module + group, then preview
await p.goto(BASE + '/reports/builder/new', { waitUntil: 'networkidle' }); await p.waitForTimeout(500);
const sel = p.locator('#rb-entity'); const opts = await sel.locator('option').allTextContents();
const di = opts.findIndex((o) => /deal/i.test(o)); if (di >= 0) await sel.selectOption({ index: di });
await p.waitForTimeout(600);
const groupSel = p.locator('#rb-group');
if (await groupSel.count()) { const g = await groupSel.locator('option').allTextContents(); const si = g.findIndex((o) => /stage/i.test(o)); if (si >= 0) await groupSel.selectOption({ index: si }); }
const prev = p.locator('button:has-text("Preview"), button:has-text("Run")').first(); if (await prev.count()) await prev.click();
await p.waitForTimeout(1500); await p.screenshot({ path: `${OUT}/report-builder.png` }); console.log('report-builder');
// public quote with signature pad
const tok = await p.evaluate(async () => '');
await c.close();
// portal
const pc = await b.newContext({ viewport: { width: 1440, height: 900 } }); const pp = await pc.newPage();
await pp.goto(BASE + '/portal/login'); await pp.fill('input[name=email]', 'portal-a@example.com'); await pp.fill('input[name=password]', 'P0rtalPassw0rd!');
await Promise.all([pp.waitForNavigation(), pp.click('button[type=submit]')]);
await pp.goto(BASE + '/portal', { waitUntil: 'networkidle' }); await pp.waitForTimeout(600); await pp.screenshot({ path: `${OUT}/client-portal.png` }); console.log('client-portal');
await b.close();
