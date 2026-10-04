// 2.0.4 Tabs：网址定位到选项卡（深链）与自动轮播。渲染结果来自隔离夹具，页面由路由拦截提供真实网址（深链要用 location.hash）。
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');

const root = path.resolve(__dirname, '../..');
const rendered = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [
  path.join(__dirname, 'fixtures/tabs.php'),
], { encoding: 'utf8' }));
const css = fs.readFileSync(path.join(root, 'assets/css/yikay-tabs.css'), 'utf8');
const js = fs.readFileSync(path.join(root, 'assets/js/yikay-tabs.js'), 'utf8');
test.use({ storageState: { cookies: [], origins: [] } });

async function open(page, html, hash = '') {
  await page.route('http://tabs.test/**', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html><head><meta charset="utf-8"><style>${css}</style></head>`
      + `<body><div style="height:1600px">spacer</div><main style="max-width:720px">${html}</main>`
      + `<div style="height:1600px"></div><script>${js}</script></body></html>`,
  }));
  await page.goto('http://tabs.test/page' + hash);
}

const selected = page => page.locator('[role="tab"][aria-selected="true"]');

test('a #prefix-title or #prefix-number link opens that tab and clicks update the URL @ci', async ({ page }) => {
  await open(page, rendered.deep, '#plans-features');
  await expect(selected(page)).toHaveText('Features');
  await expect(page.locator('[role="tabpanel"]:not([hidden])')).toHaveText('Features panel');
  expect(await page.evaluate(() => window.scrollY)).toBeGreaterThan(1000);

  await page.locator('[role="tab"]', { hasText: '服务' }).click();
  expect(new URL(page.url()).hash).toBe('#plans-3');
  await page.locator('[role="tab"]', { hasText: 'Overview' }).click();
  expect(new URL(page.url()).hash).toBe('#plans-overview');

  await page.evaluate(() => { window.location.hash = 'plans-2'; });
  await expect(selected(page)).toHaveText('Features');
});

test('autoplay advances with a progress bar, pauses on hover and stops after a click @ci', async ({ page }) => {
  await open(page, rendered.autoplay);
  const tabs = page.locator('[data-blox-tabs]');
  await expect(tabs).toHaveAttribute('data-tabs-running', '');
  await expect(selected(page)).toHaveText('Overview');
  await expect(selected(page)).toHaveText('Features', { timeout: 4000 });

  await tabs.hover();
  await expect(tabs).toHaveAttribute('data-tabs-paused', '');
  await page.waitForTimeout(2500);
  await expect(selected(page)).toHaveText('Features');
  await page.mouse.move(5, 5);
  await expect(tabs).not.toHaveAttribute('data-tabs-paused', '');

  const toggle = page.locator('[data-tabs-autoplay-toggle]');
  await expect(toggle).toHaveAttribute('aria-label', /./);
  await page.locator('[role="tab"]', { hasText: 'Overview' }).click();
  await expect(tabs).not.toHaveAttribute('data-tabs-running', '');
  await expect(toggle).toBeHidden();
  await page.mouse.move(5, 5);
  await page.waitForTimeout(2500);
  await expect(selected(page)).toHaveText('Overview');
});

test('reduced motion keeps autoplay off @ci', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await open(page, rendered.autoplay);
  await expect(page.locator('[data-blox-tabs]')).not.toHaveAttribute('data-tabs-running', '');
  await expect(page.locator('[data-tabs-autoplay-toggle]')).toBeHidden();
});
