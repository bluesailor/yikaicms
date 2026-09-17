const { expect } = require('@playwright/test');

function observeConsole(page) {
  const entries = [];
  page.on('console', (message) => {
    if (message.type() === 'error' || message.type() === 'warning') {
      entries.push(`${message.type()}: ${message.text()}`);
    }
  });
  page.on('pageerror', (error) => entries.push(`pageerror: ${error.message}`));
  return entries;
}

function observeUnsafeWrites(page) {
  // 危险写清单（P1-c，2026-08-07 审计）：不止首页 API——模板 publish 会改前台激活态，
  // 同样属于危险写。save_draft 是模板用例的预期写入（一次性库草稿），显式放行。
  const entries = [];
  page.on('request', (request) => {
    if (request.method() !== 'POST') return;
    const url = new URL(request.url());
    const body = new URLSearchParams(request.postData() || '');
    if (url.pathname === '/admin/blox_home_api.php') {
      const action = body.get('action') || 'save';
      if (action !== 'preview') entries.push('home:' + action);
      return;
    }
    if (url.pathname === '/admin/blox_template_api.php') {
      const action = body.get('action') || '';
      // save_draft 是模板用例的预期写入；get 是读。
      // prepare_insert / confirm_insert 是画布插入的两段式检查（2026-09 新增）：
      // 前者登记一条 TTL 1800s 的临时评审记录，后者认领它并返回 sections，
      // 都不改任何已发布输出或前台激活态，不属于本观察器要拦的危险写。
      const safe = ['save_draft', 'get', 'prepare_insert', 'confirm_insert'];
      if (!safe.includes(action)) entries.push('template:' + action);
    }
  });
  return entries;
}

async function openEditor(page) {
  await page.goto('/admin/blox_editor.php?home=1', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.getByTestId('blox-tree')).toBeAttached();
  await page.waitForFunction(() => {
    const frame = document.querySelector('[data-testid="blox-canvas"]');
    return frame && frame.contentDocument && frame.contentDocument.readyState === 'complete'
      && frame.contentDocument.querySelectorAll('[data-yk-sec]').length > 0;
  });
  await expect(page.getByTestId('blox-save')).toBeAttached();
  await expect(page.getByTestId('blox-publish')).toBeAttached();
  await expect(page.getByTestId('blox-rollback')).toBeAttached();
}

async function openPageEditor(page, pageId) {
  await page.goto(`/admin/blox_editor.php?id=${encodeURIComponent(pageId)}`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.getByTestId('blox-tree')).toBeAttached();
  await page.waitForFunction(() => {
    const canvas = document.querySelector('[data-testid="blox-canvas"]');
    return canvas && canvas.contentDocument && canvas.contentDocument.readyState === 'complete';
  });
  await expect(page.getByTestId('blox-save')).toBeAttached();
  await expect(page.getByTestId('blox-publish-page')).toBeAttached();
}

async function frame(page) {
  const handle = await page.getByTestId('blox-canvas').elementHandle();
  const contentFrame = handle && await handle.contentFrame();
  if (!contentFrame) throw new Error('Blox preview iframe is unavailable');
  return contentFrame;
}

async function countSections(page) {
  return page.getByTestId('blox-tree-section').count();
}

async function countCanvasSections(page) {
  return (await frame(page)).locator('[data-yk-sec]').count();
}

async function countDynamicHomeBlocks(page) {
  return (await frame(page)).locator('[data-yk-home]').count();
}

async function canvasScrollTop(page) {
  await waitPreviewSettled(page);
  return (await frame(page)).evaluate(() => window.scrollY || document.documentElement.scrollTop || 0);
}

// Read the existing client state: absence of a settle event is not an idle signal.
async function waitPreviewSettled(page, timeoutMs = 5000) {
  await expect.poll(() => page.evaluate(() => {
    if (!window.Alpine || !document.body._x_dataStack) return false;
    const app = window.Alpine.$data(document.body);
    const client = app._previewClient;
    const canvas = document.querySelector('[data-testid="blox-canvas"]');
    return !app.previewLoading && (!client || (!client.timer && !client.controller && !client.rebuilding))
      && canvas?.contentDocument?.readyState === 'complete';
  }), { timeout: timeoutMs }).toBe(true);
}

