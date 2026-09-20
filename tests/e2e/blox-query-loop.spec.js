const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const {
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  openPageEditor,
  waitPreviewSettled,
} = require('./helpers');

// list-dynamic 退役回归（2026-09-20 冻结）：palette 不再提供新增入口，但
// ① 存量文档必须一直正常渲染（老 JSON / 备份恢复不炸）；
// ② 已有实例仍可选中编辑（1–2 版本后才议移除编辑器支持）；
// ③ 两个编辑器的新增入口都不得再出现该元素。
// 存量文档由 fixture 预置——这正是冻结后的现实路径。

const run = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'list-dynamic-legacy-fixture.php'), ...args.map(String)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

let fixture;
let consoleEntries;
let unsafeWrites;

test.beforeAll(() => {
  fixture = JSON.parse(run('seed'));
});

test.afterAll(() => {
  run('cleanup');
});

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop legacy regression baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
});

test.afterEach(async () => {
  if (!consoleEntries || !unsafeWrites) return;
  expect(unsafeWrites, 'legacy regression must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('legacy list-dynamic document still renders on the frontend @ci', async ({ page }) => {
  await page.goto(fixture.url, { waitUntil: 'domcontentloaded' });
  // 循环模板逐条渲染（limit 2 → 第一页两条）
  await expect(page.getByText('TB-LD Legacy Alpha', { exact: true })).toBeVisible();
  await expect(page.getByText('TB-LD Legacy Beta', { exact: true })).toBeVisible();
  // 数字分页存在且第二页可达（3 条 / limit 2）
  const pageTwo = page.locator('a[href*="="]', { hasText: '2' }).first();
  await expect(pageTwo).toBeVisible();
  await pageTwo.click();
  await expect(page.getByText('TB-LD Legacy Gamma', { exact: true })).toBeVisible();
});

test('existing list-dynamic stays editable while the palette hides it @ci', async ({ page }) => {
  test.setTimeout(60_000);
  await openPageEditor(page, fixture.page);
  await waitPreviewSettled(page);

  // 选中存量元素：先点所属区块把树展开（blox-about-inheritance 同款手法），控件照常（存量编辑支持未撤）
  const legacyEl = page.locator('[data-testid="blox-tree-element"][data-element-type="list-dynamic"]').first();
  await legacyEl.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]')
    .locator('[data-section-drag-handle]').first().click();
  await legacyEl.locator('[data-element-drag-handle]').click();
  const professional = page.getByTestId('blox-professional-features');
  if (!(await professional.evaluate((node) => node.open))) await professional.locator('summary').click();
  await page.getByTestId('blox-professional-query_loop').click();
  await expect(page.locator('[data-control-key="pagination_mode"] select')).toBeVisible();
  await expect(page.locator('[data-control-key="pagination_mode"] select')).toHaveValue('numbers');

  // 冻结合同：元素库不再提供 list-dynamic 瓦片（顶层与搜索都找不到）
  await page.getByTestId('blox-library-open').last().click();
  await expect(page.getByTestId('blox-add-element-list-dynamic')).toHaveCount(0);
  await expect(page.getByTestId('blox-add-element-heading')).toBeVisible(); // 库本身工作正常
});

test('home editor palette does not offer list-dynamic either @ci', async ({ page }) => {
  await openEditor(page);
  // 库按钮选中区块后才可见：先点首个区块
  await page.getByTestId('blox-tree-section').first().locator('[data-section-drag-handle]').first().click();
  await page.getByTestId('blox-library-open').last().click();
  await expect(page.getByTestId('blox-add-element-heading')).toBeVisible();
  await expect(page.getByTestId('blox-add-element-list-dynamic')).toHaveCount(0);
});
