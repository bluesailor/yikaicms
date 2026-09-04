const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  editorHasChanges,
  expectClean,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  undo,
  waitPreviewSettled,
} = require('./helpers');

let consoleEntries = null;
let unsafeWrites = null;

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(
    testInfo.project.name !== 'desktop-1440' && testInfo.project.name !== 'mobile-390'
      && testInfo.project.name !== 'tablet-768',
    'multi-select interaction baseline'
  );
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const inEditor = new URL(page.url()).pathname.endsWith('/admin/blox_editor.php');
  const leakedDirtyState = inEditor && await editorHasChanges(page);
  if (leakedDirtyState) {
    for (let i = 0; i < 20 && await editorHasChanges(page); i += 1) {
      if (!await page.getByTestId('blox-undo').isEnabled()) break;
      await undo(page);
    }
    await expectClean(page);
  }
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'save/publish/rollback request was sent').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

async function restore(page) {
  for (let i = 0; i < 20 && await editorHasChanges(page); i += 1) {
    if (!await page.getByTestId('blox-undo').isEnabled()) break;
    await undo(page);
  }
  await expectClean(page);
}

// 一个区块的一条列里放 3 个标题元素（同一父级，可同级多选），返回该区块的元素行集合。
// 注意：插入即选中并收起元素库，所以每次插入前都要重新打开。
// .last() 是惰性定位器：新建区块后会指向更新的区块。用创建时的索引钉住。
async function pinLastSection(page) {
  const sections = page.getByTestId('blox-tree-section');
  return sections.nth(await sections.count() - 1);
}

async function makeSameColumnTrio(page) {
  await addTemporaryHeading(page, 1);
  const section = await pinLastSection(page);
  for (const expected of [2, 3]) {
    await page.getByTestId('blox-library-open').last().click();
    await page.getByTestId('blox-add-element-heading').press('Enter');
    await expect(section.getByTestId('blox-tree-element')).toHaveCount(expected);
  }
  await page.keyboard.press('Escape');
  await waitPreviewSettled(page);
  return section;
}

async function multiState(row) {
  return row.getAttribute('data-multi-selected');
}

test('tree shift and ctrl clicks build a same-level set with batch bar @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop pointer multi-select baseline');

  const section = await makeSameColumnTrio(page);
  const rows = section.getByTestId('blox-tree-element');
  const batchBar = page.getByTestId('blox-batch-bar');
  const batchCount = page.getByTestId('blox-batch-count');

  // 普通点击 = 单选，与现状一致：无批量条、无多选标记。
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await expect(batchBar).toBeHidden();
  expect(await multiState(rows.nth(0))).toBe('0');

  // shift+click：从锚点选到点击项（文档顺序），批量条显示计数。
  await rows.nth(2).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  await expect(batchBar).toBeVisible();
  await expect(batchCount).toContainText('3');
  for (const i of [0, 1, 2]) expect(await multiState(rows.nth(i))).toBe('1');

  // ctrl+click：区间内反向点击在锚点模式之外会先展开，再 ctrl 增减单项。
  await rows.nth(1).locator('[data-element-drag-handle]').click({ modifiers: ['Control'] });
  await expect(batchCount).toContainText('2');
  expect(await multiState(rows.nth(1))).toBe('0');
  await rows.nth(1).locator('[data-element-drag-handle]').click({ modifiers: ['Control'] });
  await expect(batchCount).toContainText('3');
  expect(await multiState(rows.nth(1))).toBe('1');

  // Esc 清空多选，回到普通单选视图。
  await page.keyboard.press('Escape');
  await expect(batchBar).toBeHidden();
  for (const i of [0, 1, 2]) expect(await multiState(rows.nth(i))).toBe('0');

  // 跨父级：另一区块的元素 ctrl+click 后集合以新点击项重新开始，不合并。
  // 注意：addTemporaryHeading 会选中新区块，三元素区块在树里折叠——先点回它展开行。
  await addTemporaryHeading(page, 1);
  const otherSection = await pinLastSection(page);
  await section.click();
  await expect(rows.nth(0)).toBeVisible();
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await rows.nth(2).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  await expect(batchBar).toBeVisible();
  const otherRow = otherSection.getByTestId('blox-tree-element');
  await otherSection.click();
  await expect(otherRow).toBeVisible();
  await otherRow.locator('[data-element-drag-handle]').click({ modifiers: ['Control'] });
  await expect(batchBar).toBeHidden();
  expect(await multiState(otherRow)).toBe('0');
  for (const i of [0, 1, 2]) expect(await multiState(rows.nth(i))).toBe('0');

  await page.keyboard.press('Escape');
  await restore(page);
});

