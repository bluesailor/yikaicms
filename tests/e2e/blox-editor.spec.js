const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  canvasScrollTop,
  countCanvasSections,
  countDynamicHomeBlocks,
  countSections,
  dragElement,
  editorHasChanges,
  expectClean,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  openPageEditor,
  performPagePreviewUpdate,
  performPreviewUpdate,
  waitPreviewSettled,
  restoreClean,
  scrollCanvasToBottom,
  undo,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

async function pointerClick(page, locator, clickCount = 1) {
  await expect(locator).toBeVisible();
  // Playwright 的 actionability 对 zoom 后的 iframe 会把可见按钮判成 viewport 外；
  // 先让 iframe 自己滚到目标。原生 locator 点击能命中时优先使用。
  await locator.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'center' }));
  await page.waitForTimeout(50);
  try {
    await locator.click({ clickCount, timeout: 1500 });
    return;
  } catch (_) {
    // CSS zoom 的已知 Playwright 坐标边界。旧回退把帧内坐标映射回父页面再
    // page.mouse.click，zoom 取整误差会打偏小按钮（快捷添加按钮实证脱靶，
    // 插入目标丢失）；改为帧内合成事件——bridge 的 click/dblclick 监听在
    // document 上，合成事件确定命中目标元素本身。
  }
  await locator.evaluate((element, count) => {
    const rect = element.getBoundingClientRect();
    const base = {
      bubbles: true, cancelable: true, composed: true,
      clientX: rect.x + rect.width / 2, clientY: rect.y + rect.height / 2,
      view: element.ownerDocument.defaultView,
    };
    for (let i = 0; i < count; i++) {
      element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('mousedown', { ...base, detail: i + 1 }));
      element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('mouseup', { ...base, detail: i + 1 }));
      element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('click', { ...base, detail: i + 1 }));
    }
    if (count === 2) {
      element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('dblclick', { ...base, detail: 2 }));
    }
  }, clickCount);
}

async function dragPaletteToTree(page, source, target, ratio, intent, label, valid = '1') {
  const point = await target.evaluate((node, targetRatio) => {
    const rect = node.getBoundingClientRect();
    return {
      x: rect.left + rect.width / 2,
      y: rect.top + rect.height * targetRatio,
    };
  }, ratio);
  const transfer = await page.evaluateHandle(() => new DataTransfer());
  await source.dispatchEvent('dragstart', { dataTransfer: transfer });
  try {
    await target.dispatchEvent('dragover', {
      dataTransfer: transfer,
      clientX: point.x,
      clientY: point.y,
    });
    const indicator = page.locator(
      `[data-testid="blox-tree-drop-indicator"][data-drop-intent="${intent}"][data-drop-valid="${valid}"]:visible`
    );
    await expect(indicator).toBeVisible();
    await expect(indicator).toHaveText(label);
    await target.dispatchEvent('drop', {
      dataTransfer: transfer,
      clientX: point.x,
      clientY: point.y,
    });
  } finally {
    await source.dispatchEvent('dragend', { dataTransfer: transfer }).catch(() => {});
    await transfer.dispose();
  }
}

test.beforeEach(async ({ page }, testInfo) => {
  consoleEntries = null;
  unsafeWrites = null;
  const crossViewportTitles = [
    'viewport contract @ci',
    'header preset chooser adapts across viewports @ci',
    'footer style library previews and applies practical starters @ci',
    'shared color picker adapts across viewports @ci',
  ];
  test.skip(testInfo.project.name !== 'desktop-1440' && !crossViewportTitles.includes(testInfo.title), 'desktop interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const inEditor = new URL(page.url()).pathname.endsWith('/admin/blox_editor.php');
  const leakedDirtyState = inEditor && await editorHasChanges(page);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'save/publish/rollback request was sent').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('viewport contract @ci', async ({ page }, testInfo) => {
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(pageOverflow).toBe(0);

  const deviceButtons = page.locator('[data-testid^="blox-device-"]');
  await expect(deviceButtons).toHaveCount(3);
  const deviceMetrics = await deviceButtons.evaluateAll((buttons) => buttons.map((button) => {
    const rect = button.getBoundingClientRect();
    return {
      left: rect.left,
      right: rect.right,
      top: rect.top,
      bottom: rect.bottom,
      state: button.getAttribute('data-responsive-state'),
      overrides: button.getAttribute('data-responsive-overrides'),
      title: button.getAttribute('title'),
    };
  }));
  expect(deviceMetrics.every((item) => item.left >= 0 && item.right <= page.viewportSize().width)).toBe(true);
  expect(deviceMetrics.every((item) => item.top >= 0 && item.bottom <= page.viewportSize().height)).toBe(true);
  expect(deviceMetrics.every((item) => ['inherit', 'override'].includes(item.state))).toBe(true);
  expect(deviceMetrics.every((item) => /^\d+$/.test(item.overrides || ''))).toBe(true);
  expect(deviceMetrics.every((item) => (item.title || '').trim() !== '')).toBe(true);

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

  if (testInfo.project.name !== 'desktop-1440') {
    await expect(page.getByTestId('blox-left-panel-resizer')).toBeHidden();
    await expect(page.getByTestId('blox-right-panel-resizer')).toBeHidden();
    await expect(page.getByTestId('blox-right-panel-toggle')).toBeHidden();
    await expect(page.getByTestId('blox-desktop-actions')).toBeHidden();
    await expect(page.getByTestId('blox-mobile-actions')).toBeVisible();
    await page.getByTestId('blox-mobile-actions-open').click();
    const menu = page.locator('.blox-mobile-actions-menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('button', { name: /撤销/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /重做/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /添加元素/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /预制区块/ })).toBeVisible();
    await expect(menu.getByRole('link', { name: /前台/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /保存/ })).toBeVisible();
    await expect(page.getByTestId('blox-mobile-preview-retry')).toBeHidden();
    const actionHeights = await menu.locator(':scope > button:visible, :scope > a:visible').evaluateAll((items) => items.map((item) => item.getBoundingClientRect().height));
    expect(Math.min(...actionHeights)).toBeGreaterThanOrEqual(44);
    if (testInfo.project.name !== 'mobile-390') return;
    await menu.getByRole('button', { name: /预制区块/ }).click();
    const templateDialog = page.locator('[x-ref="templateDialog"]');
    await expect(templateDialog).toBeVisible();
    await expect(page.getByTestId('blox-template-search')).toBeFocused();
    await expect(page.getByTestId('blox-template-category')).toBeVisible();
    const firstTemplateImage = page.locator(
      '[data-testid="blox-template-item"][data-template-key^="builtin:"] img',
    ).first();
    await expect(firstTemplateImage).toBeVisible();
    await expect.poll(() => firstTemplateImage.evaluate((image) => (
      image.complete && image.naturalWidth > 0 && image.naturalHeight > 0
    ))).toBe(true);
    const dialogBox = await templateDialog.locator(':scope > .relative').boundingBox();
    expect(dialogBox.x).toBeGreaterThanOrEqual(0);
    expect(dialogBox.x + dialogBox.width).toBeLessThanOrEqual(390);
    await page.keyboard.press('Escape');
    await expect(templateDialog).toBeHidden();

    const leftPanel = page.locator('aside.blox-mobile-panel').first();
    const rightPanel = page.locator('aside.blox-mobile-panel').last();
    await page.getByTestId('blox-mobile-library').click();
    await expect(leftPanel).toHaveClass(/is-open/);
    await expect(page.getByTestId('blox-pick-section-hint')).toBeVisible();
    const sectionCount = await page.getByTestId('blox-tree-section').count();
    await page.getByTestId('blox-add-element-heading').click();
    await expect(page.getByTestId('blox-toast')).toContainText('选择目标区块');
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(sectionCount);
    await expectClean(page);
    await page.getByTestId('blox-mobile-canvas-view').click();
    await expect(leftPanel).not.toHaveClass(/is-open/);
    await page.getByTestId('blox-mobile-structure').click();
    await expect(rightPanel).toHaveClass(/is-open/);
    await page.getByTestId('blox-tree-section').first().click();
    await expect(leftPanel).toHaveClass(/is-open/);
    await page.getByTestId('blox-mobile-canvas-view').click();
    await expect(leftPanel).not.toHaveClass(/is-open/);
  } else {
    await expect(page.getByTestId('blox-left-panel-resizer')).toBeVisible();
    await expect(page.getByTestId('blox-right-panel-resizer')).toBeVisible();
    await expect(page.getByTestId('blox-desktop-actions')).toBeVisible();
    await expect(page.getByTestId('blox-mobile-actions')).toBeHidden();
    await expect(page.getByTestId('blox-elements-open')).toBeVisible();
    const templateEntry = page.getByTestId('blox-right-panel').getByTestId('blox-prebuilt-open');
    await expect(templateEntry).toBeVisible();
    await expect(templateEntry).toContainText('预制区块');
    await expect(templateEntry.locator('.ti-layout-grid-add')).toBeVisible();
  }
});

test('scroll panels reserve a stable Tailwind 4.3 gutter @ci', async ({ page }, testInfo) => {
  const assertStableScroller = async (locator) => {
    await expect(locator).toBeVisible();
    const styles = await locator.evaluate((element) => {
      const computed = getComputedStyle(element);
      return {
        gutter: computed.scrollbarGutter,
        width: computed.scrollbarWidth,
      };
    });
    expect(styles.gutter).toContain('stable');
    expect(styles.width).toBe('thin');
  };

  await assertStableScroller(page.getByTestId('blox-element-scroll'));
  await assertStableScroller(page.getByTestId('blox-tree'));

  await page.getByTestId('blox-prebuilt-open').click();
  await assertStableScroller(page.locator('[x-ref="templateScroll"]'));
  await page.screenshot({ path: testInfo.outputPath('blox-scroll-panels.png'), fullPage: true });
});

test('element library keeps favorites and successful recent inserts discoverable @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await page.evaluate(() => {
    localStorage.removeItem('yikai:blox:element-favorites:v1');
    localStorage.removeItem('yikai:blox:element-recent:v1');
  });
  await page.reload({ waitUntil: 'domcontentloaded' });

  await page.getByTestId('blox-favorite-element-heading').click();
  await expect(page.getByTestId('blox-element-group-__favorites')).toBeVisible();
  await expect(page.getByTestId('blox-add-element-heading')).toHaveCount(1);
  await expect(page.getByTestId('blox-quick-element-heading')).toHaveCount(1);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-element-group-__favorites')).toBeVisible();
  await page.locator('[x-ref="libSearch"]').fill('heading');
  await expect(page.getByTestId('blox-add-element-heading')).toHaveCount(1);
  await expect(page.getByTestId('blox-quick-element-heading')).toHaveCount(0);
  await page.locator('[x-ref="libSearch"]').fill('');

  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-elements-open').click();
  await page.getByTestId('blox-add-element-text').press('Enter');
  await page.getByTestId('blox-library-open').click();
  await expect(page.getByTestId('blox-element-group-__recent')).toBeVisible();
  await expect(page.getByTestId('blox-add-element-text')).toHaveCount(1);
  await expect(page.getByTestId('blox-quick-element-text')).toHaveCount(1);
  await expect.poll(() => page.evaluate(() => JSON.parse(
    localStorage.getItem('yikai:blox:element-recent:v1') || '[]'
  )[0])).toBe('text');
  await restoreClean(page);
});

test('element library opens in the compact desktop viewport @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'compact desktop breakpoint baseline');
  await page.setViewportSize({ width: 1200, height: 800 });

  const panel = page.getByTestId('blox-left-panel');
  await expect(panel).toBeHidden();
  await page.getByTestId('blox-elements-open').click();
  await expect(panel).toBeVisible();
  await expect(panel.locator('[x-ref="libSearch"]')).toBeFocused();
});

test('docked prebuilt panel clears the toolbar and leaves Tab untrapped @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop dock accessibility baseline');
  await page.getByTestId('blox-prebuilt-open').click();

  const dialog = page.getByTestId('blox-template-dialog');
  const panel = page.getByTestId('blox-template-panel');
  await expect(dialog).toHaveAttribute('aria-modal', 'false');
  await expect.poll(async () => Math.round((await panel.boundingBox()).y)).toBe(56);
  const tabPrevented = await dialog.evaluate((element) => {
    const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    element.dispatchEvent(event);
    return event.defaultPrevented;
  });
  expect(tabPrevented).toBe(false);

  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
});

test('prebuilt panel resizes against its own container without losing scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop prebuilt resize baseline');
  await page.evaluate(() => {
    localStorage.removeItem('yikai:blox:template-panel-width:v1');
    localStorage.setItem('yikai:blox:template-density:v1', 'standard');
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();

  const panel = page.getByTestId('blox-template-panel');
  const resizer = page.getByTestId('blox-template-panel-resizer');
  const grid = panel.locator('.blox-template-section-grid');
  const scroller = page.locator('[x-ref="templateScroll"]');
  const columnCount = () => grid.evaluate((element) => (
    getComputedStyle(element).gridTemplateColumns.split(' ').filter(Boolean).length
  ));

  await expect.poll(async () => Math.round((await panel.boundingBox()).width)).toBe(520);
  await expect.poll(() => panel.evaluate((element) => getComputedStyle(element).containerType)).toBe('inline-size');
  await expect.poll(columnCount).toBe(1);
  await scroller.evaluate((element) => { element.scrollTop = 180; });
  const scrollBeforeResize = await scroller.evaluate((element) => element.scrollTop);

  const handle = await resizer.boundingBox();
  await page.mouse.move(handle.x + handle.width / 2, handle.y + 100);
  await page.mouse.down();
  await page.mouse.move(handle.x + handle.width / 2 + 96, handle.y + 100, { steps: 6 });
  await page.mouse.up();

  await expect.poll(async () => Math.round((await panel.boundingBox()).width)).toBe(616);
  await expect.poll(columnCount).toBe(2);
  await expect.poll(() => scroller.evaluate((element) => element.scrollTop)).toBeGreaterThanOrEqual(scrollBeforeResize - 2);
  await expect.poll(() => panel.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('yikai:blox:template-panel-width:v1'))).toBe('616');
  await page.screenshot({ path: testInfo.outputPath('blox-template-docked-resized.png'), fullPage: true });

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();
  await expect.poll(async () => Math.round((await page.getByTestId('blox-template-panel').boundingBox()).width)).toBe(616);
  await expect.poll(columnCount).toBe(2);
  const visiblePurposeBadges = () => panel.locator('.blox-template-purpose-badge').evaluateAll((elements) => (
    elements.filter((element) => getComputedStyle(element).display !== 'none').length
  ));
  await expect.poll(visiblePurposeBadges).toBeGreaterThan(0);
  for (let step = 0; step < 14; step += 1) await page.getByTestId('blox-template-panel-resizer').press('ArrowLeft');
  await expect.poll(async () => Math.round((await page.getByTestId('blox-template-panel').boundingBox()).width)).toBe(400);
  await expect.poll(columnCount).toBe(1);
  await expect.poll(visiblePurposeBadges).toBe(0);
  await page.getByTestId('blox-template-panel-resizer').dblclick();
  await expect.poll(async () => Math.round((await page.getByTestId('blox-template-panel').boundingBox()).width)).toBe(520);
  await expect.poll(columnCount).toBe(1);

  await page.setViewportSize({ width: 1190, height: 800 });
  await expect(page.getByTestId('blox-template-panel-resizer')).toBeHidden();
  await expect.poll(columnCount).toBe(3);
  await expect.poll(() => panel.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
  await page.screenshot({ path: testInfo.outputPath('blox-template-container-density.png'), fullPage: true });
});

