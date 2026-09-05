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
let allowCommandError = false; // 失败回滚用例注入异常，runner 的 console.error 是预期诊断

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(
    testInfo.project.name !== 'desktop-1440' && testInfo.project.name !== 'mobile-390'
      && testInfo.project.name !== 'tablet-768',
    'multi-select interaction baseline'
  );
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  allowCommandError = false;
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
  if (allowCommandError) {
    const expected = consoleEntries.filter((entry) => entry.includes('[blox command] batch-delete'));
    expect(expected, '注入失败应恰好产生一条命令错误诊断').toHaveLength(1);
    expect(consoleEntries.filter((entry) => !entry.includes('[blox command] batch-delete'))).toEqual([]);
    return;
  }
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

async function makeSameColumn(page, total) {
  await addTemporaryHeading(page, 1);
  const section = await pinLastSection(page);
  for (const expected of Array.from({ length: total - 1 }, (_, i) => i + 2)) {
    await page.getByTestId('blox-library-open').last().click();
    await page.getByTestId('blox-add-element-heading').press('Enter');
    await expect(section.getByTestId('blox-tree-element')).toHaveCount(expected);
  }
  await page.keyboard.press('Escape');
  await waitPreviewSettled(page);
  return section;
}
const makeSameColumnTrio = (page) => makeSameColumn(page, 3);

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

test('batch delete removes five items and a single undo restores them all @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop batch delete baseline');

  const section = await makeSameColumn(page, 5);
  const rows = section.getByTestId('blox-tree-element');
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await rows.nth(4).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  await expect(page.getByTestId('blox-batch-count')).toContainText('5');

  await page.getByTestId('blox-batch-delete').click();
  await expect(page.getByTestId('blox-batch-bar')).toBeHidden();
  await expect(rows).toHaveCount(0);
  await expect(page.getByTestId('blox-toast')).toContainText('5');

  // 一次撤销，5 项全部恢复
  await undo(page);
  await expect(rows).toHaveCount(5);
  await restore(page);
});

test('batch duplicate inserts same-order copies with fresh ids and one-shot undo @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop batch duplicate baseline');

  const section = await makeSameColumn(page, 3);
  const rows = section.getByTestId('blox-tree-element');
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await rows.nth(2).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  const before = await rows.evaluateAll((nodes) => nodes.map((n) => n.getAttribute('data-item-id')));

  await page.getByTestId('blox-batch-duplicate').click();
  await expect(rows).toHaveCount(6);
  await waitPreviewSettled(page);
  const after = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.sections[app.selectedSi].columns[0].elements.map((e) => e.id);
  });
  assertUnique(after);
  // 原件位置不变；副本（新 id）紧随集合最后一项之后
  expect(after.slice(0, 3)).toEqual(before);
  expect(before.includes(after[3])).toBe(false);
  expect(before.includes(after[5])).toBe(false);

  await undo(page);
  await expect(rows).toHaveCount(3);
  await restore(page);
});

function assertUnique(ids) {
  const seen = new Set();
  ids.forEach((id) => {
    expect(seen.has(id)).toBe(false);
    seen.add(id);
  });
}

test('batch cut keeps clipboard order and paste re-inserts with fresh ids @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop cut/paste baseline');

  await addTemporaryHeading(page, 1);
  // 钉住临时区块下标：批量剪切会 deselectAll，selectedSi 不能作为定位依据
  const sectionIndex = await page.evaluate(() => window.Alpine.$data(document.body).sections.length - 1);
  const section = page.getByTestId('blox-tree-section').nth(sectionIndex);
  const rows = section.getByTestId('blox-tree-element');
  const paste = page.getByTestId('blox-batch-paste');
  await expect(paste).toBeDisabled();
  // 补到 4 个同列元素（每次插入会收起元素库，需重开）
  for (const expected of [2, 3, 4]) {
    await page.getByTestId('blox-library-open').last().click();
    await page.getByTestId('blox-add-element-heading').press('Enter');
    await expect(rows).toHaveCount(expected);
  }
  await waitPreviewSettled(page);

  const readColumn = () => page.evaluate((si) => {
    const app = window.Alpine.$data(document.body);
    return app.sections[si].columns[0].elements.map((e) => e.id);
  }, sectionIndex);

  // 剪切第 2、3 项（0 起）
  await rows.nth(1).locator('[data-element-drag-handle]').click();
  await rows.nth(2).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  const before = await readColumn();

  await page.getByTestId('blox-batch-cut').click();
  await expect(rows).toHaveCount(2);
  expect(await readColumn()).toEqual([before[0], before[3]]);
  // 剪贴板非空：批量条保持可见，粘贴可点击（删除/复制/剪切随多选消失而禁用）
  await expect(paste).toBeEnabled();
  await expect(page.getByTestId('blox-batch-delete')).toBeDisabled();

  // 选一个剩余元素作为粘贴目标列上下文（程序化，避免树折叠态的可见性竞态）→ 追加到列尾
  await page.evaluate((si) => window.Alpine.$data(document.body).selectElement(si, 0, 0, false), sectionIndex);
  await expect(paste).toBeVisible();
  await expect(paste).toBeEnabled();
  await page.evaluate(() => window.Alpine.$data(document.body).batchPaste());
  await expect(rows).toHaveCount(4);
  await waitPreviewSettled(page);

  const after = await readColumn();
  // 剩余项原地不动；粘贴项全部是新 id（每个副本重配 id，不与旧 id 冲突），顺序保持剪贴板序
  expect(after[0]).toBe(before[0]);
  expect(after[1]).toBe(before[3]);
  assertUnique(after);
  expect(after.slice(2)).not.toContain(before[1]);
  expect(after.slice(2)).not.toContain(before[2]);

  await undo(page);
  await expect(rows).toHaveCount(2);
  await restore(page);
});

