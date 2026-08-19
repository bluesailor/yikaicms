const { test, expect } = require('@playwright/test');

async function submitForm(page, form) {
  // 同 blox-default-areas：waitForNavigation 竞态换成 POST 响应等待 + 落地。
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

test('Home navigation settings live in the menu manager @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop menu management baseline');

  await page.goto('/admin/nav_menu.php', { waitUntil: 'domcontentloaded' });
  const row = page.getByTestId('nav-menu-home-row');
  await expect(row).toBeVisible();
  await expect(row.locator('a[href="/admin/setting_home.php"]')).toHaveCount(0);
  await expect(row.locator('input[type="checkbox"]')).toHaveCount(2);
  await expect(row.locator('.nm-drag')).toBeVisible();
  await expect(row.locator('.nm-move')).toHaveCount(2);

  const rootList = page.locator('.nm-nav-sort[data-nm-parent="0"]');
  const readOrder = () => rootList.locator(':scope > .nm-menu-item').evaluateAll(items => items.map(item => item.dataset.id));
  const originalOrder = await readOrder();
  if (originalOrder.length > 1) {
    const homeIndex = originalOrder.indexOf('home');
    const direction = homeIndex < originalOrder.length - 1 ? '1' : '-1';
    const waitForSort = () => page.waitForResponse(response =>
      response.url().endsWith('/admin/nav_menu.php')
      && response.request().method() === 'POST'
      && (response.request().postData() || '').includes('sort_nav')
    );
    await Promise.all([waitForSort(), row.locator(`.nm-move[data-dir="${direction}"]`).click()]);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect.poll(readOrder).not.toEqual(originalOrder);

    await Promise.all([
      waitForSort(),
      row.locator(`.nm-move[data-dir="${direction === '1' ? '-1' : '1'}"]`).click(),
    ]);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect.poll(readOrder).toEqual(originalOrder);
  }

  await row.locator('[data-nm-home-edit]').click();
  let form = page.getByTestId('nav-menu-home-form');
  let input = page.getByTestId('nav-menu-home-label');
  await expect(form).toBeVisible();
  await expect(input).toBeFocused();
  const original = await input.inputValue();
  const changed = 'E2E Home Menu';

  try {
    await input.fill(changed);
    await submitForm(page, form);
    await expect(page.getByTestId('nav-menu-saved')).toBeVisible();
    await expect(page.getByTestId('nav-menu-home-row')).toContainText(changed);

    await page.goto('/admin/setting_home.php', { waitUntil: 'domcontentloaded' });
    await expect(page.locator('[name="settings[nav_home_text]"]')).toHaveCount(0);
    await expect(page.locator('[name="settings[nav_home_show]"]')).toHaveCount(0);
    await expect(page.locator('[name="home_footer_nav"]')).toHaveCount(0);
    await expect(page.getByTestId('classic-home-blox-state')).toBeVisible();
    await expect(page.locator('[data-testid="classic-home-blox-open"], [data-testid="classic-home-blox-convert"]')).toHaveCount(1);
  } finally {
    await page.goto('/admin/nav_menu.php', { waitUntil: 'domcontentloaded' });
    await page.getByTestId('nav-menu-home-row').locator('[data-nm-home-edit]').click();
    form = page.getByTestId('nav-menu-home-form');
    input = page.getByTestId('nav-menu-home-label');
    await input.fill(original);
    await submitForm(page, form);
  }
});
