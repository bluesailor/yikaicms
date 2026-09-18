const { test, expect } = require('@playwright/test');
const {
  countSections,
  openSectionInsertAtEnd,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop Query Loop interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  // TASK-002 第 1 项：状态位现在始终有文案（未修改/已发布…），"脏"改看 data-state 而非可见性
  const leakedDirtyState = (await page.getByTestId('blox-dirty').getAttribute('data-state').catch(() => null)) === 'dirty';
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'Query Loop E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('Query Loop exposes pagination and child fallback controls @ci', async ({ page }) => {
  test.setTimeout(60_000);
  const before = await countSections(page);
  await openSectionInsertAtEnd(page);
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);

  await page.getByTestId('blox-library-open').last().click();
  await page.getByTestId('blox-add-element-list-dynamic').press('Enter');
  // 高级控件只在专业功能页签显示；专业功能区默认折叠，循环面板由 yikai-builder 作者端模块提供。
  const openQueryLoop = async () => {
    const professional = page.getByTestId('blox-professional-features');
    if (!(await professional.evaluate((node) => node.open))) await professional.locator('summary').click();
    await page.getByTestId('blox-professional-query_loop').click();
  };
  await openQueryLoop();
  const pagination = page.locator('[data-control-key="pagination_mode"] select');
  await expect(pagination).toBeVisible();

  let previewRequest;
  await performPreviewUpdate(page, async () => {
    const request = page.waitForRequest((candidate) => {
      const url = new URL(candidate.url());
      const body = new URLSearchParams(candidate.postData() || '');
      // 插入元素时的在途预览可能晚到；只认已携带本次分页选择的那一次预览。
      return candidate.method() === 'POST'
        && url.pathname === '/admin/blox_preview.php'
        && body.get('action') === 'preview'
        && /"pagination_mode":"numbers"/.test(body.get('blocks_data') || '');
    });
    await pagination.selectOption('numbers');
    previewRequest = await request;
  });
  const previewBody = new URLSearchParams(previewRequest.postData() || '');
  const document = JSON.parse(previewBody.get('blocks_data'));
  const sections = Array.isArray(document) ? document : document.sections;
  const loop = sections[sections.length - 1].columns[0].elements[0];
  expect(loop.data.pagination_mode).toBe('numbers');

  await page.getByTestId('blox-library-open').last().click();
  await page.getByTestId('blox-add-element-heading').press('Enter');
  await openQueryLoop();
  await expect(page.locator('[data-control-key="loop_fallback"] input')).toBeVisible();
  await expect(page.locator('[data-control-key="site_field"]')).toHaveCount(0);
  await performPreviewUpdate(page, () => page.locator('[data-control-key="loop_fallback"] input').fill('Untitled'));
  await page.getByTestId('blox-content-tab').click();
  // 标题的循环字段绑定由标题面板的绑定弹层提供（heading-binding-field.php），不再是通用 select 控件。
  await page.getByTestId('blox-heading-text-binding').click();
  await expect(page.getByTestId('blox-heading-text-source')).toBeVisible();
  await expect(page.locator('[data-control-key="site_field"]')).toHaveCount(0);

  await restoreClean(page);
  await expect(page.getByTestId('blox-dirty')).not.toHaveAttribute('data-state', 'dirty');
});
