const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {test, expect} = require('./site-diagnostics');

// 产品导入 2.0：上传导入包 → 预览查出误用大类拼图的主图 → 跳过一行 → 分批导入 → 整批撤销 → 从 FTP 目录再选一次
const root = process.cwd();
const fixture = (action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'product-import-fixture.php'), action], {cwd: root, encoding: 'utf8'}).trim();

test('an import package is checked, imported in batches and undone @local', async ({page}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one desktop run is enough');
  const zipPath = fixture('package');

  await page.goto('/admin/plugin_page.php?plugin=product-import');
  const wizard = page.locator('[data-pimp-wizard]');
  await expect(wizard).toBeVisible();
  await page.locator('[data-pimp-file]').setInputFiles(zipPath);

  const preview = page.locator('[data-pimp-preview]');
  await expect(preview).toBeVisible();
  await expect(preview.locator('[data-pimp-summary="error"] .text-2xl')).toHaveText('1');
  await expect(preview.locator('[data-pimp-summary="create"] .text-2xl')).toHaveText('3');
  // 有错误时先只列出错的行：主图就是大类的展会拼图
  const bad = preview.locator('[data-pimp-row="2"]');
  await expect(bad).toBeVisible();
  await expect(bad.locator('.text-red-600').first()).toContainText('e2e-washer');
  await expect(preview.locator('[data-pimp-row="0"]')).toBeHidden();

  await preview.locator('[data-pimp-filter="all"]').click();
  const thumb = preview.locator('[data-pimp-row="0"] img');
  await expect.poll(() => thumb.evaluate((img) => img.complete && img.naturalWidth)).toBeGreaterThan(0);
  await preview.locator('[data-pimp-decision="3"]').selectOption('skip');
  await preview.locator('[data-pimp-start]').click();

  const job = page.locator('[data-pimp-job]');
  await expect(job.locator('[data-pimp-count="created"] .text-xl')).toHaveText('2');
  await expect(job.locator('[data-pimp-count="error"] .text-xl')).toHaveText('1');
  await expect(job.locator('[data-pimp-count="skipped"] .text-xl')).toHaveText('1');
  await expect(job.locator('[data-pimp-count="categories_created"] .text-xl')).toHaveText('2');

  let state = JSON.parse(fixture('state'));
  expect(state.categories).toEqual(['e2e-washer', 'e2e-washer-flat']);
  expect(state.products.map((p) => p.title)).toEqual(['E2E Flat washer DIN125', 'E2E Spring washer']);
  expect(state.products.every((p) => p.cover_exists && p.param_block)).toBe(true);
  expect(state.products.map((p) => p.import_key)).toEqual(['e2e-1', 'e2e-2']);

  page.once('dialog', (dialog) => dialog.accept());
  await job.locator('[data-pimp-undo]').click();
  await expect(job.locator('[data-pimp-undo]')).toBeHidden();
  state = JSON.parse(fixture('state'));
  expect(state).toEqual({products: [], categories: []});
  await expect(page.locator('[data-pimp-history] tr')).not.toHaveCount(0);

  // 大包走 FTP：incoming/ 里的文件夹直接选
  await job.getByRole('button').last().click();
  await wizard.locator('li', {hasText: 'e2e-washer'}).getByRole('button').click();
  await expect(preview.locator('[data-pimp-summary="create"] .text-2xl')).toHaveText('3');
  await expect(page.locator('[data-pimp-error]')).toBeHidden();
});
