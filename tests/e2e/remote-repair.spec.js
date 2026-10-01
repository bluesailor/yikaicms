const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'remote-repair-fixture.php'), action], { cwd: root });

// 官方远程修复（2.0.3）：授权开关的说明与本地执行记录对站长可见。
test.beforeAll(() => fixture('seed'));
test.afterAll(() => fixture('cleanup'));

test.beforeEach(({}, info) => test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough for a server-rendered table'));

test('upgrade page shows the remote repair consent and the repair log @ci', async ({ page }) => {
  await page.goto('/admin/upgrade.php?tab=config');
  await expect(page.getByTestId('managed-upgrade-control')).toContainText(/远程升级与修复|upgrade and repair|アップグレードと修復/);
  const log = page.getByTestId('remote-repair-log');
  await expect(log).toBeVisible();
  const rows = log.locator('tbody tr');
  await expect(rows).toHaveCount(2);
  await expect(rows.nth(0)).toContainText('E2E 修栏目别名');
  await expect(rows.nth(0)).toContainText('repair_fix-20261001-e2e00002_20261001_100000.sql');
  await expect(rows.nth(0).locator('td').nth(2)).toHaveClass(/text-red-600/);
  await expect(rows.nth(1)).toContainText('E2E 补跑迁移');
  await expect(rows.nth(1).locator('td').nth(2)).toHaveClass(/text-green-600/);
});