test('element category filter narrows the library and resets on reload @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const category = page.getByTestId('blox-element-category');
  await expect(category).toHaveValue('all');

  await category.selectOption('media');
  await expect(page.getByTestId('blox-element-group-media')).toBeVisible();
  await expect(page.getByTestId('blox-element-group-layout')).toHaveCount(0);
  await expect(page.getByTestId('blox-add-element-image')).toHaveCount(1);
  await expect(page.getByTestId('blox-add-element-heading')).toHaveCount(0);

  await page.locator('[x-ref="libSearch"]').fill('video');
  await expect(page.getByTestId('blox-add-element-video')).toHaveCount(1);
  await expect(page.getByTestId('blox-add-element-image')).toHaveCount(0);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-element-category')).toHaveValue('all');
  await expect(page.getByTestId('blox-element-group-layout')).toBeVisible();
});

test('desktop keyboard insertion requires a selected target @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await expect(page.getByTestId('blox-pick-section-hint')).toHaveCount(0);
  const sectionCount = await page.getByTestId('blox-tree-section').count();

  await page.getByTestId('blox-add-element-heading').press('Enter');

  await expect(page.getByTestId('blox-toast')).toContainText('选择目标区块');
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(sectionCount);
  await expectClean(page);
});

test('desktop element panel resizes by drag and keyboard @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop split-panel baseline');
  const panel = page.getByTestId('blox-left-panel');
  const resizer = page.getByTestId('blox-left-panel-resizer');
  const canvasHost = page.getByTestId('blox-canvas-host');
  await resizer.dblclick();
  const initialPanel = await panel.boundingBox();
  const initialCanvas = await canvasHost.boundingBox();

  await resizer.press('ArrowRight');
  await expect.poll(async () => (await panel.boundingBox()).width).toBe(initialPanel.width + 16);

  const handle = await resizer.boundingBox();
  await page.mouse.move(handle.x + handle.width / 2, handle.y + 80);
  await page.mouse.down();
  await page.mouse.move(handle.x + handle.width / 2 + 64, handle.y + 80, { steps: 4 });
  await page.mouse.up();

  await expect.poll(async () => (await panel.boundingBox()).width).toBe(initialPanel.width + 80);
  await expect.poll(async () => (await canvasHost.boundingBox()).x).toBe(initialCanvas.x + 80);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('yikai:blox:left-panel-width:v1'))).toBe('368');

  await resizer.dblclick();
  await expect.poll(async () => (await panel.boundingBox()).width).toBe(288);
  await expect(resizer).toHaveAttribute('aria-valuenow', '288');
});

test('property controls respond to panel width without losing field state @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop property-panel baseline');
  const panel = page.getByTestId('blox-left-panel');
  const resizer = page.getByTestId('blox-left-panel-resizer');
  const scroll = page.getByTestId('blox-property-scroll');
  const columnCount = (locator) => locator.evaluate((element) => (
    getComputedStyle(element).gridTemplateColumns.split(' ').filter(Boolean).length
  ));
  const expectNoHorizontalOverflow = async () => {
    await expect.poll(() => scroll.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
  };

  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-style-tab').click();
  const sectionGrid = page.getByTestId('blox-section-property-grid');
  await resizer.dblclick();
  await expect.poll(() => columnCount(sectionGrid)).toBe(1);

  for (let step = 0; step < 7; step += 1) await resizer.press('ArrowRight');
  await expect(resizer).toHaveAttribute('aria-valuenow', '400');
  await expect.poll(() => columnCount(sectionGrid)).toBe(2);
  await expectNoHorizontalOverflow();

  const minHeight = page.getByTestId('blox-section-min-height');
  await minHeight.focus();
  const scrollTop = await scroll.evaluate((element) => {
    element.scrollTop = Math.min(80, element.scrollHeight - element.clientHeight);
    return element.scrollTop;
  });
  await panel.evaluate((element) => { element.style.width = '416px'; });
  await expect(minHeight).toBeFocused();
  await expect.poll(() => scroll.evaluate((element) => element.scrollTop)).toBe(scrollTop);
  await expect.poll(() => columnCount(sectionGrid)).toBe(2);

  const firstSection = page.getByTestId('blox-tree-section').first();
  await firstSection.getByTestId('blox-tree-container').click();
  await expect.poll(() => columnCount(page.getByTestId('blox-container-property-grid'))).toBe(2);
  await expectNoHorizontalOverflow();

  const firstColumn = firstSection.getByTestId('blox-tree-column').first();
  await firstColumn.locator(':scope > div').first().click();
  await expect.poll(() => columnCount(page.getByTestId('blox-column-property-grid'))).toBe(2);
  await expectNoHorizontalOverflow();

  const sections = page.getByTestId('blox-tree-section');
  let selectedExistingElement = false;
  for (let index = 0; index < await sections.count(); index += 1) {
    const candidateSection = sections.nth(index);
    await candidateSection.click();
    const candidate = candidateSection.getByTestId('blox-tree-element').first();
    if (!await candidate.isVisible().catch(() => false)) continue;
    await candidate.locator('[data-element-drag-handle]').click();
    selectedExistingElement = true;
    break;
  }
  expect(selectedExistingElement, 'fixture must expose an existing editable element').toBe(true);
  await expect.poll(() => columnCount(page.getByTestId('blox-element-property-grid'))).toBe(2);
  await expectNoHorizontalOverflow();

  await resizer.dblclick();
  await expect.poll(() => (panel.boundingBox()).then((box) => box.width)).toBe(288);
  await expect.poll(() => columnCount(page.getByTestId('blox-element-property-grid'))).toBe(1);
});

test('desktop structure panel resizes and collapses persistently @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop split-panel baseline');
  const panel = page.getByTestId('blox-right-panel');
  const resizer = page.getByTestId('blox-right-panel-resizer');
  const toggle = page.getByTestId('blox-right-panel-toggle');
  const canvasHost = page.getByTestId('blox-canvas-host');

  if (await toggle.getAttribute('aria-expanded') === 'false') await toggle.click();
  await resizer.dblclick();
  const initialPanel = await panel.boundingBox();
  const initialCanvas = await canvasHost.boundingBox();

  await resizer.press('ArrowLeft');
  await expect.poll(async () => (await panel.boundingBox()).width).toBe(initialPanel.width + 16);

  const handle = await resizer.boundingBox();
  await page.mouse.move(handle.x + handle.width / 2, handle.y + 80);
  await page.mouse.down();
  await page.mouse.move(handle.x + handle.width / 2 - 48, handle.y + 80, { steps: 4 });
  await page.mouse.up();

  await expect.poll(async () => (await panel.boundingBox()).width).toBe(initialPanel.width + 64);
  await expect.poll(async () => (await canvasHost.boundingBox()).width).toBe(initialCanvas.width - 64);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('yikai:blox:right-panel-width:v1'))).toBe('320');

  await toggle.click();
  await expect(toggle).toHaveAttribute('aria-expanded', 'false');
  await expect(resizer).toBeHidden();
  await expect.poll(async () => (await panel.boundingBox()).width).toBe(40);
  await expect.poll(() => page.evaluate(() => localStorage.getItem('yikai:blox:right-panel-collapsed:v1'))).toBe('1');

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-right-panel-toggle')).toHaveAttribute('aria-expanded', 'false');
  await page.getByTestId('blox-right-panel-toggle').click();
  await expect.poll(async () => (await page.getByTestId('blox-right-panel').boundingBox()).width).toBe(320);
  await page.getByTestId('blox-right-panel-resizer').dblclick();
  await expect.poll(async () => (await page.getByTestId('blox-right-panel').boundingBox()).width).toBe(256);
});

test('browser image preprocessing reduces pixels and upload bytes @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop browser image baseline');

  const metrics = await page.evaluate(async () => {
    const canvas = document.createElement('canvas');
    canvas.width = 3200;
    canvas.height = 1600;
    const context = canvas.getContext('2d');
    for (let y = 0; y < 40; y += 1) {
      for (let x = 0; x < 80; x += 1) {
        context.fillStyle = `hsl(${(x * 37 + y * 19) % 360} 72% ${35 + ((x + y) % 40)}%)`;
        context.fillRect(x * 40, y * 40, 40, 40);
      }
    }
    const sourceBlob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.98));
    const source = new File([sourceBlob], 'browser-metric.jpg', { type: 'image/jpeg' });
    const prepared = await window.BloxMediaClient.prepareImage(source, {
      maxDimension: 1600,
      minBytes: 0,
      quality: 0.72,
    });
    const decoded = await createImageBitmap(prepared);
    const result = {
      originalBytes: source.size,
      outputBytes: prepared.size,
      width: decoded.width,
      height: decoded.height,
      type: prepared.type,
    };
    decoded.close();
    return result;
  });

  expect(metrics.type).toBe('image/jpeg');
  expect(metrics.width).toBe(1600);
  expect(metrics.height).toBe(800);
  expect(metrics.outputBytes).toBeLessThan(metrics.originalBytes);
});

test('browser image upload is accepted by the real media API @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop browser upload baseline');
  let uploadedUrl = '';

  try {
    const result = await page.evaluate(async () => {
      const app = document.body._x_dataStack && document.body._x_dataStack[0];
      if (!app || !app.csrf) throw new Error('Blox editor CSRF state is unavailable');

      const canvas = document.createElement('canvas');
      canvas.width = 1800;
      canvas.height = 900;
      const context = canvas.getContext('2d');
      for (let y = 0; y < 18; y += 1) {
        for (let x = 0; x < 36; x += 1) {
          context.fillStyle = `hsl(${(x * 29 + y * 41) % 360} 68% ${32 + ((x + y) % 44)}%)`;
          context.fillRect(x * 50, y * 50, 50, 50);
        }
      }
      const sourceBlob = await new Promise((resolve) => canvas.toBlob(resolve, 'image/jpeg', 0.98));
      if (!sourceBlob) throw new Error('Browser JPEG encoder is unavailable');
      const source = new File([sourceBlob], 'blox-e2e-upload.jpg', { type: 'image/jpeg' });
      const upload = await window.BloxMediaClient.upload('/admin/media_api.php', source, {
        csrf: app.csrf,
        maxDimension: 900,
        minBytes: 0,
        quality: 0.68,
      });
      return {
        ...upload,
        successMessage: app.mediaUploadMessage(upload),
        fromLabel: window.BloxMediaClient.formatBytes(upload.originalBytes),
        toLabel: window.BloxMediaClient.formatBytes(upload.uploadBytes),
      };
    });

    uploadedUrl = result.url;
    expect(result.ok).toBe(true);
    expect(result.optimized).toBe(true);
    expect(result.uploadBytes).toBeLessThan(result.originalBytes);
    expect(result.url).toMatch(/^\/uploads\/images\/\d{6}\/[a-z0-9_]+\.jpg$/);
    expect(result.successMessage).toContain(result.fromLabel);
    expect(result.successMessage).toContain(result.toLabel);

    const uploaded = await page.request.get(result.url);
    expect(uploaded.ok()).toBe(true);
    expect(uploaded.headers()['content-type']).toContain('image/jpeg');
  } finally {
    if (uploadedUrl) {
      const fs = require('fs');
      const path = require('path');
      const relative = decodeURIComponent(new URL(uploadedUrl, 'http://localhost').pathname).replace(/^\/+/, '');
      const uploadedPath = path.resolve(__dirname, '../..', relative);
      const uploadRoot = path.resolve(__dirname, '../../uploads/images');
      if (!uploadedPath.toLowerCase().startsWith((uploadRoot + path.sep).toLowerCase())) {
        throw new Error(`Refusing to clean unexpected upload path: ${uploadedPath}`);
      }
      const parsed = path.parse(uploadedPath);
      const generatedNames = fs.readdirSync(parsed.dir).filter((name) => {
        const candidate = path.parse(name);
        return candidate.name === parsed.name || candidate.name.startsWith(parsed.name + '_');
      });
      generatedNames.forEach((name) => fs.rmSync(path.join(parsed.dir, name), { force: true }));
    }
  }
});

test('media API rejects oversized image dimensions before processing @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop browser upload baseline');

  const result = await page.evaluate(async () => {
    const app = document.body._x_dataStack && document.body._x_dataStack[0];
    if (!app || !app.csrf) throw new Error('Blox editor CSRF state is unavailable');

    const binary = atob('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=');
    const bytes = Uint8Array.from(binary, (character) => character.charCodeAt(0));
    const dimensions = new DataView(bytes.buffer);
    dimensions.setUint32(16, 100000);
    dimensions.setUint32(20, 100000);

    const body = new FormData();
    body.append('file', new Blob([bytes], { type: 'image/png' }), 'blox-pixel-bomb.png');
    body.append('type', 'images');
    body.append('_token', app.csrf);
    const response = await fetch('/admin/media_api.php?action=upload', { method: 'POST', body });
    return response.json();
  });

  expect(result.code).toBe(1);
  expect(result.msg).toContain('MP');

  const listed = await page.evaluate(() => window.BloxMediaClient.list(
    '/admin/media_api.php',
    1,
    'blox-pixel-bomb',
  ));
  expect(listed.ok).toBe(true);
  expect(listed.total).toBe(0);
});

test('home canvas keeps header and footer actions without a redundant page structure action @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  const contentFrame = await frame(page);
  const headerArea = contentFrame.locator('[data-yk-context-area="header"]');
  const footerArea = contentFrame.locator('[data-yk-context-area="footer"]');
  const headerEdit = contentFrame.getByTestId('blox-context-edit-header');
  const footerEdit = contentFrame.getByTestId('blox-context-edit-footer');
  // 画布区域是首页编辑网页头的唯一入口，避免顶栏重复操作分散注意力。
  const expectedHeaderPath = `/admin/blox_editor.php?template=${fixtures.blox_header_template}&current_header=1&back=home&open=header-settings`;
  const expectedCanvasHeaderPath = expectedHeaderPath;

  await expect(page.getByTestId('blox-home-header-settings')).toHaveCount(0);
  await expect(headerEdit).toBeVisible();
  await expect(footerEdit).toBeVisible();
  await expect(contentFrame.getByTestId('blox-context-edit-content')).toHaveCount(0);
  await expect(headerEdit).not.toHaveAttribute('href', /.+/);
  await expect(footerEdit).not.toHaveAttribute('href', /.+/);
  await expect(headerArea).toHaveAttribute('data-yk-context-url', expectedCanvasHeaderPath);
  await expect(footerArea).toHaveAttribute('data-yk-context-url', /\/admin\/(?:blox_editor\.php\?template=\d+(?:&back=home)?|site_design\.php#site-design-area-footer)$/);
  await expectClean(page);

  const headerHref = await headerArea.getAttribute('data-yk-context-url');
  const expectedHeaderUrl = new URL(headerHref, page.url()).href;
  await Promise.all([
    page.waitForURL(expectedHeaderUrl),
    pointerClick(page, headerEdit),
  ]);
  expect(page.url()).toBe(expectedHeaderUrl);
  await expect(page.locator('.blox-header-page')).toContainText('当前网页头');
  await expect(page.getByTestId('blox-sticky-toggle').locator('input')).not.toBeChecked();
  await expect(page.getByTestId('blox-publish-template')).toContainText('发布并使用');
});

test('dirty area editor confirms and returns to the home editor @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop navigation baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  const areaUrl = `/admin/blox_editor.php?template=${fixtures.blox_header_template}&back=home`;
  await page.goto(areaUrl, { waitUntil: 'domcontentloaded' });
  await addTemporaryHeading(page);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();

  const back = page.getByTestId('blox-back');
  let cancelledConfirmSeen = false;
  page.once('dialog', async (dialog) => {
    cancelledConfirmSeen = dialog.type() === 'confirm' && dialog.message().includes('未保存');
    await dialog.dismiss();
  });
  await back.click();
  expect(cancelledConfirmSeen).toBe(true);
  await expect(page).toHaveURL(new URL(areaUrl, page.url()).href);

  page.once('dialog', async (dialog) => {
    expect(dialog.type()).toBe('confirm');
    await dialog.accept();
  });
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/admin/blox_editor.php' && url.search === '?home=1'),
    back.click(),
  ]);
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
});

