/**
 * 页面目录（卡片视图）：已停用页排在最后、明晰标识、可就地恢复/删除（带确认）。
 * 用真实创建的非系统页走完整链路：停用 → 分组/外观断言 → 取消删除 → 恢复 → 再停用 → 真删除。
 * 系统页的删除红线由单元测试覆盖（卡面不出删除按钮）。
 */
const { test, expect } = require('@playwright/test');

const url = '/admin/page.php?lang=zh-CN&view=cards';

async function reloadAfterToggle(page) {
  await page.waitForURL('**/admin/page.php**');
  await page.waitForLoadState('domcontentloaded');
}

test('disabled pages group after active ones with clear state and inline restore/delete @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await page.goto(url, { waitUntil: 'domcontentloaded' });

  // 建一个非系统页（走真实 create 接口；CSRF 由后台 fetch 拦截器注入）
  const id = await page.evaluate(async () => {
    const body = new FormData();
    body.append('action', 'create');
    body.append('name', '停用验收页');
    body.append('parent_id', '0');
    const response = await fetch('', { method: 'POST', body });
    const data = await response.json();
    if (data.code !== 0) throw new Error('create failed: ' + (data.msg || ''));
    return Number(data.data.id);
  });
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  const card = page.getByTestId(`website-page-card-${id}`);
  await expect(card).toBeVisible();

  // 启用卡：卡面没有删除入口；"…"菜单里是「停用」
  await expect(card.locator('[data-page-delete]')).toHaveCount(0);
  await card.locator('.website-page-more summary').click();
  await card.locator('.website-page-more button').click();
  await reloadAfterToggle(page);

  // 停用后：分隔条出现，卡片移到分隔条之后并带明确停用外观
  const divider = page.getByTestId('website-pages-disabled-divider');
  await expect(divider).toBeVisible();
  const disabledCard = page.getByTestId(`website-page-card-${id}`);
  await expect(disabledCard).toHaveClass(/is-disabled/);
  await expect(disabledCard).toHaveAttribute('data-page-disabled', '1');
  await expect(disabledCard.locator('.website-page-badges .is-off')).toBeVisible();
  const order = await page.evaluate((cardId) => {
    const dividerEl = document.querySelector('[data-testid="website-pages-disabled-divider"]');
    const cardEl = document.querySelector(`[data-testid="website-page-card-${cardId}"]`);
    return {
      card: dividerEl.compareDocumentPosition(cardEl) & Node.DOCUMENT_POSITION_FOLLOWING ? 'after' : 'before',
      disabledBeforeDivider: [...document.querySelectorAll('[data-page-disabled]')].filter(
        el => dividerEl.compareDocumentPosition(el) & Node.DOCUMENT_POSITION_PRECEDING
      ).length,
    };
  }, id);
  expect(order.card).toBe('after');
  expect(order.disabledBeforeDivider).toBe(0);

  // 删除必须过确认；取消后页面仍在
  const deleteButton = page.getByTestId(`page-delete-${id}`);
  await expect(deleteButton).toBeVisible();
  page.once('dialog', dialog => dialog.dismiss());
  await deleteButton.click();
  await page.waitForTimeout(300);
  await expect(page.getByTestId(`website-page-card-${id}`)).toBeVisible();

  // 就地恢复：回到启用组
  await page.getByTestId(`page-restore-${id}`).click();
  await reloadAfterToggle(page);
  await expect(page.getByTestId(`website-page-card-${id}`)).not.toHaveClass(/is-disabled/);

  // 再停用并真删除：卡片消失；没有其它停用页时分隔条一并消失
  const activeCard = page.getByTestId(`website-page-card-${id}`);
  await activeCard.locator('.website-page-more summary').click();
  await activeCard.locator('.website-page-more button').click();
  await reloadAfterToggle(page);
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId(`page-delete-${id}`).click();
  await expect(page.getByTestId(`website-page-card-${id}`)).toBeHidden({ timeout: 10000 });
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId(`website-page-card-${id}`)).toHaveCount(0);
  await expect(page.getByTestId('website-pages-disabled-divider')).toHaveCount(0);
});
