/**
 * 页面目录里的「栏目首页与详情页模板」分区：一处可达各自的排版编辑器。
 */
const { test, expect } = require('@playwright/test');

const url = '/admin/page.php?lang=zh-CN&view=cards';

test('structural pages section links channel landings and detail templates to the editor @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await page.goto(url, { waitUntil: 'domcontentloaded' });

  const divider = page.getByTestId('website-structural-divider');
  await expect(divider).toBeVisible();

  // 栏目首页：可排版的（product/list）给「设计排版」直达编辑器
  const cards = page.locator('[data-structural-kind="channel"]');
  expect(await cards.count()).toBeGreaterThan(0);
  const designable = page.locator('[data-structural-kind="channel"] a[href^="/admin/blox_editor.php?id="]');
  expect(await designable.count()).toBeGreaterThan(0);
  const href = await designable.first().getAttribute('href');
  const channelId = Number(new URL(href, 'http://x').searchParams.get('id'));
  expect(channelId).toBeGreaterThan(0);

  // 点进去真能打开编辑器画布
  await designable.first().click();
  await page.waitForSelector('[data-testid="blox-save"]', { timeout: 15000 });
  expect(page.url()).toContain(`/admin/blox_editor.php?id=${channelId}`);

  // 不可排版的栏目类型：说明原因，且不给编辑器链接
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  const blocked = page.locator('[data-structural-kind="channel"].is-disabled');
  if (await blocked.count()) {
    await expect(blocked.first().locator('a[href^="/admin/blox_editor.php"]')).toHaveCount(0);
    await expect(blocked.first()).toContainText('暂不支持排版');
  }

  // 结构卡片只排版，不做启停/删除
  const structural = page.locator('[data-structural-kind]');
  await expect(structural.locator('[data-page-delete]')).toHaveCount(0);

  // 搜索单页时不混入结构分区（否则"共 N 个页面"的计数对不上）
  await page.goto('/admin/page.php?lang=zh-CN&view=cards&q=关于', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('website-structural-divider')).toHaveCount(0);
});