test('stale header edit links recover to the current effective header @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop redirect baseline');
  const contentFrame = await frame(page);
  const expectedHeaderUrl = new URL(
    await contentFrame.locator('[data-yk-context-area="header"]').getAttribute('data-yk-context-url'),
    page.url()
  ).href;

  await page.goto('/admin/blox_editor.php?template=2147483647&back=home&open=header-settings', {
    waitUntil: 'domcontentloaded',
  });

  await expect(page).toHaveURL(expectedHeaderUrl);
  await expect(page.getByTestId('blox-header-presets-open')).toBeVisible();
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
});

test('footer template opens with the editable footer visible at the bottom of the canvas @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop canvas position baseline');
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'footer editing is an advanced feature');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));

  await page.goto(`/admin/blox_editor.php?template=${fixtures.blox_footer_template}`, {
    waitUntil: 'domcontentloaded',
  });
  const contentFrame = await frame(page);
  const footerArea = contentFrame.locator('[data-yk-area="footer"]');
  await expect(footerArea).toBeVisible();
  await expect(contentFrame.locator('.yk-ctx-dim')).toHaveCount(1);
  await expect(contentFrame.locator('.yk-ctx-dim header').first()).toBeVisible();
  await expect.poll(async () => contentFrame.evaluate(() => {
    const footer = document.querySelector('[data-yk-area="footer"]');
    if (!footer) return false;
    const rect = footer.getBoundingClientRect();
    const scrollTop = window.scrollY || document.documentElement.scrollTop || 0;
    return scrollTop > 0 && rect.bottom <= window.innerHeight + 2 && rect.bottom > 0;
  }), { timeout: 10000 }).toBe(true);
});

test('current theme header allows publishing unsaved canvas changes @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single publish control baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));

  await page.goto(`/admin/blox_editor.php?template=${fixtures.blox_header_template}&current_header=1&open=header-settings`,
    { waitUntil: 'domcontentloaded' });
  // 设置层默认收起（不自动弹盖画布）；像用户一样先点开「网页头设置」。
  await page.getByTestId('blox-sticky-settings').locator('summary').click();
  const sticky = page.getByTestId('blox-sticky-toggle').locator('input');
  await expect(sticky).not.toBeChecked();
  await sticky.check();
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  await expect(page.getByTestId('blox-publish-template')).toBeEnabled();
  await expect(page.getByTestId('blox-publish-template')).toHaveAttribute('title', '保存当前修改并发布');

  // 全局 E2E 安全钩子禁止触发保存/发布；恢复初始值，证明按钮状态不依赖先保存。
  await sticky.uncheck();
  await expectClean(page);
});

test('stable element deep link selects the current header logo @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop element locator baseline');
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'header editing is an advanced feature');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));

  const consoleEntries = observeConsole(page);
  const baseUrl = `/admin/blox_editor.php?template=${fixtures.blox_header_template}&current_header=1`;
  await page.goto(baseUrl, { waitUntil: 'domcontentloaded' });
  const logoTree = page.locator('[data-sort-child-item][data-element-type="logo"]').first();
  const logoId = await logoTree.getAttribute('data-item-id');
  expect(logoId).toBeTruthy();

  await page.goto(`${baseUrl}&focus_element=${encodeURIComponent(logoId)}`, { waitUntil: 'domcontentloaded' });
  const selectedLogo = page.locator(`[data-sort-child-item][data-item-id="${logoId}"]`).first();
  await expect(selectedLogo).toHaveClass(/bg-blue-100/);
  const contentFrame = await frame(page);
  const canvasLogo = contentFrame.locator(`[data-yk-el-id="${logoId}"]`);
  const logoPath = await canvasLogo.getAttribute('data-yk-el');
  await expect(contentFrame.locator('.yk-pick-overlay')).toBeVisible();
  await expect(contentFrame.locator('.yk-pick-label')).toHaveText(`Element ${logoPath}`);
  expect(consoleEntries).toEqual([]);
});

test('current theme header switches Mega Menu in place and reorders the selected navigation @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop header editing baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));

  await page.goto(`/admin/blox_editor.php?template=${fixtures.blox_header_template}&current_header=1&open=header-settings`,
    { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-tree-section').first().click();
  const children = page.locator('[data-sort-child-item]');
  const childTypes = () => children.evaluateAll((items) => items.map((item) => item.dataset.elementType));
  const initialTypes = await childTypes();
  const navigationIndex = initialTypes.indexOf('nav-mega');
  expect(navigationIndex).toBeGreaterThan(0);
  expect(navigationIndex).toBeLessThan(initialTypes.length - 1);
  expect(initialTypes).toContain('logo');
  expect(initialTypes).toContain('nav-drawer');
  const normalTypes = initialTypes.slice();
  normalTypes[navigationIndex] = 'nav';

  const mega = page.locator('[data-sort-child-item][data-element-type="nav-mega"]');
  const originalId = await mega.getAttribute('data-item-id');
  await mega.click();
  await expect(page.getByTestId('blox-navigation-quick-settings')).toBeVisible();

  await performPreviewUpdate(page, () => page.getByTestId('blox-nav-type-normal').click());
  expect(await childTypes()).toEqual(normalTypes);
  await expect(page.locator('[data-sort-child-item][data-element-type="nav"]')).toHaveAttribute('data-item-id', originalId);
  const contentFrame = await frame(page);
  const standardNav = contentFrame.locator('[data-yk-el-type="nav"] ul').first();
  await expect(standardNav).toBeVisible();
  await expect(standardNav).toHaveClass(/hidden/);
  await expect(standardNav).toHaveClass(/xl:flex/);
  await expect(standardNav.locator('[data-yk-nav-caret]')).not.toHaveCount(0);

  await undo(page);
  expect(await childTypes()).toEqual(initialTypes);
  await expectClean(page);

  await expect(page.getByTestId('blox-redo')).toBeEnabled();
  await performPreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  expect(await childTypes()).toEqual(normalTypes);

  const movedTypes = normalTypes.slice();
  [movedTypes[navigationIndex], movedTypes[navigationIndex + 1]] = [movedTypes[navigationIndex + 1], movedTypes[navigationIndex]];
  await performPreviewUpdate(page, () => page.getByTestId('blox-selected-element-down').click());
  expect(await childTypes()).toEqual(movedTypes);
  await performPreviewUpdate(page, () => page.getByTestId('blox-selected-element-up').click());
  expect(await childTypes()).toEqual(normalTypes);

  await restoreClean(page);
});

test('section spacing edits the active responsive tier @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-style-tab').click();
  const tabletDevice = page.getByTestId('blox-section-padding-device-tablet');
  const inheritButton = page.getByTestId('blox-section-padding-inherit');
  await expect(tabletDevice).toBeVisible();
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await tabletDevice.click();
  await expect(inheritButton).toBeHidden();

  const previewRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return request.method() === 'POST' && url.pathname === '/admin/blox_preview.php';
  });
  await page.getByTestId('blox-section-padding-xl').click();
  const request = await previewRequest;
  const body = new URLSearchParams(request.postData() || '');
  const document = JSON.parse(body.get('blocks_data') || '{}');
  expect(document.sections[0].settings.padding).toMatchObject({ t: 'xl' });
  expect(document.sections[0].settings.padding).not.toHaveProperty('m');
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'override');
  await expect(inheritButton).toBeVisible();

  const inheritRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return request.method() === 'POST' && url.pathname === '/admin/blox_preview.php';
  });
  await inheritButton.click();
  const restoredRequest = await inheritRequest;
  const restoredBody = new URLSearchParams(restoredRequest.postData() || '');
  const restoredDocument = JSON.parse(restoredBody.get('blocks_data') || '{}');
  expect(typeof restoredDocument.sections[0].settings.padding).toBe('string');
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await expect(inheritButton).toBeHidden();
  await expectClean(page);
});

test('heading visual size overrides one device without changing its level @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { sectionIndex } = await addTemporaryHeading(page);
  await page.getByTestId('blox-style-tab').click();
  const tabletDevice = page.getByTestId('blox-control-visual_size-device-tablet');
  const globalTabletDevice = page.getByTestId('blox-device-tablet');
  const sizeControl = page.getByTestId('blox-control-visual_size');
  const inheritButton = page.getByTestId('blox-control-visual_size-inherit');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-overrides', '0');
  await tabletDevice.click();
  await expect(inheritButton).toBeHidden();

  const previewRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return request.method() === 'POST' && url.pathname === '/admin/blox_preview.php';
  });
  await sizeControl.selectOption('xl');
  const request = await previewRequest;
  const body = new URLSearchParams(request.postData() || '');
  const document = JSON.parse(body.get('blocks_data') || '{}');
  const data = document.sections[sectionIndex].columns[0].elements[0].data;
  expect(data.visual_size).toEqual({ d: 'auto', t: 'xl' });
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'override');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-state', 'override');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-overrides', '1');
  await expect(inheritButton).toBeVisible();

  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"] h2`);
  await expect(heading).toHaveClass(/text-2xl/);
  await expect(heading).toHaveClass(/lg:text-2xl/);
  await inheritButton.click();
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await expect(globalTabletDevice).toHaveAttribute('data-responsive-overrides', '0');
  await restoreClean(page);
});

test('container panel edits and restores responsive child gap @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);
  const clearSelection = page.getByTestId('blox-clear-selection');
  if (await clearSelection.isVisible()) await clearSelection.click();
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-container').press('Enter');

  const tabletDevice = page.getByTestId('blox-container-responsive-device-tablet');
  const inheritButton = page.getByTestId('blox-container-gap-inherit');
  await expect(tabletDevice).toBeVisible();
  await tabletDevice.click();
  await expect(inheritButton).toBeHidden();

  const previewRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    return request.method() === 'POST' && url.pathname === '/admin/blox_preview.php';
  });
  await page.getByTestId('blox-container-gap-xl').click();
  const request = await previewRequest;
  const body = new URLSearchParams(request.postData() || '');
  const document = JSON.parse(body.get('blocks_data') || '{}');
  const data = document.sections[before].columns[0].elements[0].data;
  expect(data.gap).toEqual({ d: 'md', t: 'xl' });
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'override');
  await expect(inheritButton).toBeVisible();

  const layoutPreview = page.getByTestId('blox-layout-preview');
  const layoutItems = page.getByTestId('blox-layout-preview-item');
  await expect(layoutItems).toHaveCount(3);
  const previewMetrics = await layoutPreview.evaluate((preview) => {
    const style = getComputedStyle(preview);
    const items = Array.from(preview.querySelectorAll('[data-testid="blox-layout-preview-item"]'));
    return {
      gap: style.gap,
      overflowX: preview.scrollWidth - preview.clientWidth,
      items: items.map((item) => ({
        whiteSpace: getComputedStyle(item).whiteSpace,
        overflowX: item.scrollWidth - item.clientWidth,
      })),
    };
  });
  expect(previewMetrics.gap).toBe('8px');
  expect(previewMetrics.overflowX).toBeLessThanOrEqual(0);
  expect(previewMetrics.items.every((item) => item.whiteSpace === 'nowrap' && item.overflowX <= 0)).toBe(true);

  const contentFrame = await frame(page);
  const container = contentFrame.locator(`[data-yk-el="${before}.0.0"] .yk-container`);
  await expect(container).toHaveClass(/gap-12/);
  await expect(container).toHaveClass(/lg:gap-4/);
  await inheritButton.click();
  await expect(tabletDevice).toHaveAttribute('data-responsive-state', 'inherit');
  await restoreClean(page);
});

test('cover-header banner fills the first viewport @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const contentFrame = await frame(page);
  const bannerTreeItem = page.locator(
    '[data-testid="blox-tree-element"][data-element-type="home-block"][data-home-block-type="banner"]'
  ).first();

  await expect(bannerTreeItem).toBeAttached();
  const bannerSection = bannerTreeItem.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]');
  await bannerSection.locator('[data-section-drag-handle]').first().click();
  await expect(bannerTreeItem).toBeVisible();
  await bannerTreeItem.locator('[data-element-drag-handle]').click();
  await page.getByRole('button', { name: '全屏并覆盖 Header', exact: true }).click();
  await waitPreviewSettled(page);

  const banner = contentFrame.locator('[data-blox-banner]').first();
  await expect(banner).toHaveAttribute('data-blox-height-mode', 'cover-header');
  const dimensions = await banner.evaluate((element) => ({
    height: element.getBoundingClientRect().height,
    viewport: window.innerHeight,
  }));
  expect(Math.abs(dimensions.height - dimensions.viewport)).toBeLessThanOrEqual(1);

  await undo(page);
  await expectClean(page);
});

test('banner switching keeps one visible slide when selection index is stale @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const contentFrame = await frame(page);
  const banner = contentFrame.locator('[data-blox-banner]').first();
  await expect(banner).toBeVisible();

  const initial = await banner.evaluate((element) => ({
    legacyInstance: !!element._ykSwiper,
    sharesRuntimeInstance: !!element.bloxBanner && element.bloxBanner === element.swiper,
  }));
  expect(initial.legacyInstance).toBe(false);
  expect(initial.sharesRuntimeInstance).toBe(true);

  await banner.evaluate(() => window.postMessage({ ykBannerSlide: 999 }, '*'));
  await expect.poll(() => banner.locator('.swiper-slide-active').count()).toBe(1);
  const activeSlide = banner.locator('.swiper-slide-active');
  const visual = await activeSlide.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    return { width: rect.width, height: rect.height, opacity: Number(getComputedStyle(element).opacity) };
  });
  expect(visual.width).toBeGreaterThan(0);
  expect(visual.height).toBeGreaterThan(0);
  expect(visual.opacity).toBeGreaterThan(0);
});

test('tree column selection preserves canvas scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const firstSection = page.getByTestId('blox-tree-section').first();

  await firstSection.click();
  await scrollCanvasToBottom(page);
  const before = await canvasScrollTop(page);
  await firstSection.getByText('列 1', { exact: true }).click();
  await page.waitForTimeout(500);

  expect(await canvasScrollTop(page)).toBeCloseTo(before, 1);
  await expectClean(page);
});

test('inline edit patches preview and preserves scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const { sectionIndex } = await addTemporaryHeading(page);
  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"] h1, [data-yk-el="${sectionIndex}.0.0"] h2, [data-yk-el="${sectionIndex}.0.0"] h3, [data-yk-el="${sectionIndex}.0.0"] h4`).first();
  const originalText = await heading.innerText();

  // 滚动前确认加区块那轮预览已落地（新元素可见）——否则慢环境下预览恰在
  // 滚动之后完成，iframe 重建把 scrollTop 归零，scrollBefore 读到假 0（CI 实证）
  await expect(heading).toBeVisible();
  await scrollCanvasToBottom(page);
  await pointerClick(page, heading, 2);
  await expect(heading).toHaveAttribute('contenteditable', /true|plaintext-only/);
  // 基准在进入编辑态之后取：pointerClick 会有意把目标滚到视口中央，
  // 点击前取值会把这段合法滚动误判为"预览弄丢了滚动"（历史上正是如此）。
  const scrollBefore = await canvasScrollTop(page);
  await page.keyboard.press('ControlOrMeta+A');
  await page.keyboard.insertText('E2E 局部预览标题');
  await performPreviewUpdate(page, () => page.keyboard.press('Tab'));
  await expect(heading).not.toHaveAttribute('contenteditable', /.+/);
  await expect(page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-element')).toContainText('E2E 局部预览标题');
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText('E2E 局部预览标题');
  // 滚动不变量：r16-r17 五轮 CI 实证的「settled 后被恢复为 0」已产品级修复
  // （preview-client 重建窗口采样兜底 + 恢复取 max(捕获,当前)，单测覆盖），
  // 断言重新在 CI 启用。若再红：先拉 preview-client 采样时序，勿回退为跳过。
  await waitPreviewSettled(page);
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  expect(page.url()).toBe(originalURL);

  await undo(page);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText(originalText);
  await waitPreviewSettled(page);
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  await undo(page);
  await undo(page);
  await expectClean(page);
});

