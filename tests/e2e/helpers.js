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
      if (action !== 'save_draft' && action !== 'get') entries.push('template:' + action);
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
  return (await frame(page)).evaluate(() => window.scrollY || document.documentElement.scrollTop || 0);
}

async function scrollCanvasToBottom(page) {
  // 重试式：滚动后跨过预览防抖+恢复窗口（~300ms）再读——若被在途预览响应的
  // 滚动恢复清零（CI 慢机实证：scrollBefore 读到假 0）则重滚，直到值稳定为正。
  const contentFrame = await frame(page);
  await expect.poll(async () => {
    await contentFrame.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await page.waitForTimeout(350);
    return canvasScrollTop(page);
  }, { timeout: 10000 }).toBeGreaterThan(0);
}

async function clearSelection(page) {
  const button = page.getByTestId('blox-clear-selection');
  if (await button.isVisible()) await button.click();
}

async function addTemporaryHeading(page, columns = 1) {
  await clearSelection(page);
  const before = await countSections(page);
  await page.getByTestId(`blox-add-section-${columns}`).click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  const section = page.getByTestId('blox-tree-section').last();
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-heading').click();
  await expect(section.getByTestId('blox-tree-element')).toHaveCount(1);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]')).toHaveCount(1);
  return { before, section, sectionIndex: before };
}

async function performPreviewUpdate(page, action) {
  const response = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    return candidate.request().method() === 'POST'
      && url.pathname === '/admin/page_edit_advance.php'
      && url.searchParams.get('home') === '1';
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
  for (let i = 0; i < 20 && await page.getByTestId('blox-dirty').isVisible(); i += 1) {
    if (!await page.getByTestId('blox-undo').isEnabled()) break;
    await page.getByTestId('blox-undo').click();
    await page.waitForTimeout(60);
  }
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
}

async function dragElement(source, target, page) {
  const sourceBox = await source.locator('[data-element-drag-handle]').boundingBox();
  const targetBox = await target.boundingBox();
  if (!sourceBox || !targetBox) throw new Error('Sortable drag target is not visible');
  await page.mouse.move(sourceBox.x + sourceBox.width / 2, sourceBox.y + sourceBox.height / 2);
  await page.mouse.down();
  await page.mouse.move(targetBox.x + targetBox.width / 2, targetBox.y + Math.min(targetBox.height - 4, 26), { steps: 12 });
  await page.waitForTimeout(150);
  await page.mouse.up();
}

module.exports = {
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
};