test('canvas ctrl clicks mirror the selection set onto the canvas @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop canvas multi-select baseline');

  const section = await makeSameColumnTrio(page);
  const contentFrame = await frame(page);
  const batchBar = page.getByTestId('blox-batch-bar');

  // data-yk-el 包裹层是 display:contents，真实鼠标坐标不可靠；
  // 在 iframe 内对三个元素派发带 Ctrl 修饰键的 click，走同一条画布→编辑器多选链路。
  const paths = await page.evaluate((sectionId) => {
    const app = window.Alpine.$data(document.body);
    const si = app.sections.findIndex((s) => s.id === sectionId);
    const col = app.sections[si] && app.sections[si].columns[0];
    return col ? col.elements.map((el) => si + '.0.' + app.sections[si].columns[0].elements.indexOf(el)) : [];
  }, await section.getAttribute('data-section-id'));
  expect(paths.length).toBe(3);

  await contentFrame.evaluate((paths) => {
    paths.forEach((path) => {
      const node = document.querySelector('[data-yk-el="' + path + '"]');
      node.dispatchEvent(new MouseEvent('click', { bubbles: true, cancelable: true, ctrlKey: true }));
    });
  }, paths);

  await expect(batchBar).toBeVisible();
  await expect(contentFrame.locator('.yk-multi-selected')).toHaveCount(3);
  await expect(page.getByTestId('blox-batch-count')).toContainText('3');

  // Esc（画布内按键透传）清空多选并撤销描边。
  await contentFrame.evaluate(() => {
    document.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
  });
  await expect(batchBar).toBeHidden();
  await expect(contentFrame.locator('.yk-multi-selected')).toHaveCount(0);
  await restore(page);
});

test('section rows multi-select at document root @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop section multi-select baseline');

  await addTemporaryHeading(page, 1);
  const sections = page.getByTestId('blox-tree-section');
  const count = await sections.count();
  expect(count).toBeGreaterThanOrEqual(2);
  const batchBar = page.getByTestId('blox-batch-bar');

  await sections.nth(count - 2).click();
  await sections.nth(count - 1).click({ modifiers: ['Shift'] });
  await expect(batchBar).toBeVisible();
  await expect(sections.nth(count - 2)).toHaveAttribute('data-multi-selected', '1');
  await expect(sections.nth(count - 1)).toHaveAttribute('data-multi-selected', '1');

  await page.keyboard.press('Escape');
  await expect(batchBar).toBeHidden();
  await restore(page);
});

// 窄屏（768/390）无修饰键：点选永远是单选，批量条与多选描边不得出现。
async function ensureTreeVisible(page) {
  const row = page.getByTestId('blox-tree-section').first();
  if (await row.isVisible()) return;
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.count()) {
    await structure.click();
    await expect(row).toBeVisible();
  }
}

test('touch path keeps single select without fake multi state @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'mobile-390' && testInfo.project.name !== 'tablet-768', 'narrow touch baseline');

  await ensureTreeVisible(page);
  const sections = page.getByTestId('blox-tree-section');
  const batchBar = page.getByTestId('blox-batch-bar');
  await expect(batchBar).toBeAttached();
  await expect(batchBar).toBeHidden();

  await sections.first().click();
  await expect(batchBar).toBeHidden();
  await expect(sections.first()).toHaveAttribute('data-multi-selected', '0');
  await expect(page.locator('[data-multi-selected="1"]')).toHaveCount(0);

  const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(overflow).toBe(0);
  await restore(page);
});
