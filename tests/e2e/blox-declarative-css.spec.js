const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop declarative CSS interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = (await page.getByTestId('blox-dirty').getAttribute('data-state').catch(() => null)) === 'dirty';
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'declarative CSS E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

// E05：标题精确字号经声明式 CSS 编译到元素根样式；清空后回到未设置，不残留内联值。
test('heading exact font size reaches the canvas and clears back to unset @ci', async ({ page }) => {
  await addTemporaryHeading(page);
  await page.getByTestId('blox-style-tab').click();
  const input = page.getByTestId('blox-control-type_font_size').locator('input');
  await expect(input).toBeVisible();
  await expect(input).toHaveValue('');

  const heading = async () => (await frame(page)).locator('[data-yk-el-type="heading"] h2').last();
  const documentMarker = await (await frame(page)).evaluate(() => {
    window.__ykDeclarativeCssMarker = Math.random();
    return window.__ykDeclarativeCssMarker;
  });

  let previewRequest;
  await performPreviewUpdate(page, async () => {
    const request = page.waitForRequest((candidate) => {
      const body = new URLSearchParams(candidate.postData() || '');
      return candidate.method() === 'POST'
        && new URL(candidate.url()).pathname === '/admin/blox_preview.php'
        && body.get('action') === 'preview'
        // 响应式控件在桌面槽位写入 {d,t,m}；非响应式为标量。
        && /"type_font_size":(?:40\b|\{"d":40\b)/.test(body.get('blocks_data') || '');
    });
    await input.fill('40');
    previewRequest = await request;
  });
  expect(previewRequest).toBeTruthy();
  await expect(await heading()).toHaveAttribute('style', /font-size:40px;/);

  // 普通样式修改不应重建画布文档。
  expect(await (await frame(page)).evaluate(() => window.__ykDeclarativeCssMarker)).toBe(documentMarker);

  await performPreviewUpdate(page, () => input.fill(''));
  await expect(input).toHaveValue('');
  const style = await (await heading()).getAttribute('style');
  expect(style || '').not.toContain('font-size:');

  await restoreClean(page);
});

test('button exact dimensions affect the clickable link rather than its wrapper @ci', async ({ page }) => {
  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-button').press('Enter');
  await page.getByTestId('blox-style-tab').click();
  const clickable = (await frame(page)).locator('[data-yk-el-type="button"] a').last();
  for (const [key, value, property] of [
    ['btn_padding_x', '0', 'padding-left'],
    ['btn_padding_y', '10', 'padding-top'],
    ['btn_radius', '6', 'border-top-left-radius'],
  ]) {
    const input = page.getByTestId('blox-control-' + key).locator('input');
    await performPreviewUpdate(page, () => input.fill(value));
    await expect(clickable).toHaveCSS(property, value + 'px');
  }
  await restoreClean(page);
});
