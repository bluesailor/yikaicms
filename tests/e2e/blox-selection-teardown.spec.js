// 选中被清空时右侧的区块设置面板要先拆掉、不能对着 null 的 sel 求值：取消选择、撤销刚加的区块、
// 删除选中的区块，以及区块标题字段面板，都不能出现 Alpine Expression Error。只在编辑器里操作，不保存。
const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

function watchAlpineErrors(page) {
  const errors = [];
  page.on('console', (message) => {
    const text = message.text();
    if (text.startsWith('Alpine Expression Error')) errors.push(text.split('\n')[0]);
  });
  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  return errors;
}

// Alpine 先 console.warn，再 setTimeout 抛出同一个错误；让出两轮宏任务后再读。
async function drainErrors(page, errors) {
  await page.evaluate(() => new Promise((resolve) => setTimeout(() => setTimeout(resolve, 0), 0)));
  return errors.splice(0);
}

async function editorState(page, key) {
  return page.evaluate((name) => window.Alpine.$data(document.body)[name], key);
}

test('emptying the selection tears the section panels down without expression errors @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop editor layout');
  const errors = watchAlpineErrors(page);
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page);
  const sections = page.getByTestId('blox-tree-section');
  const containerGrid = page.getByTestId('blox-container-property-grid');
  const sectionGrid = page.getByTestId('blox-section-property-grid');
  const fieldStyle = page.getByTestId('blox-section-field-style');
  const before = await sections.count();
  expect(before).toBeGreaterThan(1);
  expect(await drainErrors(page, errors)).toEqual([]);

  await test.step('clear the selection while the container panel is open', async () => {
    const first = sections.first();
    await first.click();
    await first.getByTestId('blox-tree-container').click();
    await expect(containerGrid).toBeVisible();
    await page.getByTestId('blox-clear-selection').click();
    await expect.poll(() => editorState(page, 'selectedSi')).toBe(-1);
    await expect(containerGrid).toHaveCount(0);
    expect.soft(await drainErrors(page, errors), 'clear selection').toEqual([]);
  });

  await test.step('undo a section that was just added and selected', async () => {
    await page.getByTestId('blox-layout-open').click();
    await page.getByTestId('blox-section-insert-picker').getByTestId('blox-add-section-1').click();
    await expect(sections).toHaveCount(before + 1);
    const added = sections.last();
    await added.click();
    await added.getByTestId('blox-tree-container').click();
    await expect(containerGrid).toBeVisible();
    await expect(page.getByTestId('blox-undo')).toBeEnabled();
    await page.getByTestId('blox-undo').click();
    await expect(sections).toHaveCount(before);
    await expect(containerGrid).toHaveCount(0);
    expect.soft(await drainErrors(page, errors), 'undo added section').toEqual([]);
  });

  await test.step('delete the selected section from its style tab', async () => {
    // 切页签会让面板的 x-if 重新求值；之后它排在子表达式后面，问题才会出现。
    const last = sections.last();
    await last.click();
    await page.getByTestId('blox-style-tab').click();
    await expect(sectionGrid).toBeVisible();
    await expect.poll(() => editorState(page, 'selectedSi')).toBe(before - 1);
    page.once('dialog', (dialog) => dialog.accept());
    await page.keyboard.press('Delete');
    await expect(sections).toHaveCount(before - 1);
    await expect.poll(() => editorState(page, 'selectedSi')).toBe(-1);
    await expect(sectionGrid).toHaveCount(0);
    expect.soft(await drainErrors(page, errors), 'delete selected section').toEqual([]);
  });

  await test.step('clear the selection while a section title field is selected', async () => {
    await page.evaluate(() => {
      const section = window.Alpine.$data(document.body).sections[0];
      section.settings.title = 'E2E teardown title';
      section.settings.subtitle = 'E2E teardown subtitle';
    });
    const first = sections.first();
    // 区块标签也会显示标题文字，只点结构树里的字段节点
    const fieldNode = (text) => first.locator('span:not([data-testid="blox-tree-section-label"])')
      .filter({ hasText: new RegExp(`^${text}$`) });
    await first.click();
    await fieldNode('E2E teardown title').click();
    await expect(fieldStyle).toBeVisible();
    await fieldNode('E2E teardown subtitle').click();
    await expect.poll(() => editorState(page, 'selectedSectionField')).toBe('subtitle');
    await page.getByTestId('blox-clear-selection').click();
    await expect.poll(() => editorState(page, 'selectedSi')).toBe(-1);
    await expect(fieldStyle).toHaveCount(0);
    expect.soft(await drainErrors(page, errors), 'clear section field').toEqual([]);
  });
});