test('process-steps host rules bind batch actions and resync numbers @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'process-steps batch baseline');

  await addTemporaryHeading(page, 1);
  const section = await pinLastSection(page);
  // 插入流程步骤元素（种子 3 步）
  await page.getByTestId('blox-library-open').last().click();
  await page.getByTestId('blox-add-element-process-steps').press('Enter');
  await waitPreviewSettled(page);
  await page.keyboard.press('Escape');

  const hostRow = section.locator('[data-testid="blox-tree-element"][data-element-type="process-steps"]');
  await expect(hostRow).toHaveCount(1);
  const childRows = hostRow.locator('[data-sort-child-item]');
  await expect(childRows).toHaveCount(3);

  const state = () => page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    // 批量删除会 deselectAll：宿主按全文档扫描定位，不依赖 selectedSi
    for (const s of app.sections) {
      for (const c of (s.columns || [])) {
        const host = (c.elements || []).find((e) => e.type === 'process-steps');
        if (host) return {
          ids: (host.data.children || []).map((c2) => c2.id),
          numbers: (host.data.children || []).map((c2) => (c2.data || {}).number),
        };
      }
    }
    return null;
  });
  const childHandles = () => hostRow.locator('[data-child-drag-handle]');

  const undoBefore = await page.evaluate(() => window.Alpine.$data(document.body).canUndo());

  // 全选 3 步批量删除 → 拒绝（至少保留 1 步），不产生撤销项
  await childHandles().nth(0).click();
  await childHandles().nth(2).click({ modifiers: ['Shift'] });
  await page.getByTestId('blox-batch-delete').click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await expect(childRows).toHaveCount(3);
  expect(await page.evaluate(() => window.Alpine.$data(document.body).canUndo())).toBe(undoBefore);

  // 复制到 19 步 → 再次复制被拒（上限 20）。选中在预览重渲染窗口可能未生效：等按钮启用再点。
  const dupButton = page.getByTestId('blox-batch-duplicate');
  let guard = 0;
  while (guard < 15) {
    const n = await page.evaluate(() => {
      const app = window.Alpine.$data(document.body);
      const si = app.selectedSi;
      const host = app.sections[si].columns[0].elements.find((e) => e.type === 'process-steps');
      return host.data.children.length;
    });
    if (n >= 19) break;
    await childHandles().nth(0).click();
    await childHandles().nth(1).click({ modifiers: ['Control'] });
    await expect(dupButton).toBeEnabled({ timeout: 5000 });
    await dupButton.click();
    await page.waitForTimeout(120);
    guard += 1;
  }
  const capped = (await state()).ids.length;
  expect(capped).toBe(19);
  // 满员后再复制 → 拒绝且数量不变
  await childHandles().nth(0).click();
  await childHandles().nth(1).click({ modifiers: ['Control'] });
  await expect(dupButton).toBeEnabled({ timeout: 5000 });
  await dupButton.click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  expect((await state()).ids.length).toBe(capped);

  // 删除中间两步：编号重新连续（auto_number 默认开）
  await childHandles().nth(1).click();
  await childHandles().nth(2).click({ modifiers: ['Shift'] });
  await page.getByTestId('blox-batch-delete').click();
  await expect(page.getByTestId('blox-batch-bar')).toBeHidden();
  const numbers = (await state()).numbers;
  expect(numbers).toEqual(numbers.map((_, i) => String(i + 1).padStart(2, '0')));
  await restore(page);
});