test('unchanged inline blur does not create history @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const contentFrame = await frame(page);
  const field = contentFrame.locator('[data-yk-home-field]').filter({ visible: true }).first();
  await expect(field).toBeVisible();
  const value = await field.innerText();
  await pointerClick(page, field, 2);
  await page.keyboard.press('Tab');
  await expect(field).not.toHaveAttribute('contenteditable', /.+/);
  await expect(field).toHaveText(value);
  await expect(page.getByTestId('blox-undo')).toBeDisabled();
  await expectClean(page);
});

test('custom pricing columns edit in place and restore inheritance @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const source = page.getByTestId('blox-tree').getByText('自定义版块 #1', { exact: true });
  test.skip(await source.count() === 0, 'site has no custom pricing block');

  await source.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]').click();
  await source.click();
  const panel = page.getByTestId('blox-custom-columns');
  await expect(panel).toBeVisible();
  const professional = panel.getByTestId('blox-custom-column-custom-columns-0-1');
  await expect(professional).toHaveCount(1);
  await expect(professional).toContainText('专业版');

  await professional.click();
  const titleField = panel.locator(
    '[data-home-custom-field*=".columns.1."][data-home-custom-field$=".data.text"]'
  ).first();
  await titleField.click();
  const editor = page.locator('[data-home-field-editor]');
  const input = editor.locator('input[type="text"]');
  const temporary = '专业版-浏览器回归';
  await performPreviewUpdate(page, () => input.fill(temporary));

  let contentFrame = await frame(page);
  const heading = contentFrame.getByRole('heading', { name: temporary, exact: true });
  await expect(heading).toBeVisible();
  await pointerClick(page, heading);
  await expect(page.getByTestId('blox-breadcrumb')).toContainText('区块');
  await expect(editor).toContainText('专业版');

  await performPreviewUpdate(page, () => page.getByTestId('blox-custom-field-reset').click());
  contentFrame = await frame(page);
  await expect(contentFrame.getByRole('heading', { name: '专业版', exact: true })).toBeVisible();
  await expect(input).toHaveValue('专业版');
  await expectClean(page);

  const planGroups = panel.locator('[data-testid^="blox-custom-column-custom-columns-"]');
  await expect(planGroups).toHaveCount(3);
  await expect(page.getByTestId('blox-plan-move-up').first()).toBeDisabled();
  await performPreviewUpdate(page, () => page.getByTestId('blox-plan-move-up').nth(1).click());
  contentFrame = await frame(page);
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".columns.0.elements.0.data.text"]'
  ).first()).toHaveText('专业版');
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".columns.1.elements.0.data.text"]'
  ).first()).toHaveText('基础版');

  await performPreviewUpdate(page, () => page.getByTestId('blox-plan-duplicate').first().click());
  await expect(planGroups).toHaveCount(4);
  contentFrame = await frame(page);
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".columns.1.elements.0.data.text"]'
  ).first()).toHaveText('专业版');

  await performPreviewUpdate(page, () => page.getByTestId('blox-plan-delete').nth(1).click());
  await expect(planGroups).toHaveCount(3);
  await performPreviewUpdate(page, () => page.getByTestId('blox-plan-add').click());
  await expect(planGroups).toHaveCount(4);

  page.once('dialog', (dialog) => dialog.accept());
  await performPreviewUpdate(page, () => page.getByTestId('blox-plan-restore').click());
  await expect(planGroups).toHaveCount(3);
  contentFrame = await frame(page);
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".columns.0.elements.0.data.text"]'
  ).first()).toHaveText('基础版');
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".columns.1.elements.0.data.text"]'
  ).first()).toHaveText('专业版');
  await expect(page.getByTestId('blox-plan-restore')).toBeHidden();
  await expectClean(page);
});

test('custom FAQ edits individual questions and answers from panel or canvas @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const source = page.getByTestId('blox-tree').getByText('自定义版块 #2', { exact: true });
  test.skip(await source.count() === 0, 'site has no custom FAQ block');

  await source.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]').click();
  await source.click();
  const panel = page.getByTestId('blox-custom-columns');
  await expect(panel).toBeVisible();
  const firstItem = panel.locator('[data-testid^="blox-custom-column-custom-faq-"]').first();
  test.skip(await firstItem.count() === 0, 'custom block has no accordion fixture');
  await firstItem.click();

  const questionField = panel.locator('[data-home-custom-field$=".accordion_items.0.question"]').first();
  await questionField.click();
  const editor = page.locator('[data-home-field-editor]');
  const questionInput = editor.locator('input[type="text"]');
  const originalQuestion = await questionInput.inputValue();
  const temporaryQuestion = originalQuestion + '-浏览器回归';
  await performPreviewUpdate(page, () => questionInput.fill(temporaryQuestion));

  let contentFrame = await frame(page);
  const canvasQuestion = contentFrame.locator(
    '[data-yk-home-field$=".accordion_items.0.question"]'
  ).first();
  await expect(canvasQuestion).toHaveText(temporaryQuestion);
  await pointerClick(page, canvasQuestion);
  await expect(editor).toContainText('问题');
  await performPreviewUpdate(page, () => page.getByTestId('blox-custom-field-reset').click());
  await expect(questionInput).toHaveValue(originalQuestion);

  contentFrame = await frame(page);
  const canvasAnswer = contentFrame.locator(
    '[data-yk-home-field$=".accordion_items.0.answer"]'
  ).first();
  await pointerClick(page, canvasAnswer);
  const answerInput = editor.locator('textarea');
  const originalAnswer = await answerInput.inputValue();
  const temporaryAnswer = originalAnswer + ' 浏览器回归';
  await performPreviewUpdate(page, () => answerInput.fill(temporaryAnswer));
  contentFrame = await frame(page);
  await expect(contentFrame.locator(
    '[data-yk-home-field$=".accordion_items.0.answer"]'
  ).first()).toContainText(temporaryAnswer);
  await performPreviewUpdate(page, () => page.getByTestId('blox-custom-field-reset').click());
  await expect(answerInput).toHaveValue(originalAnswer);

  const faqGroups = panel.locator('[data-testid^="blox-custom-column-custom-faq-"]');
  const originalCount = await faqGroups.count();
  if (originalCount > 1) {
    contentFrame = await frame(page);
    const firstQuestion = await contentFrame.locator(
      '[data-yk-home-field$=".accordion_items.0.question"]'
    ).first().innerText();
    const secondQuestion = await contentFrame.locator(
      '[data-yk-home-field$=".accordion_items.1.question"]'
    ).first().innerText();

    await expect(page.getByTestId('blox-faq-move-up').first()).toBeDisabled();
    await performPreviewUpdate(page, () => page.getByTestId('blox-faq-move-down').first().click());
    contentFrame = await frame(page);
    await expect(contentFrame.locator(
      '[data-yk-home-field$=".accordion_items.0.question"]'
    ).first()).toHaveText(secondQuestion);
    await expect(contentFrame.locator(
      '[data-yk-home-field$=".accordion_items.1.question"]'
    ).first()).toHaveText(firstQuestion);
    await expect(page.getByTestId('blox-faq-move-down').last()).toBeDisabled();
  }

  await performPreviewUpdate(page, () => page.getByTestId('blox-faq-add').click());
  await expect(faqGroups).toHaveCount(originalCount + 1);
  contentFrame = await frame(page);
  await expect(contentFrame.locator(
    `[data-yk-home-field$=".accordion_items.${originalCount}.question"]`
  )).toHaveText('新问题');

  await performPreviewUpdate(page, () => page.getByTestId('blox-faq-delete').nth(originalCount).click());
  await expect(faqGroups).toHaveCount(originalCount);
  await expect(page.getByTestId('blox-faq-restore')).toBeVisible();

  page.once('dialog', (dialog) => dialog.accept());
  await performPreviewUpdate(page, () => page.getByTestId('blox-faq-restore').click());
  await expect(page.getByTestId('blox-faq-restore')).toBeHidden();
  await expectClean(page);
});

test('stale save is blocked and keeps a recoverable local copy @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);
  let submittedRevision = '';
  await page.route('**/admin/blox_home_api.php', async (route) => {
    const request = route.request();
    const body = new URLSearchParams(request.postData() || '');
    if ((body.get('action') || 'save') === 'preview') {
      await route.continue();
      return;
    }
    submittedRevision = body.get('base_revision') || '';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 409, msg: 'conflict', data: null }),
    });
  });

  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-conflict-dialog')).toBeVisible();
  expect(submittedRevision).toMatch(/^[a-f0-9]{64}$/);
  const key = await page.locator('body').getAttribute('data-blox-recovery-key');
  await expect.poll(() => page.evaluate((storageKey) => !!localStorage.getItem(storageKey), key)).toBe(true);

  // 请求已被 route 截断，没有写入服务器；从危险写观察器中移除这条已证明安全的模拟请求。
  unsafeWrites.length = 0;
  await expect(page.getByTestId('blox-conflict-continue')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('blox-conflict-dialog')).toBeHidden();
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('publish saves the current document before activating it @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);
  let submitted = null;
  await page.route('**/admin/blox_home_api.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    if ((body.get('action') || '') !== 'publish') {
      await route.continue();
      return;
    }
    submitted = {
      action: body.get('action'),
      blocksData: body.get('blocks_data'),
      baseRevision: body.get('base_revision'),
    };
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: 'ok',
        data: { active: true, has_published: true, sections: before + 1, base_revision: 'a'.repeat(64) },
      }),
    });
  });

  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  await expect(page.getByTestId('blox-publish')).toBeEnabled();
  page.once('dialog', (dialog) => dialog.accept());
  await page.getByTestId('blox-publish').click();
  await expectClean(page);

  expect(submitted.action).toBe('publish');
  expect(submitted.baseRevision).toMatch(/^[a-f0-9]{64}$/);
  expect(JSON.parse(submitted.blocksData).sections).toHaveLength(before + 1);
  unsafeWrites.length = 0;

  // 请求已被拦截，服务器草稿未改变；重载回到真实基线，避免污染后续用例。
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expectClean(page);
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
  await expectClean(page);
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
  await expectClean(page);
});

test('built-in prebuilt section library filters previews and inserts a fresh section @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);

  await page.getByTestId('blox-prebuilt-open').click();
  await expect(page.getByTestId('blox-template-tab-local')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('blox-template-quick-recommended')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByText('推荐用于：首页')).toBeVisible();
  await expect(page.getByTestId('blox-template-item')).toHaveCount(14);
  await expect.poll(() => page.getByTestId('blox-template-panel').evaluate((panel) => (
    panel.scrollWidth <= panel.clientWidth
  ))).toBe(true);
  await page.getByTestId('blox-template-quick-all').click();

  const builtins = page.locator('[data-testid="blox-template-item"][data-template-key^="builtin:"]');
  await expect(builtins).toHaveCount(18);
  const firstPreview = builtins.first().locator('img');
  await expect(firstPreview).toBeVisible();
  await expect.poll(() => firstPreview.evaluate((image) => (
    image.complete && image.naturalWidth > 0 && image.naturalHeight > 0
  ))).toBe(true);

  const search = page.getByTestId('blox-template-search');
  await search.fill('项目流程');
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:process-steps"]')).toBeVisible();
  await expect(builtins).toHaveCount(1);
  await search.fill('');

  const category = page.getByTestId('blox-template-category');
  await expect(category).toBeVisible();
  await category.selectOption('content');
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]')).toBeVisible();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text-reverse"]')).toBeVisible();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:text-columns"]')).toBeVisible();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:testimonial-quote"]')).toBeVisible();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:faq-accordion"]')).toBeVisible();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:download-guide"]')).toBeVisible();
  await expect(builtins).toHaveCount(6);

  await category.selectOption('all');
  const hero = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:hero-intro"]');
  await hero.getByTestId('blox-template-insert').click();
  await expect(page.locator('[x-ref="templateDialog"]')).toBeHidden();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect((await frame(page)).getByText('以专业与稳健，陪伴客户长期成长')).toBeVisible();

  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('prebuilt library persists favorites and tracks only successful recent inserts @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop template preference baseline');
  await page.evaluate(() => {
    localStorage.removeItem('yikai:blox:template-favorites:v1');
    localStorage.removeItem('yikai:blox:template-recent:v1');
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  const before = await countSections(page);

  await page.getByTestId('blox-prebuilt-open').click();
  const heroFavorite = page.getByTestId('blox-template-favorite-builtin:hero-intro');
  await heroFavorite.click();
  await expect(heroFavorite).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(() => page.evaluate(() => JSON.parse(
    localStorage.getItem('yikai:blox:template-favorites:v1') || '[]'
  ))).toEqual(['builtin:hero-intro']);

  await page.getByTestId('blox-template-quick-favorites').click();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:hero-intro"]')).toBeVisible();
  await expect(page.getByTestId('blox-template-item')).toHaveCount(1);

  await page.getByTestId('blox-template-quick-all').click();
  const imageText = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]');
  await imageText.getByTestId('blox-template-insert').click();
  await waitPreviewSettled(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect.poll(() => page.evaluate(() => JSON.parse(
    localStorage.getItem('yikai:blox:template-recent:v1') || '[]'
  )[0])).toBe('builtin:image-text');

  await undo(page);
  await expectClean(page);
  await page.getByTestId('blox-prebuilt-open').click();
  await page.getByTestId('blox-template-quick-recent').click();
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]')).toBeVisible();
  await expect(page.getByTestId('blox-template-item')).toHaveCount(1);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();
  await page.getByTestId('blox-template-quick-favorites').click();
  await expect(page.getByTestId('blox-template-favorite-builtin:hero-intro')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-template-item')).toHaveCount(1);
});

