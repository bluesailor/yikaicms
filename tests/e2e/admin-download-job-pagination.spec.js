const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'channel-pagination-fixture.php'), action]);
test.beforeAll(() => fixture('seed'));
test.afterAll(() => fixture('cleanup'));
for (const kind of ['download', 'job']) {
  test(`${kind} real pagination preserves status zero and exact result set @ci`, async ({ page }) => {
    await page.goto(`/admin/${kind}.php?per_page=20`);
    const form = page.locator('form').filter({ has: page.locator('[name="keyword"]') });
    const results = page.locator('tbody tr').filter({ hasText: `E2E Paging ${kind}` });
    for (const status of ['1', '0']) {
      await form.locator('[name="keyword"]').fill(`E2E Paging ${kind}`);
      await form.locator('[name="status"]').selectOption(status);
      await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
      await expect(results).toHaveCount(20);
      const titles = () => page.getByText(new RegExp(`^E2E Paging ${kind} ${status} \\d+$`));
      const first = await titles().allTextContents();
      expect(first).toHaveLength(20);
      await page.getByRole('link', { name: '下一页', exact: true }).click();
      expect(new URL(page.url()).searchParams.get('status')).toBe(status);
      expect(new URL(page.url()).searchParams.get('lang')).toBe('zh-CN');
      await expect(results).toHaveCount(2);
      const second = await titles().allTextContents();
      expect([...first, ...second].map(s => s.trim()).sort()).toEqual(
        Array.from({ length: 22 }, (_, i) => `E2E Paging ${kind} ${status} ${i + 1}`).sort());
      await page.reload();
      await expect(results).toHaveCount(2);
      await Promise.all([page.waitForNavigation(), form.locator('[name="per_page"]').selectOption('50')]);
      await expect(results).toHaveCount(22);
      await Promise.all([page.waitForNavigation(), form.locator('[name="per_page"]').selectOption('100')]);
      await expect(results).toHaveCount(22);
      await Promise.all([page.waitForNavigation(), form.locator('[name="per_page"]').selectOption('20')]);
    }
    await page.goto(`/admin/${kind}.php?lang=en`);
    await form.locator('[name="keyword"]').fill('No matching fixture');
    await Promise.all([page.waitForNavigation(), form.locator('button[type="submit"]').click()]);
    expect(new URL(page.url()).searchParams.get('lang')).toBe('en');
    await expect(results).toHaveCount(0);
  });
}
