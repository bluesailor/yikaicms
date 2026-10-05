/**
 * 2.0.5 单页的页面切换动画：网页设置里选「进入本页时」的效果 → 发布 → 前台头部输出该效果；
 * 选回「跟随全站」后恢复全站设置（全站默认关闭时不输出）。
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const app = (page, fn) => page.evaluate(body => new Function('app', `return (${body})(app);`)(window.Alpine.$data(document.body)), fn.toString());

async function choose(page, value) {
  await page.getByTestId('blox-page-frame-open').click();
  await expect(page.getByTestId('blox-page-frame-transition')).toBeVisible();
  await page.getByTestId('blox-page-transition').selectOption(value);
  await page.getByTestId('blox-page-frame-apply').click();
  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|clean|published/, { timeout: 10000 });
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-publish-page').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/, { timeout: 15000 });
}

test('a single page can choose its own page transition @ci', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);
  const frontUrl = await app(page, a => a.pageUrl);
  // 访客单独一个上下文：共用编辑器的 cookie 罐会被前台的匿名会话覆盖，编辑器随之「登录已过期」
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const head = async () => (await (await visitor.request.get(frontUrl + (frontUrl.includes('?') ? '&' : '?') + 't=' + Date.now())).text());

  await choose(page, 'zoom');
  expect(await app(page, a => a.docSettings.page_transition)).toBe('zoom');
  expect(await head()).toContain('data-yk-page-transition="zoom"');

  // 跟随全站（演示站默认关闭）：文档里不留这个键，前台不输出
  await choose(page, '');
  expect(await app(page, a => 'page_transition' in a.docSettings)).toBe(false);
  expect(await head()).not.toContain('data-yk-page-transition');
  await visitor.close();
});