test('prebuilt compact density shortens the library and persists locally @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop template density baseline');
  await page.evaluate(() => localStorage.removeItem('yikai:blox:template-density:v1'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();

  const standardButton = page.getByTestId('blox-template-density-standard');
  const compactButton = page.getByTestId('blox-template-density-compact');
  const libraryScroller = page.locator('[x-ref="templateDialog"] .blox-scroll');
  const firstCard = page.getByTestId('blox-template-item').first();
  await expect(standardButton).toHaveAttribute('aria-pressed', 'true');
  await expect(firstCard).toBeVisible();
  await expect(firstCard.getByTestId('blox-template-section-bar')).toBeVisible();
  await expect(firstCard.getByTestId('blox-template-insert')).toHaveClass(/bg-white/);
  await expect(firstCard.getByTestId('blox-template-insert')).toHaveClass(/text-blue-700/);
  const standardHeight = await firstCard.evaluate((element) => element.getBoundingClientRect().height);
  const standardScrollHeight = await libraryScroller.evaluate((element) => element.scrollHeight);
  expect(standardHeight).toBeGreaterThanOrEqual(250);

  await compactButton.click();
  await expect(compactButton).toHaveAttribute('aria-pressed', 'true');
  await expect.poll(() => page.evaluate(() => localStorage.getItem('yikai:blox:template-density:v1'))).toBe('compact');
  const compactHeight = await firstCard.evaluate((element) => element.getBoundingClientRect().height);
  const compactScrollHeight = await libraryScroller.evaluate((element) => element.scrollHeight);
  expect(compactHeight).toBeGreaterThanOrEqual(94);
  expect(compactHeight).toBeLessThanOrEqual(98);
  expect(compactScrollHeight).toBeLessThan(standardScrollHeight);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();
  await expect(page.getByTestId('blox-template-density-compact')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-template-item').first()).toHaveCSS('height', '96px');
});

test('prebuilt library restores session filters and scroll after closing or inserting @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop template session continuity baseline');
  await page.evaluate(() => sessionStorage.removeItem('yikai:blox:template-section-view:v2'));
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();

  const category = page.getByTestId('blox-template-category');
  const search = page.getByTestId('blox-template-search');
  await category.selectOption('content');
  await search.fill('图文');
  await page.getByTestId('blox-template-close').click();
  await expect.poll(() => page.evaluate(() => JSON.parse(
    sessionStorage.getItem('yikai:blox:template-section-view:v2') || '{}'
  ))).toMatchObject({ scope: 'local', category: 'content', quickFilter: 'recommended', query: '图文' });

  await page.getByTestId('blox-prebuilt-open').click();
  await expect(category).toHaveValue('content');
  await expect(search).toHaveValue('图文');
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]')).toBeVisible();
  await search.fill('');
  await category.selectOption('all');
  await page.getByTestId('blox-template-density-compact').click();
  const scroller = page.locator('[x-ref="templateScroll"]');
  await scroller.evaluate((element) => { element.scrollTop = Math.min(240, element.scrollHeight); });
  const savedScroll = await scroller.evaluate((element) => element.scrollTop);
  expect(savedScroll).toBeGreaterThan(100);
  await page.getByTestId('blox-template-close').click();

  await page.getByTestId('blox-prebuilt-open').click();
  await expect.poll(() => scroller.evaluate((element) => element.scrollTop)).toBeGreaterThanOrEqual(savedScroll - 2);
  await search.fill('图文');
  const imageText = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]');
  await imageText.getByTestId('blox-template-insert').click();
  await waitPreviewSettled(page);
  await expect(page.locator('[x-ref="templateDialog"]')).toBeHidden();
  await expect.poll(() => page.evaluate(() => JSON.parse(
    sessionStorage.getItem('yikai:blox:template-section-view:v2') || '{}'
  ).query)).toBe('图文');
  await undo(page);

  await page.getByTestId('blox-prebuilt-open').click();
  await expect(search).toHaveValue('图文');
  await search.fill('');
  await page.getByTestId('blox-template-quick-recent').click();
  await page.getByTestId('blox-template-close').click();
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-prebuilt-open').click();
  await expect(page.getByTestId('blox-template-quick-recent')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-testid="blox-template-item"][data-template-key="builtin:image-text"]')).toBeVisible();
  await expect(page.getByTestId('blox-template-item')).toHaveCount(1);
});

test('prebuilt empty states explain active filters and clear them in one action @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop prebuilt empty state baseline');
  await page.evaluate(() => {
    localStorage.removeItem('yikai:blox:template-favorites:v1');
    localStorage.removeItem('yikai:blox:template-recent:v1');
    sessionStorage.removeItem('yikai:blox:template-section-view:v2');
  });
  await page.reload({ waitUntil: 'domcontentloaded' });
  let catalogRequests = 0;
  await page.route('**/admin/blox_template_api.php?action=list&**', async route => {
    catalogRequests++;
    await page.waitForTimeout(500);
    await route.continue();
  });
  await page.getByTestId('blox-prebuilt-open').click();
  await expect(page.getByTestId('blox-template-item').first()).toBeVisible();
  expect(catalogRequests).toBeGreaterThan(0);
  const recommendedCount = await page.getByTestId('blox-template-item').count();
  expect(recommendedCount).toBeGreaterThan(0);

  await page.getByTestId('blox-template-quick-favorites').click();
  const empty = page.getByTestId('blox-template-empty');
  const clear = page.getByTestId('blox-template-clear-filters');
  await expect(empty).toHaveAttribute('data-empty-reason', 'favorites');
  await expect(clear).toBeVisible();
  await clear.click();
  await expect(page.getByTestId('blox-template-quick-recommended')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-template-item')).toHaveCount(recommendedCount);

  const search = page.getByTestId('blox-template-search');
  await search.fill('__missing_section__');
  await expect(empty).toHaveAttribute('data-empty-reason', 'search');
  await clear.click();
  await expect(search).toHaveValue('');
  await expect(page.getByTestId('blox-template-category')).toHaveValue('all');
  await expect(page.locator('[x-ref="templateScroll"]')).toHaveJSProperty('scrollTop', 0);
  await expect.poll(() => page.evaluate(() => JSON.parse(
    sessionStorage.getItem('yikai:blox:template-section-view:v2') || '{}'
  ))).toMatchObject({ category: 'all', quickFilter: 'recommended', query: '', scrollTop: 0 });
});

test('prebuilt section drags from the dock into a visible fixed canvas boundary @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop native drag baseline');
  const beforeSections = await countSections(page);
  await page.getByTestId('blox-prebuilt-open').click();

  const dialog = page.locator('[x-ref="templateDialog"]');
  const panel = dialog.locator(':scope > .relative');
  const source = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:hero-intro"]');
  await expect(dialog).toHaveAttribute('aria-modal', 'false');
  await expect(source).toHaveAttribute('draggable', 'true');
  const panelBox = await panel.boundingBox();
  expect(panelBox).not.toBeNull();
  expect(panelBox.x).toBe(0);
  expect(panelBox.width).toBeLessThanOrEqual(520);

  const contentFrame = await frame(page);
  const targetPath = await contentFrame.locator('[data-yk-sec]').evaluateAll((nodes) => {
    const target = nodes.find((node) => {
      const rect = node.getBoundingClientRect();
      const centerY = rect.top + rect.height / 2;
      return rect.width > 0 && rect.height > 0 && centerY >= 80 && centerY <= window.innerHeight - 80;
    });
    return target?.getAttribute('data-yk-sec') || '';
  });
  expect(targetPath).not.toBe('');
  const target = contentFrame.locator(`[data-yk-sec="${targetPath}"]`);
  const sourceBox = await source.boundingBox();
  const frameBox = await page.getByTestId('blox-canvas').boundingBox();
  const frameViewport = await contentFrame.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
  const targetRect = await target.evaluate((node) => {
    const rect = node.getBoundingClientRect();
    return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
  });
  expect(sourceBox).not.toBeNull();
  expect(frameBox).not.toBeNull();
  const targetPoint = {
    x: frameBox.x + (targetRect.left + targetRect.width / 2) * (frameBox.width / frameViewport.width),
    y: frameBox.y + (targetRect.top + targetRect.height * 0.75) * (frameBox.height / frameViewport.height),
  };
  expect(targetPoint.x).toBeGreaterThan(panelBox.x + panelBox.width + 16);

  const pageScrollBefore = await page.evaluate(() => window.scrollY);
  const hostScrollBefore = await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop);
  const frameScrollBefore = await contentFrame.evaluate(() => window.scrollY);
  let mouseDown = false;
  try {
    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + Math.min(96, sourceBox.height / 3));
    await page.mouse.down();
    mouseDown = true;
    await page.mouse.move(sourceBox.x + sourceBox.width / 2 + 12, sourceBox.y + Math.min(96, sourceBox.height / 3) + 4, { steps: 4 });
    await expect(page.getByTestId('blox-canvas-drop-bridge')).toHaveClass(/pointer-events-auto/);
    await page.mouse.move(targetPoint.x, targetPoint.y, { steps: 18 });
    await page.mouse.move(targetPoint.x + 1, targetPoint.y);
    await expect(contentFrame.locator('.yk-drop-line')).toBeVisible();
    await expect(contentFrame.locator('.yk-drop-line')).toHaveAttribute('data-yk-drop-intent', 'section');
    await expect(contentFrame.locator('.yk-drop-label')).toContainText('区块之后');
    await page.waitForTimeout(150);
    expect(await page.evaluate(() => window.scrollY)).toBe(pageScrollBefore);
    expect(await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop)).toBe(hostScrollBefore);
    expect(await contentFrame.evaluate(() => window.scrollY)).toBe(frameScrollBefore);
    await page.mouse.up();
    mouseDown = false;
    await waitPreviewSettled(page);
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections + 1);
    await expect((await frame(page)).getByText('以专业与稳健，陪伴客户长期成长')).toBeVisible();
  } finally {
    if (mouseDown) await page.mouse.up().catch(() => {});
    if (await editorHasChanges(page)) await undo(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections);
  await expectClean(page);
});

test('prebuilt section drags to an exact structure boundary without canvas scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop native drag baseline');
  const beforeSections = await countSections(page);
  expect(beforeSections).toBeGreaterThan(1);
  await page.getByTestId('blox-prebuilt-open').click();

  const dialog = page.locator('[x-ref="templateDialog"]');
  const source = page.locator('[data-testid="blox-template-item"][data-template-key="builtin:hero-intro"]');
  const targetIndex = 1;
  const target = page.getByTestId('blox-tree-section').nth(targetIndex).locator('[data-section-drag-handle]');
  const sourceBox = await source.boundingBox();
  const targetBox = await target.boundingBox();
  expect(sourceBox).not.toBeNull();
  expect(targetBox).not.toBeNull();

  const contentFrame = await frame(page);
  const pageScrollBefore = await page.evaluate(() => window.scrollY);
  const hostScrollBefore = await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop);
  const frameScrollBefore = await contentFrame.evaluate(() => window.scrollY);
  const treeScrollBefore = await page.getByTestId('blox-tree').evaluate((node) => node.scrollTop);
  let mouseDown = false;
  try {
    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + Math.min(96, sourceBox.height / 3));
    await page.mouse.down();
    mouseDown = true;
    await page.mouse.move(sourceBox.x + sourceBox.width / 2 + 12, sourceBox.y + Math.min(96, sourceBox.height / 3) + 4, { steps: 4 });
    await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + targetBox.height * 0.8, { steps: 24 });
    const indicator = page.locator(
      '[data-testid="blox-tree-drop-indicator"][data-drop-intent="section-after"][data-drop-valid="1"]:visible'
    );
    await expect(indicator).toBeVisible();
    await expect(indicator).toHaveText('插入到此区块之后');
    await page.waitForTimeout(150);
    expect(await page.evaluate(() => window.scrollY)).toBe(pageScrollBefore);
    expect(await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop)).toBe(hostScrollBefore);
    expect(await contentFrame.evaluate(() => window.scrollY)).toBe(frameScrollBefore);
    expect(await page.getByTestId('blox-tree').evaluate((node) => node.scrollTop)).toBe(treeScrollBefore);

    await page.mouse.up();
    mouseDown = false;
    await waitPreviewSettled(page);
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections + 1);
    await expect((await frame(page)).locator(`[data-yk-sec="${targetIndex + 1}"]`).getByText('以专业与稳健，陪伴客户长期成长')).toBeVisible();
  } finally {
    if (mouseDown) await page.mouse.up().catch(() => {});
    if (await editorHasChanges(page)) await undo(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections);
  await expectClean(page);
});

test('legacy service page can switch to editable built-in process template @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single template replacement baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  expect(fixtures.process_page).toBeGreaterThan(0);

  await openPageEditor(page, fixtures.process_page);
  await expect(page.getByTestId('blox-legacy-page-notice')).toBeVisible();
  await page.getByTestId('blox-legacy-page-templates').click();

  const template = page.locator(
    '[data-testid="blox-template-item"][data-template-key="builtin:service-process"]',
  );
  await expect(template).toBeVisible();
  await expect(template).toContainText('服务流程');
  page.once('dialog', (dialog) => dialog.accept());
  await template.getByTestId('blox-template-replace').click();

  await expect(page.getByTestId('blox-legacy-page-notice')).toBeHidden();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(5);
  await page.getByTestId('blox-tree-section').nth(1).click();
  const processGroup = page.locator(
    '[data-testid="blox-tree-element"][data-element-type="process-steps"]',
  );
  await expect(processGroup).toHaveCount(1);
  const steps = processGroup.locator('[data-sort-child-item][data-element-type="process-step"]');
  await expect(steps).toHaveCount(6);

  await processGroup.locator('[data-element-drag-handle]').click();
  const processManager = page.getByTestId('blox-process-manager');
  await expect(processManager).toBeVisible();
  await expect(processManager.getByTestId('blox-process-item')).toHaveCount(6);
  await expect(processManager.getByTestId('blox-process-item').first().locator('input').nth(1)).toHaveValue('需求沟通');
  await page.getByTestId('blox-process-add').click();
  await expect(processManager.getByTestId('blox-process-item')).toHaveCount(7);
  await expect(processManager.getByTestId('blox-process-item').last().locator('input').nth(1)).toHaveValue('新步骤 7');
  await expect(steps).toHaveCount(7);
  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(steps).toHaveCount(6);
  const processSection = processGroup.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]');
  await processSection.locator('[data-section-drag-handle]').click();
  await processGroup.locator('[data-element-drag-handle]').click();
  await expect(processManager.getByTestId('blox-process-item')).toHaveCount(6);

  await steps.first().click();
  await expect(page.locator('[data-control-key="title"] input')).toHaveValue('需求沟通');
  await expect(page.locator('[data-control-key="text"] textarea')).toHaveValue(/了解业务场景/);

  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(1);
  await expect(page.getByTestId('blox-legacy-page-notice')).toBeVisible();
  await expectClean(page);
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
  await page.getByTestId('blox-prebuilt-open').click();
  const item = page.getByTestId('blox-template-item');
  await expect(item).toHaveCount(1);
  await expect(item.getByTestId('blox-template-edit')).toHaveAttribute('href', '/admin/blox_editor.php?template=1');
  await item.getByTestId('blox-template-insert').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]', {
    hasText: 'E2E 模板标题',
  })).toHaveCount(1);
  expect(page.url()).toBe(originalURL);
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('template catalog separates local and remote libraries without trapping docked focus @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await page.route('**/admin/blox_template_api.php?action=list**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: 'ok',
        data: {
          items: [
            {
              key: 'local:1', type: 'section', name: 'Local section', source: 'local',
              provider: 'import', thumbnail: 'data:image/gif;base64,R0lGODlhAQABAAAAACw=', locked: false,
            },
            {
              key: 'remote:hero', type: 'section', name: 'Remote hero', source: 'remote',
              provider: 'update.yikaicms.com', thumbnail: '', locked: false,
            },
            {
              key: 'plugin:shop:grid', type: 'section', name: 'Plugin grid', source: 'plugin',
              provider: 'shop', thumbnail: '', locked: false,
            },
          ],
          remote_error: '',
        },
      }),
    });
  });

  const opener = page.getByTestId('blox-prebuilt-open');
  await opener.focus();
  await opener.click();
  const dialog = page.locator('[x-ref="templateDialog"]');
  const search = page.getByTestId('blox-template-search');
  await expect(dialog).toBeVisible();
  await expect(search).toBeFocused();
  await expect(page.getByTestId('blox-template-tab-local')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('blox-template-item')).toHaveCount(2);
  await expect(page.getByTestId('blox-template-item').first().locator('img')).toHaveAttribute('src', /^data:image\/gif/);
  const localCard = page.getByTestId('blox-template-item').filter({ hasText: 'Local section' });
  const pluginCard = page.getByTestId('blox-template-item').filter({ hasText: 'Plugin grid' });
  await expect(localCard.getByTestId('blox-template-edit')).toHaveAttribute('href', '/admin/blox_editor.php?template=1');
  await expect(pluginCard.getByTestId('blox-template-edit')).toHaveCount(0);

  await page.getByTestId('blox-template-tab-remote').click();
  await expect(page.getByTestId('blox-template-tab-remote')).toHaveAttribute('aria-selected', 'true');
  await expect(page.getByTestId('blox-template-item')).toHaveCount(1);
  await expect(page.getByTestId('blox-template-item')).toContainText('Remote hero');
  const remoteImport = page.getByTestId('blox-template-insert');
  await expect(remoteImport).toContainText('下载导入');
  const remoteButtonWidth = await remoteImport.evaluate((element) => element.getBoundingClientRect().width);
  const remoteCardWidth = await page.getByTestId('blox-template-item').evaluate((element) => element.getBoundingClientRect().width);
  expect(remoteButtonWidth + 16).toBeLessThan(remoteCardWidth);
  await expect(page.getByTestId('blox-template-edit')).toHaveCount(0);
  await page.getByTestId('blox-template-tab-local').click();
  await expect(page.getByTestId('blox-template-item')).toHaveCount(2);

  const lastFocusable = dialog.getByRole('link').last();
  await lastFocusable.focus();
  await page.keyboard.press('Tab');
  await expect(page.getByTestId('blox-template-close')).not.toBeFocused();
  await lastFocusable.focus();
  await page.keyboard.press('Escape');
  await expect(dialog).toBeHidden();
  await expect(opener).toBeFocused();
});

