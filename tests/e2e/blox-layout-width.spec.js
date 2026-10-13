// 布局操作（v2.1.0，参照 Bricks）：顶栏「添加布局」与画布同一个选择器；1/4 单栏在画布里真的占四分之一，
// 列面板改「位置」后居中；整行宽度的按钮写成「满宽」，比例写成分数。只在编辑器里操作，不保存。
const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { frame, openPageEditor, performPagePreviewUpdate, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function lastColumnBox(page) {
  return (await frame(page)).evaluate(() => {
    const sections = document.querySelectorAll('[data-yk-sec]');
    const column = sections[sections.length - 1].querySelector('[data-yk-col]');
    // 按父元素的内容区量（整行时父元素是带内边距的容器）
    const parent = column.parentElement;
    const style = getComputedStyle(parent);
    const rect = parent.getBoundingClientRect();
    const start = rect.left + parseFloat(style.paddingLeft);
    const end = rect.right - parseFloat(style.paddingRight);
    const box = column.getBoundingClientRect();
    return { ratio: box.width / (end - start), left: box.left - start, right: end - box.right, span: column.getAttribute('data-yk-col-span') };
  });
}

test('toolbar layout picker adds a quarter-width column that really takes a quarter @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Canvas geometry baseline at desktop width');
  await openPageEditor(page, fixtures.blox_page);
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();
  await waitPreviewSettled(page);
  const sections = page.getByTestId('blox-tree-section');
  const before = await sections.count();

  await page.getByTestId('blox-layout-open').click();
  const picker = page.getByTestId('blox-section-insert-picker');
  await expect(picker).toBeVisible();
  await expect(picker.getByTestId('blox-add-section-ratio-4-8')).toBeVisible();
  await picker.screenshot({ path: info.outputPath('layout-picker.png') });
  await performPagePreviewUpdate(page, () => picker.getByTestId('blox-add-section-ratio-3').click());
  await expect(sections).toHaveCount(before + 1);
  await waitPreviewSettled(page);

  await expect.poll(async () => (await lastColumnBox(page)).span).toBe('3');
  const quarter = await lastColumnBox(page);
  expect(quarter.ratio).toBeGreaterThan(0.23);
  expect(quarter.ratio).toBeLessThan(0.27);
  expect(quarter.left).toBeLessThan(2);

  await sections.last().getByTestId('blox-tree-column').first().locator(':scope > div').first().click();
  await expect(page.getByTestId('blox-column-span-12')).toContainText(/满宽|Full width|全幅/);
  await expect(page.getByTestId('blox-column-span-3')).toContainText('1/4');
  await expect(page.getByTestId('blox-column-span-3')).toContainText('25%');
  await expect(page.getByTestId('blox-column-position')).toBeVisible();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-position-center').click());
  await expect.poll(async () => {
    const box = await lastColumnBox(page);
    return Math.abs(box.left - box.right) < 2 && box.left > 10;
  }).toBe(true);
  await expect(page.getByTestId('blox-column-span-visual')).toHaveClass(/justify-center/);
  await page.screenshot({ path: info.outputPath('column-panel.png') });

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-span-12').click());
  await expect(page.getByTestId('blox-column-position')).toBeHidden();
  await expect.poll(async () => (await lastColumnBox(page)).ratio).toBeGreaterThan(0.98);

  await sections.last().getByTestId('blox-tree-container').click();
  await expect(page.getByTestId('blox-breadcrumb')).not.toContainText('blox_el_container');
  await expect(page.getByTestId('blox-layout-apply-hint')).toBeVisible();
  await page.screenshot({ path: info.outputPath('layout-width.png') });

  // 不保存、不撤销，直接结束：编辑器没有自动保存，改动不会落盘。撤销会先选回新区块再把它删掉，
  // 设置面板开着时选中项变空会刷出一串 Alpine 报错——2.1.0 之前就有的问题，另行处理。
});
