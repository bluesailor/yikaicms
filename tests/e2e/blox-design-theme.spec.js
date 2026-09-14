const { test, expect } = require('@playwright/test');
const { observeConsole, openEditor, addTemporaryHeading, frame, performPreviewUpdate, restoreClean } = require('./helpers');

// E04：全站排版/按钮/布局主题走真实草稿与发布接口（隔离站点），前台只在发布后输出主题样式。
test('site theme drafts stay private until published and then reach the front end @ci', async ({ page }, testInfo) => {
  test.setTimeout(90000);
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design management interaction baseline');
  const consoleEntries = observeConsole(page);
  const frontTheme = async () => {
    const response = await page.request.get('/?e2e_theme=' + Date.now());
    const html = await response.text();
    const match = html.match(/<style id="yk-blox-design-theme">([\s\S]*?)<\/style>/);
    return match ? match[1] : '';
  };
  const expectStatus = async (draft) => {
    const data = await page.evaluate(() => {
      const state = window.Alpine.$data(document.querySelector('[data-testid="blox-design-page"]')).themeState;
      return { has_draft: state.has_draft, revision: state.revision, published_revision: state.published_revision };
    });
    expect(data.has_draft).toBe(draft);
    return data;
  };

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-theme').click();
  await expect(page.getByTestId('blox-design-page-theme')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  const initialFront = await frontTheme();

  await page.getByTestId('blox-design-page-theme-role-h2').click();
  await page.getByTestId('blox-design-page-theme-size').fill('36');
  await page.getByTestId('blox-design-page-theme-device-m').click();
  await expect(page.getByTestId('blox-design-page-theme-size')).toHaveAttribute('placeholder', '36');
  await page.getByTestId('blox-design-page-theme-size').fill('28');
  await page.getByTestId('blox-design-page-theme-device-d').click();
  await page.getByTestId('blox-design-page-theme-gap').fill('0');
  await page.getByTestId('blox-design-page-theme-width').fill('1200');
  await page.getByTestId('blox-design-page-theme-spacing').fill('64');
  await page.getByTestId('blox-design-page-theme-device-m').click();
  await expect(page.getByTestId('blox-design-page-theme-spacing')).toHaveAttribute('placeholder', '64');
  await page.getByTestId('blox-design-page-theme-spacing').fill('0');
  await page.getByTestId('blox-design-page-theme-device-d').click();

  await page.getByTestId('blox-design-page-theme-save').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-amber-50/);
  const draft = await expectStatus(true);
  expect(await frontTheme(), 'a saved draft must not change the live site').toBe(initialFront);

  await page.getByTestId('blox-design-page-theme-publish').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
  const published = await expectStatus(false);
  expect(published.revision).toBe(draft.revision + 1);
  expect(published.published_revision).toBe(published.revision);

  const css = await frontTheme();
  expect(css).toContain('--yk-type-h2-size:28px');
  expect(css).toContain('@media (min-width:768px){:root{--yk-type-h2-size:36px;');
  expect(css).toContain('h2.yk-type-h2{font-size:var(--yk-type-h2-size);}');
  // 显式 0 是有效值，不能被当成“未设置”丢掉。
  expect(css).toContain('--yk-layout-gap:0px');
  expect(css).toContain('--yk-layout-max-width:1200px');
  expect(css).toContain('--yk-layout-section-spacing:0px');
  expect(css).toContain('--yk-layout-section-spacing:64px');
  expect(css).not.toContain('yk-type-h1');

  const editor = await page.context().newPage();
  try {
    await openEditor(editor);
    const { sectionIndex } = await addTemporaryHeading(editor);
    await editor.getByTestId('blox-tree-section-label').last().click();
    await editor.getByTestId('blox-style-tab').click();
    await performPreviewUpdate(editor, () => editor.getByTestId('blox-section-padding-global').check());
    const section = (await frame(editor)).locator('[data-yk-sec="' + sectionIndex + '"]');
    await expect(section).toHaveCSS('padding-top', '64px');
    await editor.getByTestId('blox-device-mobile').click();
    await expect(section).toHaveCSS('padding-top', '0px');
    await editor.getByTestId('blox-device-desktop').click();
    await performPreviewUpdate(editor, () => editor.getByTestId('blox-section-padding-global').uncheck());
    await expect(section).toHaveCSS('padding-top', '32px');
    await editor.getByTestId('blox-tree-container').last().click();
    await performPreviewUpdate(editor, () => editor.locator('select[x-model="sel.settings.max_width"]').selectOption('default'));
    await expect(section.locator(':scope > div').first()).toHaveCSS('max-width', '1200px');
    await restoreClean(editor);
  } finally {
    await editor.close();
  }

  // 刷新后表单从服务端回读同一份已发布设置。
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-theme').click();
  await page.getByTestId('blox-design-page-theme-role-h2').click();
  await expect(page.getByTestId('blox-design-page-theme-size')).toHaveValue('36');
  await expect(page.getByTestId('blox-design-page-theme-gap')).toHaveValue('0');
  await expect(page.getByTestId('blox-design-page-theme-width')).toHaveValue('1200');
  await expect(page.getByTestId('blox-design-page-theme-spacing')).toHaveValue('64');
  await page.getByTestId('blox-design-page-theme-layout').screenshot({ path: testInfo.outputPath('theme-layout-desktop.png') });
  await page.setViewportSize({ width: 390, height: 844 });
  await expect.poll(() => page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  await page.getByTestId('blox-design-page-theme-layout').screenshot({ path: testInfo.outputPath('theme-layout-mobile.png') });
  await page.setViewportSize({ width: 1440, height: 900 });

  // 清理：清空后发布，前台恢复为未配置主题的输出。
  await page.getByTestId('blox-design-page-theme-size').fill('');
  await page.getByTestId('blox-design-page-theme-spacing').fill('');
  await page.getByTestId('blox-design-page-theme-width').fill('');
  await page.getByTestId('blox-design-page-theme-device-m').click();
  await page.getByTestId('blox-design-page-theme-size').fill('');
  await page.getByTestId('blox-design-page-theme-spacing').fill('');
  await page.getByTestId('blox-design-page-theme-device-d').click();
  await page.getByTestId('blox-design-page-theme-gap').fill('');
  await page.getByTestId('blox-design-page-theme-publish').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
  expect(await frontTheme()).toBe('');

  expect(consoleEntries, 'site theme management must keep the console clean').toEqual([]);
});
