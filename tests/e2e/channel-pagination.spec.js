const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const fixture = (file, action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), action], { cwd: root, encoding: 'utf8' });
let ids;
test.beforeAll(() => {
  fixture('catalog-baseline-fixture.php', 'query');
  ids = JSON.parse(fixture('channel-pagination-fixture.php', 'seed'));
});
test.afterAll(() => {
  fixture('channel-pagination-fixture.php', 'cleanup');
  fixture('catalog-baseline-fixture.php', 'restore');
});
for (const mode of ['query', 'pretty']) {
for (const kind of ['case', 'download', 'job']) {
  test(`${kind} ${mode} channel override and inheritance with exact pagination results @ci`, async ({ page }, info) => {
    fixture('catalog-baseline-fixture.php', mode);
    const settings = `/admin/setting.php?tab=pagination&channel_id=${ids[kind]}`;
    await page.goto(settings);
    const field = page.locator('[name="channel_page_size"]');
    await field.fill('8');
    const save = async () => {
      const response = page.waitForResponse(r => r.request().method() === 'POST' && r.url().includes('/admin/setting.php'));
      await page.locator('#channelPaginationForm button[type="submit"]').click();
      expect((await (await response).json()).code).toBe(0);
    };
    await save();
    await page.reload();
    await expect(field).toHaveValue('8');
    if (kind === 'case') await page.screenshot({ path: info.outputPath('channel-pagination.png'), fullPage: true });
    const url = mode === 'query'
      ? `/index.php?yk_route=list&slug=e2e-paging-${kind}&keyword=${encodeURIComponent('E2E Paging ' + kind)}`
      : `/e2e-paging-${kind}.html?keyword=${encodeURIComponent('E2E Paging ' + kind)}`;
    await page.goto(url);
    const title = new RegExp(`^E2E Paging ${kind} 1 \\d+$`);
    const results = kind === 'case' ? page.getByRole('heading', { name: title }) : page.getByText(title);
    const seen = [];
    for (const [index, count] of [8, 8, 6].entries()) {
      if (index) await page.getByRole('link', { name: String(index + 1), exact: true }).click();
      await expect(results).toHaveCount(count);
      seen.push(...(await results.allTextContents()).map(s => s.trim()));
      await expect(page.locator('body')).not.toContainText(`E2E Paging ${kind} 0 `);
    }
    expect(seen.sort()).toEqual(Array.from({ length: 22 }, (_, i) => `E2E Paging ${kind} 1 ${i + 1}`).sort());
    await page.goto(settings);
    await field.fill('');
    await save();
    await page.goto(url);
    await expect(results).toHaveCount(12);
  });
}
}