test('banner host flips to custom items after batch paste @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'banner paste baseline');

  // 宿主行挂在其区块展开子树里：先选中该区块
  await ensureTreeVisible(page);
  const bannerSectionIndex = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.sections.findIndex((s) => (s.columns || []).some((c) => (c.elements || []).some((e) => e.type === 'home-block' && String((e.data || {}).block_type || '') === 'banner')));
  });
  expect(bannerSectionIndex).toBeGreaterThanOrEqual(0);
  const bannerSectionRow = page.getByTestId('blox-tree-section').nth(bannerSectionIndex);
  // 种子 Banner 是继承模式（data.children 为空）。注入 3 个子 slide 并保留遗留态：
  // 这正是「有 children 但未切 custom」的数据形态，用于验证批量粘贴后的 items_mode 翻转。
  await page.evaluate((si) => {
    const app = window.Alpine.$data(document.body);
    const col = app.sections[si].columns[0];
    const host = col.elements.find((e) => e.type === 'home-block' && String((e.data || {}).block_type || '') === 'banner');
    host.data = host.data || {};
    host.data.items_mode = '';
    host.data.children = ['a', 'b', 'c'].map((t) => ({ id: 'slide_' + t, type: 'home-banner-item', data: {} }));
  }, bannerSectionIndex);
  await bannerSectionRow.click();
  const hostRow = bannerSectionRow.locator('[data-testid="blox-tree-element"][data-element-type="home-block"][data-home-block-type="banner"]').first();
  await expect(hostRow).toBeVisible();
  await hostRow.click();
  const childHandles = () => hostRow.locator('[data-child-drag-handle]');
  const slideCount = await childHandles().count();
  expect(slideCount).toBeGreaterThanOrEqual(2);

  const itemsMode = () => page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const si = app.selectedSi;
    const col = si >= 0 && app.sections[si] ? app.sections[si].columns[0] : null;
    const host = col ? col.elements.find((e) => e.type === 'home-block' && String((e.data || {}).block_type || '') === 'banner') : null;
    return host ? { mode: (host.data.items_mode || '') || 'inherit', count: (host.data.children || []).length } : null;
  });

  // 批量剪切两枚 slide（批量需 ≥2）→ 剩余 slide 提供子元素上下文 → 粘贴 → items_mode 必须切到 custom
  // 首击普通选择（与既有单选一致）；修饰键点击偶发未注册（CDP 拖拽提升竞态）：未启用则重选。
  const cutButton = page.getByTestId('blox-batch-cut');
  await childHandles().nth(0).click();
  for (let attempt = 0; attempt < 3; attempt += 1) {
    await childHandles().nth(1).click({ modifiers: ['Control'] });
    const enabled = await cutButton.isEnabled().catch(() => false);
    if (enabled) break;
  }
  await expect(cutButton).toBeEnabled({ timeout: 5000 });
  await cutButton.click();
  await expect(childHandles()).toHaveCount(slideCount - 2);
  await expect(page.getByTestId('blox-batch-bar')).toBeVisible();

  // 剩余 slide 建立子元素上下文：剪切会折叠树，先重新展开区块
  await bannerSectionRow.click();
  await expect(childHandles().first()).toBeVisible();
  await childHandles().nth(0).click();
  const paste = page.getByTestId('blox-batch-paste');
  await expect(paste).toBeVisible();
  await expect(paste).toBeEnabled();
  await page.evaluate(() => window.Alpine.$data(document.body).batchPaste());
  await waitPreviewSettled(page);
  const after = await itemsMode();
  expect(after.count).toBe(slideCount);
  expect(after.mode).toBe('custom');
  await restore(page);
});

test('paste without a target context is rejected loudly, not dropped silently @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'paste rejection baseline');

  const section = await makeSameColumn(page, 2);
  const rows = section.getByTestId('blox-tree-element');
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await rows.nth(1).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  await page.getByTestId('blox-batch-cut').click();
  await expect(rows).toHaveCount(0);

  // 取消选择（无粘贴上下文）→ 粘贴必须拒绝并提示，不静默丢弃
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();
  await page.getByTestId('blox-batch-paste').click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await expect(rows).toHaveCount(0);
  await restore(page);
});

test('batch delete failure rolls back document, history and stays clean @ci @shard-core', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'batch rollback baseline');

  const section = await makeSameColumn(page, 3);
  const rows = section.getByTestId('blox-tree-element');
  await rows.nth(0).locator('[data-element-drag-handle]').click();
  await rows.nth(2).locator('[data-element-drag-handle]').click({ modifiers: ['Shift'] });
  const undoBefore = await page.evaluate(() => window.Alpine.$data(document.body).canUndo());

  // 真实失败注入：编排层抛错 → 命令层快照回滚（文档不变、无新历史）
  allowCommandError = true;
  await page.evaluate(() => {
    window.YikaiBloxMultiActions.planBatchAction = function () { throw new Error('boom'); };
  });
  await page.getByTestId('blox-batch-delete').click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await expect(rows).toHaveCount(3);
  expect(await page.evaluate(() => window.Alpine.$data(document.body).canUndo())).toBe(undoBefore);
  // 失败回滚不产生新历史；清理本用例造的数据
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
