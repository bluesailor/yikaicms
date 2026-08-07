const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  canvasScrollTop,
  countCanvasSections,
  countDynamicHomeBlocks,
  countSections,
  dragElement,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
  scrollCanvasToBottom,
  undo,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  consoleEntries = null;
  unsafeWrites = null;
  test.skip(testInfo.project.name !== 'desktop-1440' && testInfo.title !== 'viewport contract @ci', 'desktop interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = await page.getByTestId('blox-dirty').isVisible().catch(() => false);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'save/publish/rollback request was sent').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('viewport contract @ci', async ({ page }, testInfo) => {
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(pageOverflow).toBe(0);

  const contentFrame = await frame(page);
  const frameOverflow = await contentFrame.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(frameOverflow).toBe(0);

  const treeCount = await countSections(page);
  const canvasCount = await countCanvasSections(page);
  const dynamicCount = await countDynamicHomeBlocks(page);
  expect(dynamicCount).toBeGreaterThan(0);
  expect(canvasCount).toBe(treeCount);
  await expect(page.getByTestId('blox-save')).toBeEnabled();
  await expect(page.getByTestId('blox-publish')).toBeEnabled();
  await expect(page.getByTestId('blox-rollback')).toBeDisabled();

  if (testInfo.project.name === 'mobile-390') {
    await expect(page.getByTestId('blox-desktop-actions')).toBeHidden();
    await expect(page.getByTestId('blox-mobile-actions')).toBeVisible();
    await page.getByTestId('blox-mobile-actions-open').click();
    const menu = page.locator('.blox-mobile-actions-menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('button', { name: /撤销/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /重做/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /模板/ })).toBeVisible();
    await expect(menu.getByRole('link', { name: /前台/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /保存/ })).toBeVisible();
  } else {
    await expect(page.getByTestId('blox-desktop-actions')).toBeVisible();
    await expect(page.getByTestId('blox-mobile-actions')).toBeHidden();
  }
});