test('real remote template channel @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440' || process.env.BLOX_E2E_REMOTE !== '1', 'opt-in signed remote channel check');
  await page.getByTestId('blox-prebuilt-open').click();
  await page.getByTestId('blox-template-tab-remote').click();
  const remote = page.getByTestId('blox-template-item').filter({ has: page.locator('[class*="ti-cloud-download"]') }).first();
  await expect(remote).toBeVisible();
});

test('verified remote install reaches canvas and one-step undo @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'shared database integration baseline');
  const root = require('path').resolve(__dirname, '../..');
  const fixtureScript = require('path').resolve(__dirname, 'remote-template-fixture.php');
  const installed = JSON.parse(execFileSync('php', [fixtureScript, 'seed'], { cwd: root, encoding: 'utf8' }).trim());
  try {
    const fixtures = JSON.parse(require('fs').readFileSync(
      require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
    await page.goto('/admin/blox_editor.php?id=' + fixtures.blox_page, { waitUntil: 'domcontentloaded' });
    const initialCount = await countSections(page);
    await page.getByTestId('blox-prebuilt-open').click();
    await page.getByTestId('blox-template-tab-local').click();
    await page.getByTestId('blox-template-quick-all').click();
    const installedCard = page.getByTestId('blox-template-item').filter({ hasText: installed.name });
    await expect(installedCard).toBeVisible();
    await installedCard.getByTestId('blox-template-insert').click();
    await expect(page.getByTestId('blox-template-dialog')).toBeHidden();
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(initialCount + 1);
    await expect((await frame(page)).getByText('Remote journey verified')).toBeVisible();
    await expect(page.getByTestId('blox-undo')).toBeEnabled();
    await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(initialCount);
  } finally {
    execFileSync('php', [fixtureScript, 'cleanup'], { cwd: root });
  }
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

// ── 模板模式（r7）：头模板草稿编辑 = 可编辑区 + 首页正文只读灰罩上下文 ──
test('template manager exposes safe local header and footer starters @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop management baseline');
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });

  const presets = page.getByTestId('blox-area-presets');
  await expect(presets).toBeVisible();
  await expect(presets.getByTestId('blox-area-preset-install')).toHaveCount(11);
  await expect(presets.locator('.ti-layout-navbar')).toHaveCount(6);
  await expect(presets.locator('.ti-layout-bottombar')).toHaveCount(5);
  await expect(page.getByTestId('blox-default-theme-status')).toBeVisible();

  const areaRow = page.locator('tbody tr').filter({ has: page.getByTestId('blox-condition-toggle') }).first();
  await expect(areaRow.getByTestId('blox-condition-summary')).toBeVisible();
  await areaRow.getByTestId('blox-condition-toggle').click();
  const form = page.getByTestId('blox-condition-form').first();
  await expect(form).toBeVisible();
  await form.getByTestId('blox-condition-add').click();
  await form.getByTestId('blox-condition-main').last().selectOption('page');

  const picker = form.getByTestId('blox-condition-picker').last();
  await picker.click();
  const search = form.locator('input[type="search"]').last();
  await search.click();
  await expect(search).toBeFocused();
  await search.fill('#');
  await search.fill('');
  const choice = form.getByTestId('blox-condition-choice').first();
  await expect(choice).toBeVisible();
  await choice.click();
  await expect(picker).not.toContainText(/请选择|Select pages|選択してください/);
  await expect(form.locator('[name="conditions_json"]')).toHaveValue(/"main":"page","ids":\[\d+\]/);
});

test('template mode edits an isolated header and applies bundled starters @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.getByTestId('blox-tree')).toBeAttached();
  await page.waitForFunction(() => {
    const frame = document.querySelector('[data-testid="blox-canvas"]');
    return frame && frame.contentDocument && frame.contentDocument.readyState === 'complete'
      && frame.contentDocument.querySelectorAll('[data-yk-sec]').length > 0;
  });

  // 网页头是独立编辑对象：画布不附带正文，避免长页面干扰页头设计。
  const contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area="header"]')).toHaveCount(1);
  await expect(contentFrame.locator('.yk-ctx-dim')).toHaveCount(0);
  await expect(page.getByTestId('blox-tree-section-label')).toHaveText('网页页头');

  // 网页头模式给专属样式库，不再把普通正文区块入口伪装成网页头模板。
  await expect(page.getByTestId('blox-prebuilt-open')).toHaveCount(0);
  const headerPresetEntry = page.getByTestId('blox-right-panel').getByTestId('blox-header-presets-open');
  await expect(headerPresetEntry).toContainText('网页头样式');
  await expect(page.getByTestId('blox-ctx-select')).toHaveCount(0);
  await expect(page.getByTestId('blox-preview-language-control')).toHaveCount(0);
  await headerPresetEntry.click();
  const headerPresets = page.getByTestId('blox-header-presets');
  await expect(headerPresets).toBeVisible();
  await expect(headerPresets.getByTestId('blox-header-preset-apply')).toHaveCount(6);
  await expect(headerPresets.getByTestId('blox-header-preset-preview')).toHaveCount(6);
  const corporatePreset = headerPresets.getByTestId('blox-header-preset-corporate-site-header');
  const corporateName = await corporatePreset.locator('h3').innerText();
  const corporatePreviewButton = corporatePreset.getByTestId('blox-header-preset-preview');
  await corporatePreviewButton.click();
  await expect(corporatePreset).toHaveAttribute('data-selected', 'true');
  const presetPreview = page.getByTestId('blox-header-preset-preview-dialog');
  await expect(presetPreview).toBeVisible();
  await expect(presetPreview).toContainText(corporateName);
  await expect(presetPreview.getByTestId('blox-header-preset-preview-close')).toBeFocused();
  const presetFrame = presetPreview.getByTestId('blox-header-preset-preview-frame');
  await expect(presetFrame).toHaveAttribute('src', /template_area=header&area_preset=corporate-site-header/);
  const presetContent = presetFrame.contentFrame();
  await expect(presetContent.locator('#siteHeader')).toBeVisible();
  const hasSiteBrand = await presetContent.locator('#siteHeader').evaluate((header) => (
    /Yikai/i.test(header.textContent || '')
    || Array.from(header.querySelectorAll('img')).some((image) => /Yikai/i.test(image.alt || ''))
  ));
  expect(hasSiteBrand).toBe(true);
  await expect(presetContent.locator('#siteHeader ul').first()).toBeVisible();
  await expect(presetContent.locator('#siteHeader [data-yk-language-switcher]').first()).toBeVisible();

  const presetViewport = presetPreview.getByTestId('blox-header-preset-preview-viewport');
  const desktopToggle = presetPreview.getByTestId('blox-header-preset-preview-desktop');
  const mobileToggle = presetPreview.getByTestId('blox-header-preset-preview-mobile');
  await expect(desktopToggle).toHaveAttribute('aria-pressed', 'true');
  const desktopWidth = await presetViewport.evaluate((element) => element.getBoundingClientRect().width);
  expect(desktopWidth).toBeGreaterThan(1200);
  await mobileToggle.click();
  await expect(mobileToggle).toHaveAttribute('aria-pressed', 'true');
  await expect(presetViewport).toHaveAttribute('data-device', 'mobile');
  const mobileWidth = await presetViewport.evaluate((element) => element.getBoundingClientRect().width);
  expect(mobileWidth).toBeLessThanOrEqual(390);
  expect(mobileWidth).toBeLessThan(desktopWidth);
  await page.keyboard.press('Escape');
  await expect(presetPreview).toBeHidden();
  await expect(headerPresets).toBeVisible();
  await expect(corporatePreviewButton).toBeFocused();
  const initialCount = await countSections(page);
  const searchPreset = headerPresets.getByTestId('blox-header-preset-search-site-header');
  await searchPreset.getByTestId('blox-header-preset-apply').click();
  await expect(headerPresets).toBeHidden();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(2);
  await expect(page.getByTestId('blox-tree-section-label')).toHaveText(['网页页头 1', '网页页头 2']);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  await headerPresetEntry.click();
  await expect(headerPresets).toBeVisible();
  await expect(searchPreset).toHaveAttribute('data-current', 'true');
  await expect(searchPreset).toHaveAttribute('data-selected', 'true');
  await expect(searchPreset.getByTestId('blox-header-preset-apply')).toBeDisabled();
  await page.keyboard.press('Escape');
  await expect(headerPresets).toBeHidden();
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(initialCount);
  await expectClean(page);

  // 模板模式工具栏：发布模板按钮在场；首页发布/回退不在场
  await expect(page.getByTestId('blox-publish-template')).toBeAttached();
  await expect(page.getByTestId('blox-publish')).toHaveCount(0);
  await expect(page.getByTestId('blox-rollback')).toHaveCount(0);

  // 编辑 → 存草稿 → 重载持久化 → API 复位种子（幂等：无论此前状态如何，
  // 结束时草稿 = fixture 单源内容；审计指出「接受种子增长」违背可重复基线）。
  const seedDoc = JSON.stringify(JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, 'fixtures/header-template.json'), 'utf8')).document);
  const before = await countSections(page);
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  const savePair = Promise.all([
    page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname === '/admin/blox_template_api.php'),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/blox_template_api.php'),
  ]);
  await page.getByTestId('blox-save').click();
  const [saveReq, saveRes] = await savePair;
  expect((await saveRes.json()).code).toBe(0);
  await expectClean(page);

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);

  // 复位：CSRF 从真实保存请求捕获，API 直写种子文档（不走 UI，无脆弱定位）
  const token = new URLSearchParams(saveReq.postData() || '').get('_token');
  const restore = await page.request.post('/admin/blox_template_api.php', {
    form: {
      action: 'save_draft',
      id: String(fixtures.blox_header_template),
      blocks_data: seedDoc,
      _token: token || '',
    },
  });
  expect((await restore.json()).code).toBe(0);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(1);
});

test('header state selector drives the semantic preview shell @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-header-state-settings').locator('summary').click();

  const previewRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    const body = new URLSearchParams(request.postData() || '');
    return request.method() === 'POST'
      && url.pathname === '/admin/blox_preview.php'
      && body.get('header_state') === 'overlay';
  });
  await page.getByTestId('blox-header-state-overlay').click();
  await previewRequest;

  const contentFrame = await frame(page);
  const header = contentFrame.locator('#siteHeader');
  await expect(header).toHaveClass(/yk-header-preview-overlay/);
  await expect(header).toHaveAttribute('data-yk-header-state', 'overlay');
  await expectClean(page);

  const opacityRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    const body = new URLSearchParams(request.postData() || '');
    const settings = JSON.parse(body.get('blocks_data') || '{}').settings || {};
    return request.method() === 'POST'
      && url.pathname === '/admin/blox_preview.php'
      && settings.header_states?.overlay?.background === 'rgba(255,255,255,0.55)';
  });
  await page.getByTestId('blox-header-state-opacity').fill('55');
  await opacityRequest;
  await expect(header).toHaveAttribute('style', /--yk-header-overlay-bg:rgba\(255,255,255,0\.55\)/);
  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await undo(page);
  await expect(header).toHaveAttribute('style', /--yk-header-overlay-bg:transparent/);
  await expectClean(page);

  await expect(page.getByTestId('blox-redo')).toBeEnabled();
  await performPreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await expect(header).toHaveAttribute('style', /--yk-header-overlay-bg:rgba\(255,255,255,0\.55\)/);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  await undo(page);
  await expectClean(page);
});

test('header preset chooser adapts across viewports @ci', async ({ page }, testInfo) => {
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });

  if (testInfo.project.name === 'desktop-1440') {
    await page.getByTestId('blox-right-panel').getByTestId('blox-header-presets-open').click();
  } else {
    await page.getByTestId('blox-mobile-actions-open').click();
    await page.getByTestId('blox-mobile-actions').getByRole('button', { name: '网页头样式' }).click();
  }

  const dialog = page.getByTestId('blox-header-presets');
  await expect(dialog).toBeVisible();
  const panel = dialog.locator(':scope > .relative');
  const box = await panel.boundingBox();
  const viewport = page.viewportSize();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
  expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);
  await expect(dialog.locator('[data-testid^="blox-header-preset-"][data-selected]')).toHaveCount(6);
  await expect(dialog.locator('[data-testid^="blox-header-preset-"][data-selected]').first().locator('span').filter({ hasText: /内容宽度|全宽|居中品牌|顶栏|站内搜索/ }).first()).toBeVisible();

  const firstPreviewButton = dialog.getByTestId('blox-header-preset-preview').first();
  await firstPreviewButton.click();
  const previewDialog = page.getByTestId('blox-header-preset-preview-dialog');
  await expect(previewDialog).toBeVisible();
  const previewPanel = previewDialog.getByTestId('blox-header-preset-preview-panel');
  const previewBox = await previewPanel.boundingBox();
  expect(previewBox.x).toBeGreaterThanOrEqual(0);
  expect(previewBox.y).toBeGreaterThanOrEqual(0);
  expect(previewBox.x + previewBox.width).toBeLessThanOrEqual(viewport.width);
  expect(previewBox.y + previewBox.height).toBeLessThanOrEqual(viewport.height);
  const previewHeaderMetrics = await previewPanel.locator(':scope > div').first().evaluate((header) => {
    const title = header.children[0];
    const controls = header.children[1];
    return {
      titleWidth: title.getBoundingClientRect().width,
      controlsWidth: controls.getBoundingClientRect().width,
      headerWidth: header.getBoundingClientRect().width,
    };
  });
  expect(previewHeaderMetrics.titleWidth).toBeGreaterThan(160);
  expect(previewHeaderMetrics.controlsWidth).toBeLessThan(previewHeaderMetrics.headerWidth);
  const presetFrame = previewDialog.getByTestId('blox-header-preset-preview-frame');
  await expect(presetFrame).toHaveAttribute('src', /template_area=header&area_preset=clean-site-header/);
  await expect(presetFrame.contentFrame().locator('#siteHeader')).toBeVisible();
  await expect(previewDialog.getByTestId('blox-header-preset-difference')).toBeVisible();
  await previewDialog.getByTestId('blox-header-preset-preview-next').click();
  await expect(presetFrame).toHaveAttribute('src', /template_area=header&area_preset=full-width-site-header/);
  await previewDialog.getByTestId('blox-header-preset-preview-previous').click();
  await expect(presetFrame).toHaveAttribute('src', /template_area=header&area_preset=clean-site-header/);
  const previewViewport = previewDialog.getByTestId('blox-header-preset-preview-viewport');
  await previewDialog.getByTestId('blox-header-preset-preview-mobile').click();
  await expect(previewViewport).toHaveAttribute('data-device', 'mobile');
  const mobilePreviewBox = await previewViewport.boundingBox();
  expect(mobilePreviewBox.width).toBeLessThanOrEqual(390);
  expect(mobilePreviewBox.x).toBeGreaterThanOrEqual(0);
  expect(mobilePreviewBox.x + mobilePreviewBox.width).toBeLessThanOrEqual(viewport.width);
  await previewDialog.getByTestId('blox-header-preset-preview-state-overlay').click();
  await expect(presetFrame.contentFrame().locator('#siteHeader')).toHaveAttribute('data-yk-header-state', 'overlay');
  await previewDialog.getByTestId('blox-header-preset-preview-drawer').click();
  await expect(presetFrame.contentFrame().locator('[data-yk-drawer-panel]')).toBeVisible();
  await expect(presetFrame.contentFrame().locator('[data-yk-drawer-open]')).toHaveAttribute('aria-expanded', 'true');
  await previewDialog.getByTestId('blox-header-preset-preview-close').click();
  await expect(previewDialog).toBeHidden();
  await expect(dialog).toBeVisible();
  await expect(firstPreviewButton).toBeFocused();
});

