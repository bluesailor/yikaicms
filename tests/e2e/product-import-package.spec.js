const path = require('node:path');
const {execFileSync} = require('node:child_process');
const {test, expect} = require('./site-diagnostics');

// 产品导入 2.0：上传导入包 → 预览查出误用大类拼图的主图 → 跳过一行 → 分批导入 → 导入后检查
// → 从本站导出再导入（全部无变化）→ 从历史里整批撤销 → 从 FTP 目录再选一次
const root = process.cwd();
const fixture = (action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'product-import-fixture.php'), action], {cwd: root, encoding: 'utf8'}).trim();

test('an import package is checked, imported, synced back and undone @ci', async ({page}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one desktop run is enough');
  const zipPath = fixture('package');

  await page.goto('/admin/product_import.php');
  const wizard = page.locator('[data-pimp-wizard]');
  await expect(wizard).toBeVisible();
  await page.locator('[data-pimp-file]').setInputFiles(zipPath);

  const preview = page.locator('[data-pimp-preview]');
  await expect(preview).toBeVisible();
  await expect(preview.locator('[data-pimp-summary="error"] .text-2xl')).toHaveText('2');
  await expect(preview.locator('[data-pimp-summary="create"] .text-2xl')).toHaveText('3');
  // 有错误时先只列出错的行：两款的主图都是大类的展会拼图
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
  await expect(job.locator('[data-pimp-count="error"] .text-xl')).toHaveText('2');
  await expect(job.locator('[data-pimp-count="skipped"] .text-xl')).toHaveText('1');
  await expect(job.locator('[data-pimp-count="categories_created"] .text-xl')).toHaveText('2');
  await expect(job.locator('[data-pimp-verify]')).toHaveClass(/bg-green-50/);

  let state = JSON.parse(fixture('state'));
  expect(state.categories).toEqual(['e2e-washer', 'e2e-washer-flat']);
  expect(state.products.map((p) => p.title)).toEqual(['E2E Flat washer DIN125', 'E2E Spring washer']);
  expect(state.products.every((p) => p.cover_exists && p.param_block)).toBe(true);
  expect(state.products.map((p) => p.import_key)).toEqual(['e2e-1', 'e2e-2']);

  // 从本站导出这个大类再导入：两款都与本站相同，默认全部跳过
  await job.getByRole('button').last().click();
  const exportBox = page.locator('[data-pimp-export]');
  await exportBox.locator('summary').click();
  await exportBox.locator('[data-pimp-export-category="e2e-washer"]').check();
  const downloadPromise = page.waitForEvent('download');
  await exportBox.locator('[data-pimp-export-link]').click();
  const exported = await (await downloadPromise).path();
  await page.locator('[data-pimp-file]').setInputFiles({name: 'export.zip', mimeType: 'application/zip', buffer: require('node:fs').readFileSync(exported)});
  await expect(preview.locator('[data-pimp-summary="unchanged"] .text-2xl')).toHaveText('2');
  await expect(preview.locator('[data-pimp-summary="update"] .text-2xl')).toHaveText('2');
  await expect(preview.locator('[data-pimp-start]')).toBeDisabled();

  // 从历史里打开第一批并撤销
  await page.locator('[data-pimp-history] tr').last().getByRole('button').click();
  page.once('dialog', (dialog) => dialog.accept());
  await job.locator('[data-pimp-undo]').click();
  await expect(job.locator('[data-pimp-undo]')).toBeHidden();
  state = JSON.parse(fixture('state'));
  expect(state).toEqual({products: [], categories: []});

  // 大包走 FTP：incoming/ 里的文件夹直接选
  await job.getByRole('button').last().click();
  await wizard.locator('li', {hasText: 'e2e-washer'}).getByRole('button').click();
  await expect(preview.locator('[data-pimp-summary="create"] .text-2xl')).toHaveText('3');
  await expect(page.locator('[data-pimp-error]')).toBeHidden();
});

// 2.3.0：产品导入并入主程序。从 2.2.x 升级上来的站点旧插件目录可能还在、数据库里还标着启用——
// 不能出现第二个「导入产品」菜单，旧地址转到新页面，插件列表里不再列出它
test('an upgraded site with the old import plugin still has one entry and old links keep working @ci', async ({page}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one desktop run is enough');
  expect(fixture('legacy')).toBe('ok');

  await page.goto('/admin/index.php');
  const html = await page.content();
  expect(html).not.toContain('LEGACY-IMPORT-MENU');
  expect(html).not.toContain('plugin_page.php?plugin=product-import');
  await expect(page.locator('a[href$="/admin/product_import.php"]').first()).toBeAttached();

  // 旧书签：转到新页面，不执行旧插件的页面
  await page.goto('/admin/plugin_page.php?plugin=product-import&tab=table');
  await expect(page).toHaveURL(/\/admin\/product_import\.php\?tab=table$/);
  await expect(page.locator('[data-pimp-tab="table"]')).toHaveAttribute('aria-selected', 'true');
  expect(await page.content()).not.toContain('LEGACY-IMPORT-PAGE');

  // 产品列表页有入口
  await page.goto('/admin/product.php');
  await page.getByTestId('product-import-entry').click();
  await expect(page.locator('[data-pimp-wizard]')).toBeVisible();

  // 插件列表不再列出它
  await page.goto('/admin/plugin.php');
  expect(await page.content()).not.toContain('plugin=product-import');
});