async function scrollCanvasToBottom(page) {
  // 两代方案融合（r16 重试式 + r17 settled 等待；CI 第 6 轮实证单用任一都会被
  // 慢机的在途恢复清零击穿）：先等 settle，滚动后跨过恢复窗口读值，被清零则
  // 重滚，直到稳定为正。
  const contentFrame = await frame(page);
  await waitPreviewSettled(page);
  await expect.poll(async () => {
    await contentFrame.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(400);
    return canvasScrollTop(page);
  }, { timeout: 15000 }).toBeGreaterThan(0);
}

async function clearSelection(page) {
  const button = page.getByTestId('blox-clear-selection');
  if (await button.isVisible()) await button.click();
}

async function openSectionInsertAtEnd(page) {
  const insert = page.getByTestId('blox-section-insert-after').last();
  if (await insert.count()) {
    if (!await insert.isVisible()) {
      const mobile = page.getByTestId('blox-mobile-structure');
      if (await mobile.isVisible()) await mobile.click();
      else await page.getByTestId('blox-toolbar-structure-toggle').click();
    }
    await insert.click();
    return;
  }
  const canvasView = page.getByTestId('blox-mobile-canvas-view');
  if (await canvasView.isVisible()) await canvasView.click();
  await (await frame(page)).locator('.yk-empty-doc .yk-empty-btn').last().click();
}

async function addTemporaryHeading(page, columns = 1) {
  await clearSelection(page);
  await waitPreviewSettled(page);
  const before = await countSections(page);
  const headingBefore = await (await frame(page)).locator('[data-yk-el-type="heading"]').count();
  const mobile = await page.getByTestId('blox-mobile-structure').isVisible();
  if (mobile) await page.getByTestId('blox-mobile-structure').click();
  await openSectionInsertAtEnd(page);
  await page.getByTestId(`blox-add-section-${columns}`).click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  const section = page.getByTestId('blox-tree-section').last();
  if (mobile) await page.getByTestId('blox-mobile-library').click();
  else await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-heading').press('Enter');
  await expect(section.getByTestId('blox-tree-element')).toHaveCount(1);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]')).toHaveCount(headingBefore + 1);
  return { before, section, sectionIndex: before };
}

async function performPreviewUpdate(page, action) {
  const response = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    return candidate.request().method() === 'POST'
      && url.pathname === '/admin/blox_preview.php'
      && url.searchParams.get('home') === '1';
  });
  await action();
  await response;
  await page.waitForTimeout(80);
}

async function performPagePreviewUpdate(page, action) {
  const response = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return candidate.request().method() === 'POST'
      && url.pathname === '/admin/blox_page_api.php'
      && body.get('action') === 'preview';
  });
  await action();
  await response;
  await page.waitForTimeout(80);
}
async function undo(page) {
  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPreviewUpdate(page, () => page.getByTestId('blox-undo').click());
}

async function restoreClean(page) {
  for (let i = 0; i < 20 && await editorHasChanges(page); i += 1) {
    if (!await page.getByTestId('blox-undo').isEnabled()) break;
    await page.getByTestId('blox-undo').click();
    await page.waitForTimeout(60);
  }
  await expectClean(page);
}

async function editorHasChanges(page) {
  return page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.dirty || app.documentData() !== app._savedDocumentSnapshot;
  });
}

async function expectClean(page) {
  await expect.poll(() => editorHasChanges(page)).toBe(false);
}

async function dragElement(source, target, page) {
  // 结构树是 overflow-y-auto 的滚动容器。目标若在可视区之外，boundingBox() 照样返回
  // 布局坐标，但鼠标事件会落到裁剪区外的元素上（实测落在底部「添加区块」工具条），
  // 拖放静默失效。先把两端滚进视野——先目标后源，保证 mousedown 时源可见。
  const handle = source.locator('[data-element-drag-handle]');
  await target.scrollIntoViewIfNeeded();
  await handle.scrollIntoViewIfNeeded();
  const sourceBox = await handle.boundingBox();
  const targetBox = await target.boundingBox();
  if (!sourceBox || !targetBox) throw new Error('Sortable drag target is not visible');
  await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + Math.min(targetBox.height - 4, 26), { steps: 12 });
  await page.waitForTimeout(150);
  await page.mouse.up();
}

/** 属性面板里当前标题元素的文字框（标题专属面板 heading-content.php，多行；与后台语言无关）。 */
function headingTextField(page) {
  return page.getByTestId('blox-heading-text');
}

module.exports = {
  headingTextField,
  openSectionInsertAtEnd,
  addTemporaryHeading,
  canvasScrollTop,
  waitPreviewSettled,
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
  restoreClean,
  scrollCanvasToBottom,
  undo,
};