test('footer style library previews and applies practical starters @ci', async ({ page }, testInfo) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'footer editing is an advanced feature');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_footer_template,
    { waitUntil: 'domcontentloaded' });

  if (testInfo.project.name === 'desktop-1440') {
    const entry = page.getByTestId('blox-right-panel').getByTestId('blox-footer-presets-open');
    await expect(entry).toContainText('网页脚样式');
    await entry.click();
  } else {
    await page.getByTestId('blox-mobile-actions-open').click();
    await page.getByTestId('blox-mobile-actions').getByRole('button', { name: '网页脚样式' }).click();
  }

  const dialog = page.getByTestId('blox-header-presets');
  await expect(dialog).toBeVisible();
  await expect(dialog.getByTestId('blox-header-preset-apply')).toHaveCount(5);
  await expect(dialog).toContainText('紧凑网页脚');
  await expect(dialog).toContainText('联系方式网页脚');
  await expect(dialog).toContainText('搜索导航网页脚');

  const searchPreset = dialog.getByTestId('blox-header-preset-search-site-footer');
  await searchPreset.getByTestId('blox-header-preset-preview').click();
  const preview = page.getByTestId('blox-header-preset-preview-dialog');
  await expect(preview).toBeVisible();
  const previewFrame = preview.getByTestId('blox-header-preset-preview-frame');
  await expect(previewFrame).toHaveAttribute('src', /template_area=footer&area_preset=search-site-footer/);
  const previewContent = previewFrame.contentFrame();
  await expect(previewContent.locator('[data-yk-area="footer"]')).toBeVisible();
  await expect(previewContent.locator('.yk-ctx-dim')).toHaveCount(0);
  await expect(preview.getByTestId('blox-header-preset-preview-state-normal')).toBeHidden();
  await expect(preview.getByTestId('blox-header-preset-preview-drawer')).toBeHidden();

  const previewViewport = preview.getByTestId('blox-header-preset-preview-viewport');
  await preview.getByTestId('blox-header-preset-preview-mobile').click();
  await expect(previewViewport).toHaveAttribute('data-device', 'mobile');
  const mobilePreviewBox = await previewViewport.boundingBox();
  expect(mobilePreviewBox.width).toBeLessThanOrEqual(390);
  await preview.getByTestId('blox-header-preset-preview-close').click();
  await expect(preview).toBeHidden();

  if (testInfo.project.name === 'desktop-1440') {
    await searchPreset.getByTestId('blox-header-preset-apply').click();
    await expect(dialog).toBeHidden();
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(3);
    await expect(page.getByTestId('blox-dirty')).toBeVisible();
    await undo(page);
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(2);
    await expectClean(page);
  }
});

test('shared color picker adapts across viewports @ci', async ({ page }) => {
  await page.evaluate(() => {
    const data = window.Alpine.$data(document.body);
    window.__bloxColorResult = '#ffffff';
    data.openEditorColorPicker({
      currentTarget: { getBoundingClientRect: () => ({ left: 12, right: 240, top: 120, bottom: 160 }) },
    }, 'e2e-color', 'E2E Color', '#ffffff', '#ffffff', true, (value) => {
      window.__bloxColorResult = value;
    });
  });

  const picker = page.getByTestId('blox-editor-color-picker');
  await expect(picker).toBeVisible();
  const box = await picker.boundingBox();
  const viewport = page.viewportSize();
  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
  expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBe(0);

  await page.getByTestId('blox-editor-color-token-primary').click();
  await expect.poll(() => page.evaluate(() => window.__bloxColorResult)).toBe('var(--yk-color-primary)');
  await page.getByTestId('blox-editor-color-clear').click();
  await expect.poll(() => page.evaluate(() => window.__bloxColorResult)).toBe('');
  await expect(picker).toBeHidden();
});

test('sticky header behavior and device scope reach the preview shell @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });

  const headerSettings = page.getByTestId('blox-sticky-settings');
  await expect(headerSettings).toBeVisible();
  await expect(headerSettings.locator('summary')).toContainText('网页头设置');
  await headerSettings.locator('summary').click();
  await expect(page.getByTestId('blox-sticky-toggle')).toBeVisible();
  await performPreviewUpdate(page, () => page.getByTestId('blox-sticky-toggle').locator('input').check());
  await expect(page.getByTestId('blox-sticky-options')).toBeVisible();
  for (const device of ['desktop', 'tablet', 'mobile']) {
    const deviceLabel = page.getByTestId(`blox-sticky-device-${device}`).locator('xpath=..');
    await expect(deviceLabel).toHaveCSS('white-space', 'nowrap');
    expect(await deviceLabel.evaluate((element) => element.scrollWidth <= element.clientWidth)).toBe(true);
  }

  const behaviorRequest = page.waitForRequest((request) => {
    const url = new URL(request.url());
    const body = new URLSearchParams(request.postData() || '');
    return request.method() === 'POST'
      && url.pathname === '/admin/blox_preview.php'
      && JSON.parse(body.get('blocks_data') || '{}').settings?.sticky_behavior === 'scroll-up';
  });
  await page.getByTestId('blox-sticky-behavior').selectOption('scroll-up');
  await behaviorRequest;

  await performPreviewUpdate(page, () => page.getByTestId('blox-sticky-device-mobile').uncheck());
  let contentFrame = await frame(page);
  let header = contentFrame.locator('#siteHeader');
  await expect(header).toHaveAttribute('data-yk-sticky-behavior', 'scroll-up');
  await expect(header).toHaveAttribute('data-yk-sticky-desktop', '1');
  await expect(header).toHaveAttribute('data-yk-sticky-tablet', '1');
  await expect(header).toHaveAttribute('data-yk-sticky-mobile', '0');

  await performPreviewUpdate(page, () => page.getByTestId('blox-sticky-device-mobile').check());
  await performPreviewUpdate(page, () => page.getByTestId('blox-sticky-behavior').selectOption('always'));
  await performPreviewUpdate(page, () => page.getByTestId('blox-sticky-toggle').locator('input').uncheck());
  await expectClean(page);
});

test('template publishing retries only after explicit conflict confirmation @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });

  const publishBodies = [];
  await page.route('**/admin/blox_template_api.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    if (body.get('action') !== 'publish') {
      await route.continue();
      return;
    }
    publishBodies.push(body);
    const confirmed = body.get('confirm_conflict') === '1';
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify(confirmed
        ? { code: 0, msg: 'ok', data: { id: fixtures.blox_header_template } }
        : { code: 409, msg: '显示范围重叠；首页条件优先', data: null }),
    });
  });

  let dialogs = 0;
  page.on('dialog', async (dialog) => {
    dialogs += 1;
    await dialog.accept();
  });
  await page.getByTestId('blox-publish-template').click();
  await expect.poll(() => publishBodies.length).toBe(2);
  expect(dialogs).toBe(2);
  expect(publishBodies[0].get('confirm_conflict')).toBeNull();
  expect(publishBodies[1].get('confirm_conflict')).toBe('1');
  unsafeWrites.length = 0;
});

// ── 模板模式（r9）：URL 上下文驱动隔离预览 + Resolver 命中上报 ──
test('header preview context reports resolver hit without rendering page body @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });
  const canvasReady = () => page.waitForFunction(() => {
    const f = document.querySelector('[data-testid="blox-canvas"]');
    return f && f.contentDocument && f.contentDocument.readyState === 'complete'
      && f.contentDocument.querySelector('[data-yk-area]') !== null;
  });
  await canvasReady();

  // 上下文由入口 URL 决定，编辑器不再展示难以理解的上下文下拉。
  await expect(page.getByTestId('blox-ctx-select')).toHaveCount(0);
  let contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area][data-yk-ctx-hit]')).toHaveCount(1);

  // 页面上下文仍由 Resolver 命中，但画布保持纯网页头。
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template
    + '&preview_context=' + encodeURIComponent(`page:${fixtures.process_page}`),
  { waitUntil: 'domcontentloaded' });
  await canvasReady();
  contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area][data-yk-ctx-hit]')).toHaveCount(1);
  await expect(contentFrame.locator('.yk-ctx-dim')).toHaveCount(0);
});

// ── 画布插入轨道（r13）：区块边界精确插入 + 末尾常驻入口 ──
test('canvas insert rails add section at exact boundary @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);
  expect(before).toBeGreaterThan(1);
  const contentFrame = await frame(page);

  // 第 2 个区块的上缘轨道：点「+」出快捷面板，选两列 → 新区块插在 index 1
  const rail = contentFrame.locator('[data-yk-insert="1"]');
  await rail.evaluate((el) => el.click());
  const pop = contentFrame.locator('.yk-insert-pop');
  await expect(pop).toBeVisible();
  await pop.locator('.yk-insert-pop-btn').nth(1).evaluate((el) => el.click()); // 两列
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  // 插入即选中，且位置正确（selectedSi=1 → 结构树第 2 项高亮由选择态保证；直接断言画布新区块两列）
  await expect(contentFrame.locator('[data-yk-sec="1"] [data-yk-col]')).toHaveCount(2);

  // 一次 undo 完整撤销
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);

  // 末尾不再渲染常驻添加栏，避免画布底部出现额外空白和误导性操作条。
  const tailRail = contentFrame.locator('.yk-insert-rail-tail');
  await expect(tailRail).toHaveCount(0);
});

// ── 画布空目标快捷添加（r18）：空列/空容器定位到既有元素库，不刷新页面 ──
test('empty canvas targets open element library at the exact node @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();

  const before = await countSections(page);
  await page.getByTestId('blox-add-section-2').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  const sectionIndex = before;
  const section = page.getByTestId('blox-tree-section').nth(sectionIndex);
  const columns = section.getByTestId('blox-tree-column');
  await waitPreviewSettled(page);

  let contentFrame = await frame(page);
  const secondColumnAdd = contentFrame.locator(`[data-yk-quick-add="column:${sectionIndex}.1"]`);
  await pointerClick(page, secondColumnAdd);
  await expect(page.getByTestId('blox-add-element-heading')).toBeVisible();
  const headingTile = page.getByTestId('blox-add-element-heading');
  const secondColumnBefore = await columns.nth(1).getByTestId('blox-tree-element').count();
  await headingTile.click();
  await expect(columns.nth(1).getByTestId('blox-tree-element')).toHaveCount(secondColumnBefore);
  await headingTile.dragTo(columns.nth(1));
  await expect(columns.nth(1).getByTestId('blox-tree-element')).toHaveCount(secondColumnBefore + 1);

  await waitPreviewSettled(page);
  contentFrame = await frame(page);
  const firstColumnAdd = contentFrame.locator(`[data-yk-quick-add="column:${sectionIndex}.0"]`);
  await pointerClick(page, firstColumnAdd);
  await page.getByTestId('blox-add-element-container').press('Enter');
  await expect(columns.nth(0).getByTestId('blox-tree-element')).toHaveCount(1);

  await waitPreviewSettled(page);
  contentFrame = await frame(page);
  const containerAdd = contentFrame.locator(`[data-yk-quick-add="container:${sectionIndex}.0.0"]`);
  await pointerClick(page, containerAdd);
  await page.getByTestId('blox-add-element-heading').press('Enter');
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0.0"]`)).toHaveCount(1);
  expect(page.url()).toBe(originalURL);

  await undo(page);
  await undo(page);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('canvas drag labels and inserts into a container center @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop canvas drag baseline');
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();

  const before = await countSections(page);
  try {
    await page.getByTestId('blox-add-section-1').click();
    const section = page.getByTestId('blox-tree-section').last();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId('blox-add-element-container').press('Enter');
    const treeContainer = section.getByTestId('blox-tree-element').first();
    const containerId = await treeContainer.getAttribute('data-item-id');
    expect(containerId).toBeTruthy();
    await waitPreviewSettled(page);

    await page.getByTestId('blox-library-open').click();
    const headingTile = page.getByTestId('blox-add-element-heading');
    let contentFrame = await frame(page);
    const container = contentFrame.locator(`[data-yk-el-id="${containerId}"]`);
    await container.evaluate((wrapper) => {
      const visibleNode = (node) => {
        const rect = node.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) return node;
        for (const child of node.children) {
          const found = visibleNode(child);
          if (found) return found;
        }
        return null;
      };
      const target = visibleNode(wrapper);
      if (!target) throw new Error('Canvas container box is unavailable');
      target.scrollIntoView({ block: 'center', inline: 'nearest' });
    });
    const sourceTransfer = await page.evaluateHandle(() => new DataTransfer());
    await headingTile.dispatchEvent('dragstart', { dataTransfer: sourceTransfer });
    const dragState = await contentFrame.evaluate((id) => {
      const wrapper = document.querySelector(`[data-yk-el-id="${CSS.escape(id)}"]`);
      const visibleNode = (node) => {
        if (!node) return null;
        const rect = node.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) return node;
        for (const child of node.children) {
          const found = visibleNode(child);
          if (found) return found;
        }
        return null;
      };
      const target = visibleNode(wrapper);
      if (!target) throw new Error('Canvas container target is unavailable');
      const rect = target.getBoundingClientRect();
      const transfer = new DataTransfer();
      transfer.setData('application/x-yikai-blox', JSON.stringify({ version: 1, source: 'palette', type: 'heading' }));
      transfer.setData('text/plain', 'heading');
      window.__ykTestDropTransfer = transfer;
      target.dispatchEvent(new DragEvent('dragover', {
        bubbles: true,
        cancelable: true,
        dataTransfer: transfer,
        clientX: rect.left + rect.width / 2,
        clientY: rect.top + rect.height / 2,
      }));
      const indicator = document.querySelector('.yk-drop-line');
      return {
        hasSection: !!target.closest('[data-yk-sec]'),
        path: wrapper.getAttribute('data-yk-el'),
        type: wrapper.getAttribute('data-yk-el-type'),
        transferType: transfer.getData('text/plain'),
        intent: indicator ? indicator.getAttribute('data-yk-drop-intent') : null,
        display: indicator ? indicator.style.display : null,
      };
    }, containerId);
    expect(dragState).toMatchObject({
      hasSection: true,
      type: 'container',
      transferType: 'heading',
      intent: 'container',
      display: 'block',
    });
    const indicator = contentFrame.locator('.yk-drop-line[data-yk-drop-intent="container"]');
    await expect(indicator).toBeVisible();
    await expect(indicator.locator('.yk-drop-label')).toHaveText('放入此容器');
    await expect(indicator).toHaveAttribute('data-yk-drop-valid', '1');
    await contentFrame.evaluate((id) => {
      const wrapper = document.querySelector(`[data-yk-el-id="${CSS.escape(id)}"]`);
      const visibleNode = (node) => {
        if (!node) return null;
        const rect = node.getBoundingClientRect();
        if (rect.width > 0 && rect.height > 0) return node;
        for (const child of node.children) {
          const found = visibleNode(child);
          if (found) return found;
        }
        return null;
      };
      const target = visibleNode(wrapper);
      const transfer = window.__ykTestDropTransfer;
      if (!target || !transfer) throw new Error('Canvas drop state is unavailable');
      const rect = target.getBoundingClientRect();
      target.dispatchEvent(new DragEvent('drop', {
        bubbles: true,
        cancelable: true,
        dataTransfer: transfer,
        clientX: rect.left + rect.width / 2,
        clientY: rect.top + rect.height / 2,
      }));
      delete window.__ykTestDropTransfer;
    }, containerId);
    await headingTile.dispatchEvent('dragend', { dataTransfer: sourceTransfer });
    await sourceTransfer.dispose();

    await waitPreviewSettled(page);
    contentFrame = await frame(page);
    await expect(treeContainer.locator('[data-sort-child-item]')).toHaveCount(1);
    await expect(contentFrame.locator(`[data-yk-el-id="${containerId}"] [data-yk-el-type="heading"]`)).toHaveCount(1);
  } finally {
    if (await editorHasChanges(page)) await restoreClean(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('native palette drag keeps the canvas fixed and inserts in view @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop native drag baseline');
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();

  const beforeSections = await countSections(page);
  const beforeElements = await page.getByTestId('blox-tree-element').count();
  try {
    const source = page.getByTestId('blox-add-element-heading').first();
    const contentFrame = await frame(page);
    const visibleTargetPath = await contentFrame.locator('[data-yk-col]').evaluateAll((nodes) => {
      const target = nodes.find((node) => {
        const rect = node.getBoundingClientRect();
        const centerY = rect.top + rect.height / 2;
        return rect.width > 0 && rect.height > 0 && centerY >= 0 && centerY <= window.innerHeight;
      });
      return target?.getAttribute('data-yk-col') || '';
    });
    expect(visibleTargetPath).not.toBe('');
    const target = contentFrame.locator(`[data-yk-col="${visibleTargetPath}"]`);
    const sourceBox = await source.boundingBox();
    const frameBox = await page.getByTestId('blox-canvas').boundingBox();
    const bridgeBox = await page.getByTestId('blox-canvas-drop-bridge').boundingBox();
    const frameViewport = await contentFrame.evaluate(() => ({ width: window.innerWidth, height: window.innerHeight }));
    const pageScrollBefore = await page.evaluate(() => window.scrollY);
    const canvasHostScrollBefore = await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop);
    const canvasScrollBefore = await contentFrame.evaluate(() => window.scrollY);
    expect(sourceBox).not.toBeNull();
    expect(frameBox).not.toBeNull();
    expect(bridgeBox).not.toBeNull();
    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
    await page.mouse.down();
    await page.mouse.move(sourceBox.x + sourceBox.width / 2 + 12, sourceBox.y + sourceBox.height / 2 + 4, { steps: 4 });
    await expect(page.getByTestId('blox-canvas-drop-bridge')).toHaveClass(/pointer-events-auto/);
    await expect(page.getByTestId('blox-canvas-host')).toHaveClass(/overflow-hidden/);
    const targetRect = await target.evaluate((node) => {
      const rect = node.getBoundingClientRect();
      return { left: rect.left, top: rect.top, width: rect.width, height: rect.height };
    });
    expect(targetRect.top + targetRect.height / 2).toBeGreaterThanOrEqual(0);
    expect(targetRect.top + targetRect.height / 2).toBeLessThanOrEqual(frameViewport.height);
    const targetCenter = {
      x: frameBox.x + (targetRect.left + targetRect.width / 2) * (frameBox.width / frameViewport.width),
      y: frameBox.y + (targetRect.top + targetRect.height / 2) * (frameBox.height / frameViewport.height),
    };
    const iframeTargetHit = await contentFrame.evaluate(({ x, y }) => {
      const node = document.elementFromPoint(x, y);
      const column = node?.closest?.('[data-yk-col]');
      return {
        tag: node?.tagName || '',
        column: column?.getAttribute('data-yk-col') || '',
      };
    }, { x: targetRect.left + targetRect.width / 2, y: targetRect.top + targetRect.height / 2 });
    expect(iframeTargetHit.column, JSON.stringify({ iframeTargetHit, targetRect, targetCenter, frameBox, frameViewport })).toBe(visibleTargetPath);
    await page.mouse.move(targetCenter.x, targetCenter.y, { steps: 16 });
    await page.mouse.move(targetCenter.x + 1, targetCenter.y);
    const parentHit = await page.evaluate(({ x, y }) => document.elementFromPoint(x, y)?.getAttribute('data-testid') || '', {
      x: targetCenter.x,
      y: targetCenter.y,
    });
    expect(parentHit).toBe('blox-canvas-drop-bridge');
    await expect(contentFrame.locator('html')).toHaveClass(/yk-palette-dragging/);
    await expect(contentFrame.locator('.yk-drop-line')).toBeVisible();
    await page.waitForTimeout(150);
    expect(await page.evaluate(() => window.scrollY)).toBe(pageScrollBefore);
    expect(await page.getByTestId('blox-canvas-host').evaluate((node) => node.scrollTop)).toBe(canvasHostScrollBefore);
    expect(await contentFrame.evaluate(() => window.scrollY)).toBe(canvasScrollBefore);
    await page.mouse.up();
    await waitPreviewSettled(page);
    await expect(page.getByTestId('blox-canvas-host')).toHaveClass(/overflow-auto/);
    await expect(page.getByTestId('blox-canvas-host')).not.toHaveClass(/overflow-hidden/);
    await expect((await frame(page)).locator('html')).not.toHaveClass(/yk-palette-dragging/);
    await expect(page.getByTestId('blox-tree-element')).toHaveCount(beforeElements + 1);
    await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections);
  } finally {
    if (await editorHasChanges(page)) await restoreClean(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(beforeSections);
  await expectClean(page);
});

