const { test, expect } = require('./site-diagnostics');

for (const lang of ['zh-CN', 'en', 'ja']) {
  test(`native homepage footer has no hover edit overlay but keeps its admin entry (${lang}) @ci`, async ({ page }, info) => {
    await page.goto(`/tests/e2e/frontend-footer-hover-page.php?lang=${lang}`);
    await expect(page.locator('#ik-adminbar')).toBeVisible();
    const footer = page.locator('footer[data-yk-footer]');
    await expect(footer).toBeVisible();
    await footer.scrollIntoViewIfNeeded();
    await footer.hover();
    await expect(page.locator('#yk-edit-outline')).toBeHidden();
    await expect(footer.locator('.yk-logo-btns')).toHaveCount(0);
    const link = footer.locator('a[href]').first();
    await expect(link).toBeVisible();
    await link.hover();
    await expect(page.locator('#yk-edit-outline')).toBeHidden();
    await info.attach('footer-without-hover-overlay', { body: await footer.screenshot(), contentType: 'image/png' });

    const regions = page.getByTestId('admin-edit-regions');
    await regions.locator('summary').click();
    await expect(page.getByTestId('admin-edit-region-menu').locator('a[href="/admin/setting.php?tab=footer"]')).toBeVisible();
  });
}