test('inline edit patches preview and preserves scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const { sectionIndex } = await addTemporaryHeading(page);
  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"] h1, [data-yk-el="${sectionIndex}.0.0"] h2, [data-yk-el="${sectionIndex}.0.0"] h3, [data-yk-el="${sectionIndex}.0.0"] h4`).first();
  const originalText = await heading.innerText();

  await scrollCanvasToBottom(page);
  const scrollBefore = await canvasScrollTop(page);
  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('dblclick', {
    bubbles: true,
    cancelable: true,
  })));
  await expect(heading).toHaveAttribute('contenteditable', /true|plaintext-only/);
  await heading.evaluate((element) => {
    element.textContent = 'E2E 局部预览标题';
    element.dispatchEvent(new element.ownerDocument.defaultView.InputEvent('input', { bubbles: true }));
  });
  await performPreviewUpdate(page, () => heading.evaluate((element) => element.blur()));
  await expect(heading).not.toHaveAttribute('contenteditable', /.+/);
  await expect(page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-element')).toContainText('E2E 局部预览标题');
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText('E2E 局部预览标题');
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  expect(page.url()).toBe(originalURL);

  await undo(page);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText(originalText);
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('unchanged inline blur does not create history @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const contentFrame = await frame(page);
  const field = contentFrame.locator('[data-yk-home-field]').filter({ visible: true }).first();
  await expect(field).toBeVisible();
  const value = await field.innerText();
  await field.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('dblclick', { bubbles: true, cancelable: true })));
  await field.evaluate((element) => element.blur());
  await expect(field).not.toHaveAttribute('contenteditable', /.+/);
  await expect(field).toHaveText(value);
  await expect(page.getByTestId('blox-undo')).toBeDisabled();
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('clipboard copy paste and undo @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page);
  const column = section.getByTestId('blox-tree-column').first();
  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`).first();
  const before = await column.getByTestId('blox-tree-element').count();

  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 24, clientY: 24 })));
  await page.getByTestId('blox-context-copy').click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 24, clientY: 24 })));
  await page.getByTestId('blox-context-paste').click();
  await expect(column.getByTestId('blox-tree-element')).toHaveCount(before + 1);
  await expect(page.getByTestId('blox-toast')).toBeVisible();

  await undo(page);
  await expect(column.getByTestId('blox-tree-element')).toHaveCount(before);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('cross-column drag survives Sortable rebind @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page, 2);
  const columns = section.getByTestId('blox-tree-column');
  const first = columns.nth(0);
  const second = columns.nth(1);
  const contentFrame = await frame(page);

  const expectCounts = async (left, right) => {
    await expect(first.getByTestId('blox-tree-element')).toHaveCount(left);
    await expect(second.getByTestId('blox-tree-element')).toHaveCount(right);
  };

  await expectCounts(1, 0);
  await dragElement(first.getByTestId('blox-tree-element'), second, page);
  await expectCounts(0, 1);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.1.0"]`)).toHaveCount(1);

  await undo(page);
  await expectCounts(1, 0);
  await dragElement(first.getByTestId('blox-tree-element'), second, page);
  await expectCounts(0, 1);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.1.0"]`)).toHaveCount(1);

  await undo(page);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('local template insertion uses catalog resolve without reload @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await page.route('**/admin/blox_template_api.php?action=list**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: 'ok',
        data: {
          items: [{
            key: 'local:1',
            type: 'section',
            name: 'E2E 本地区块',
            source: 'local',
            provider: 'import',
            updated_at: 1,
            paid: false,
            locked: false,
            locked_reason: '',
          }],
          remote_error: '',
        },
      }),
    });
  });
  const originalURL = page.url();
  const before = await countSections(page);
  await page.getByTestId('blox-templates-open').click();
  const item = page.getByTestId('blox-template-item');
  await expect(item).toHaveCount(1);
  await expect(item).toBeEnabled();
  await item.click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]')).toContainText('E2E 模板标题');
  expect(page.url()).toBe(originalURL);
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('real remote template channel @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440' || process.env.BLOX_E2E_REMOTE !== '1', 'opt-in signed remote channel check');
  await page.getByTestId('blox-templates-open').click();
  const remote = page.getByTestId('blox-template-item').filter({ has: page.locator('[class*="ti-cloud-download"]') }).first();
  await expect(remote).toBeVisible();
});

// ── 语言档：编辑器 chrome 三语化验收 ──────────────────────────────
// en：chrome（顶栏+元素库面板）不得含 CJK。ja 无法用「无 CJK」断言——日语汉字
// 与中文同在 CJK 统一表意区间——改为断言日语标记文案已生效。
// 结构树/画布不在断言范围：它们显示用户文档内容（中文示例数据），属合法中文。
const { execFileSync } = require('child_process');
const setAdminLang = (lang) => execFileSync('php', ['tests/e2e/set-lang.php', lang], { cwd: require('path').resolve(__dirname, '../..') });

test('editor chrome localizes to en and ja @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  try {
    setAdminLang('en');
    await page.reload();
    await expect(page.getByTestId('blox-tree')).toBeVisible();
    const headerText = await page.locator('.blox-editor-header').innerText();
    expect(headerText, 'en header must not contain CJK').not.toMatch(/[\u4e00-\u9fff]/);
    // 元素库面板默认展开，直接断言其标题与分类已英语化
    await expect(page.getByText('Element library').first()).toBeVisible();

    setAdminLang('ja');
    await page.reload();
    await expect(page.getByTestId('blox-tree')).toBeVisible();
    await expect(page).toHaveTitle(/エディター/);
    await expect(page.getByText('要素ライブラリ').first()).toBeVisible();
  } finally {
    setAdminLang('zh-CN');
  }
  await page.reload();
  await expect(page.getByTestId('blox-tree')).toBeVisible();
});