test('palette drag uses a compact ghost and Escape cancels cleanly @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop native drag baseline');
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();

  const before = await countSections(page);
  let mouseDown = false;
  try {
    const source = page.getByTestId('blox-add-element-heading').first();
    const sourceLabel = (await source.locator('span').last().textContent())?.trim() || '';
    const sourceBox = await source.boundingBox();
    const bridge = page.getByTestId('blox-canvas-drop-bridge');
    const bridgeBox = await bridge.boundingBox();
    expect(sourceBox).not.toBeNull();
    expect(bridgeBox).not.toBeNull();

    await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
    await page.mouse.down();
    mouseDown = true;
    await page.mouse.move(sourceBox.x + sourceBox.width / 2 + 12, sourceBox.y + sourceBox.height / 2 + 4, { steps: 4 });
    await expect(bridge).toHaveClass(/pointer-events-auto/);
    await expect(page.getByTestId('blox-canvas-host')).toHaveClass(/overflow-hidden/);
    const ghost = page.getByTestId('blox-palette-drag-ghost');
    await expect(ghost).toHaveCount(1);
    await expect(ghost).toContainText(sourceLabel);

    await page.mouse.move(bridgeBox.x + bridgeBox.width / 2, bridgeBox.y + Math.min(240, bridgeBox.height / 2), { steps: 12 });
    const contentFrame = await frame(page);
    await expect(contentFrame.locator('html')).toHaveClass(/yk-palette-dragging/);
    await page.keyboard.press('Escape');
    await page.mouse.up();
    mouseDown = false;

    await expect(ghost).toHaveCount(0);
    await expect(bridge).toHaveClass(/pointer-events-none/);
    await expect(page.getByTestId('blox-canvas-host')).toHaveClass(/overflow-auto/);
    await expect(contentFrame.locator('html')).not.toHaveClass(/yk-palette-dragging/);
    await expect(contentFrame.locator('.yk-drop-line')).toBeHidden();
  } finally {
    if (mouseDown) await page.mouse.up().catch(() => {});
    if (await editorHasChanges(page)) await restoreClean(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});

test('structure tree drag labels before and inside intentions @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop structure drag baseline');
  const clear = page.getByTestId('blox-clear-selection');
  if (await clear.isVisible()) await clear.click();

  const before = await countSections(page);
  try {
    await page.getByTestId('blox-add-section-1').click();
    const section = page.getByTestId('blox-tree-section').last();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId('blox-add-element-heading').press('Enter');

    let elements = section.getByTestId('blox-tree-element');
    const headingRow = elements.first().locator('[data-element-drag-handle]');
    await page.getByTestId('blox-library-open').click();
    await dragPaletteToTree(
      page,
      page.getByTestId('blox-add-element-text'),
      headingRow,
      0.1,
      'before',
      '插入到此元素之前'
    );
    elements = section.getByTestId('blox-tree-element');
    await expect(elements).toHaveCount(2);
    await expect(elements.first()).toHaveAttribute('data-element-type', 'text');
    await expect(elements.nth(1)).toHaveAttribute('data-element-type', 'heading');

    const columnHeading = section.getByTestId('blox-tree-column').locator(':scope > div').first();
    await columnHeading.click();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId('blox-add-element-container').press('Enter');
    const container = section.locator('[data-sort-el-item][data-element-type="container"]');
    const containerRow = container.locator('[data-element-drag-handle]');
    await page.getByTestId('blox-library-open').click();
    await dragPaletteToTree(
      page,
      page.getByTestId('blox-add-element-heading'),
      containerRow,
      0.5,
      'inside',
      '放入此容器'
    );
    await expect(container.locator('[data-sort-child-item]')).toHaveCount(1);
    await expect(container.locator('[data-sort-child-item]')).toHaveAttribute('data-element-type', 'heading');

    await columnHeading.click();
    await page.getByTestId('blox-library-open').click();
    await dragPaletteToTree(
      page,
      page.getByTestId('blox-add-element-container').first(),
      containerRow,
      0.5,
      'inside',
      '容器内不能再放容器',
      '0'
    );
    await expect(container.locator('[data-sort-child-item]')).toHaveCount(1);
  } finally {
    if (await editorHasChanges(page)) await restoreClean(page);
  }
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expectClean(page);
});
// ── 面包屑（r14）：选择模型的第二视图，点父级两击内回到区块 ──
test('breadcrumb reflects selection and climbs to parent @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page);
  // 选中新加的标题元素 → 面包屑 = 区块 > 列 > 元素（3 项，末项高亮）
  await section.getByTestId('blox-tree-element').first().click();
  const crumb = page.getByTestId('blox-breadcrumb');
  await expect(crumb).toBeVisible();
  await expect(crumb.locator('button')).toHaveCount(3);
  // 点第一项 → 回到区块层（面包屑收敛为 1 项），选择仍在该区块
  await crumb.locator('button').first().click();
  await expect(crumb.locator('button')).toHaveCount(1);
  // 清理：临时标题=加区块+加元素两个历史项，undo 两次回 clean
  await undo(page);
  await undo(page);
  await expectClean(page);
});

// ── 点空白取消选择（用户报告的回归，2026-08-08）──
test('clicking blank canvas deselects tree selection @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  // 结构树选中首个区块 → 左栏出现「取消选中（改为插入到末尾）」提示按钮
  await page.getByTestId('blox-tree-section').first().click();
  await expect(page.getByTestId('blox-clear-selection')).toBeVisible();

  // 点画布内空白 → 选择清除。首页画布内容从 y=0 铺满（真实空白只在内容间隙/尾部），
  // 坐标点击会命中 section——用合成 body 点击验证「未命中 yk 目标 → ykClear」链路。
  const contentFrame = await frame(page);
  await contentFrame.locator('body').evaluate((body) => body.click());
  await expect(page.getByTestId('blox-clear-selection')).toBeHidden();

  // 再选中一次，点画布宿主空白（iframe 外灰底）→ 同样清除
  await page.getByTestId('blox-tree-section').first().click();
  await expect(page.getByTestId('blox-clear-selection')).toBeVisible();
  // y 避开顶部面包屑条（r14 起有选择时显示在 host 顶部），点画布左侧灰底空白
  await page.getByTestId('blox-canvas-host').click({ position: { x: 4, y: 100 } });
  await expect(page.getByTestId('blox-clear-selection')).toBeHidden();
});

// ── Bootstrap Icons：双图库选择、搜索、预览资源加载 ──
test('Bootstrap icon picker selects and renders without reload @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const { sectionIndex } = await addTemporaryHeading(page);

  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-icon').press('Enter');
  await expect(page.getByTestId('blox-icon-value')).toBeVisible();
  await page.getByTestId('blox-icon-library-toggle').click();
  await page.getByTestId('blox-icon-provider-bootstrap').click();
  await page.getByTestId('blox-icon-search').fill('house-door');
  await expect(page.getByTestId('blox-icon-option-bi-house-door')).toBeVisible();

  await performPreviewUpdate(page, () => page.getByTestId('blox-icon-option-bi-house-door').click());
  await expect(page.getByTestId('blox-icon-value')).toHaveValue('bi:house-door');
  const contentFrame = await frame(page);
  const icon = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.1"] i.bi.bi-house-door`);
  await expect(icon).toHaveCount(1);
  const fontFamily = await icon.evaluate((element) => getComputedStyle(element, '::before').fontFamily);
  expect(fontFamily.toLowerCase()).toContain('bootstrap-icons');
  expect(page.url()).toBe(originalURL);

  await restoreClean(page);
});

test('business icon preset applies semantic icon and hover motion @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { sectionIndex } = await addTemporaryHeading(page);

  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-icon-box').press('Enter');
  await page.getByTestId('blox-icon-library-toggle').click();

  const businessPresets = page.getByTestId('blox-business-icon-presets');
  await expect(businessPresets).toBeVisible();
  await expect(businessPresets.locator('button')).toHaveCount(12);
  await performPreviewUpdate(page, () => page.getByTestId('blox-business-icon-headset').click());

  await expect(page.getByTestId('blox-icon-value')).toHaveValue('headset');
  await expect(page.getByTestId('blox-control-icon_motion')).toHaveValue('ring');
  const contentFrame = await frame(page);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.1"] i.ti-headset.yk-icon-motion--ring`)).toHaveCount(1);

  await restoreClean(page);
});

test('page title area presets preview safely and inheritance is read only @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  expect(fixtures.channel_any).toBeGreaterThan(0);

  await openPageEditor(page, fixtures.channel_any);
  const contentFrame = await frame(page);
  await pointerClick(page, contentFrame.locator('[data-yk-page-hero-action]'));

  const dialog = page.getByTestId('blox-page-hero-dialog');
  await expect(dialog).toBeVisible();
  await dialog.getByTestId('blox-page-hero-preset-minimal').click();
  await expect(dialog.locator('#blox-page-hero-overlay')).toHaveValue('0');
  await expect(dialog.getByTestId('blox-page-hero-style-preview').locator('.h-20')).toBeVisible();

  await dialog.getByTestId('blox-page-hero-color-picker').click();
  await expect(page.getByTestId('blox-editor-color-picker')).toBeVisible();
  await page.getByTestId('blox-editor-color-clear').click();
  await expect(page.getByTestId('blox-editor-color-picker')).toBeHidden();

  await dialog.getByTestId('blox-page-hero-mode-global').click();
  await expect(dialog.getByTestId('blox-page-hero-preset-standard')).toBeDisabled();
  await expect(dialog.getByTestId('blox-page-hero-color-picker')).toBeDisabled();
  await dialog.getByTestId('blox-page-hero-mode-self').click();
  await expect(dialog.getByTestId('blox-page-hero-preset-standard')).toBeEnabled();
  await dialog.getByTestId('blox-page-hero-preview-mobile').click();
  await expect(dialog.getByTestId('blox-page-hero-preview-mobile')).toHaveAttribute('aria-pressed', 'true');
  const previewFrame = dialog.getByTestId('blox-page-hero-preview-frame');
  await expect.poll(() => previewFrame.evaluate((node) => node.offsetWidth)).toBeLessThanOrEqual(390);
  await dialog.locator('#blox-page-hero-bg').fill('/assets/images/demo/banner-1.svg');
  await dialog.getByTestId('blox-page-hero-focus-x').fill('0');
  await dialog.getByTestId('blox-page-hero-focus-y').fill('100');
  await expect(dialog.getByTestId('blox-page-hero-style-preview').locator('.relative.bg-cover')).toHaveAttribute(
    'style',
    /background-position:\s*0% 100%/
  );

  await dialog.getByRole('button', { name: '取消' }).click();
  await expect(dialog).toBeHidden();
  await expectClean(page);
});
