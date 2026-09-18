const { test, expect } = require('@playwright/test');
const {
  observeConsole, openEditor, addTemporaryHeading, frame, performPreviewUpdate, restoreClean,
  countSections, openSectionInsertAtEnd,
} = require('./helpers');

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

  const previewOpened = page.waitForEvent('popup');
  await page.getByTestId('blox-design-page-theme-preview').click();
  const preview = await previewOpened;
  try {
    await preview.waitForLoadState('domcontentloaded');
    await expect(preview.locator('#yk-blox-design-theme')).toHaveCount(1);
    expect(await preview.locator('#yk-blox-design-theme').textContent()).toContain('--yk-layout-max-width:1200px');
    await expect(preview.locator('div.yk-width-theme').first()).toHaveCSS('max-width', '1200px');
    expect(await frontTheme(), 'previewing must not publish the draft').toBe(initialFront);
    const anonymous = await page.context().browser().newContext({ storageState: { cookies: [], origins: [] } });
    try {
      const denied = await anonymous.request.get(preview.url(), { maxRedirects: 0 });
      expect([302, 401, 403]).toContain(denied.status());
      expect(await denied.text()).not.toContain('id="yk-blox-design-theme"');
    } finally { await anonymous.close(); }
  } finally { await preview.close(); }

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
    await editor.getByTestId('blox-tree-element').last().click();
    await editor.getByTestId('blox-style-tab').click();
    const localSize = editor.getByTestId('blox-control-type_font_size').locator('input');
    const title = section.locator('h2.yk-type-h2');
    await expect(title).toHaveCSS('font-size', '36px');
    await editor.getByTestId('blox-device-mobile').click();
    await performPreviewUpdate(editor, () => localSize.fill('23'));
    await expect(title).toHaveCSS('font-size', '23px');
    await editor.getByTestId('blox-device-desktop').click();
    await expect(title).toHaveCSS('font-size', '36px');
    await editor.getByTestId('blox-device-mobile').click();
    await performPreviewUpdate(editor, () => localSize.fill(''));
    await expect(title).toHaveCSS('font-size', '28px');
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

// G2：按钮三变体预设的状态链——设计页三态配置 → 发布输出固定规则 → 画布消费 + 键盘焦点环；
// 局部显式颜色完整回退局部路径。焦点用真实 Tab 键盘序列验证，不用 hover 模拟。
test('button variant presets publish state rules and consume on canvas @ci', async ({ page }, testInfo) => {
  test.setTimeout(90000);
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design management interaction baseline');
  const consoleEntries = observeConsole(page);
  const frontTheme = async () => {
    const response = await page.request.get('/?e2e_theme=' + Date.now());
    const html = await response.text();
    const match = html.match(/<style id="yk-blox-design-theme">([\s\S]*?)<\/style>/);
    return match ? match[1] : '';
  };

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-theme').click();
  await expect(page.getByTestId('blox-design-theme-variants')).toBeVisible();
  const initialFront = await frontTheme();

  await page.getByTestId('blox-theme-v-filled-base-bg').selectOption('primary');
  await page.getByTestId('blox-theme-v-filled-hover-bg').selectOption('secondary');
  await page.getByTestId('blox-theme-v-filled-focus').selectOption('secondary');
  await page.getByTestId('blox-theme-v-outline-base-color').selectOption('text');
  await page.getByTestId('blox-design-page-theme-publish').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);

  const css = await frontTheme();
  expect(css).toContain('a.yk-btn-v-filled{background-color:var(--yk-color-primary);}');
  expect(css).toContain('a.yk-btn-v-filled.yk-btn-v-hover:hover{background-color:var(--yk-color-secondary);}');
  expect(css).toContain('a.yk-btn-v-filled:focus-visible{outline:2px solid var(--yk-color-secondary);outline-offset:2px}');
  expect(css).toContain('a.yk-btn-v-outline{color:var(--yk-color-text);}');

  // Use a real front-end link: editor overlays intentionally intercept pointer interaction.
  const front = await page.context().newPage();
  try {
    await front.goto('/?e2e_hover=' + Date.now());
    const link = front.locator('a.yk-btn-v-filled.yk-btn-v-hover').first();
    await expect(link).toBeVisible();
    const beforeHover = await link.evaluate(el => getComputedStyle(el).backgroundColor);
    await link.hover();
    await expect.poll(() => link.evaluate(el => getComputedStyle(el).backgroundColor)).not.toBe(beforeHover);
    await front.mouse.move(0, 0);
    await expect(link).toHaveCSS('background-color', beforeHover);
  } finally { await front.close(); }

  // 刷新后表单从服务端回读同一份预设。
  await page.reload({ waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-theme').click();
  await expect(page.getByTestId('blox-theme-v-filled-base-bg')).toHaveValue('primary');
  await expect(page.getByTestId('blox-theme-v-filled-hover-bg')).toHaveValue('secondary');
  await expect(page.getByTestId('blox-theme-v-outline-base-color')).toHaveValue('text');

  const editor = await page.context().newPage();
  try {
    await openEditor(editor);
    const before = await countSections(editor);
    await openSectionInsertAtEnd(editor);
    await editor.getByTestId('blox-add-section-1').click();
    await expect(editor.getByTestId('blox-tree-section')).toHaveCount(before + 1);
    const section = editor.getByTestId('blox-tree-section').last();
    await editor.getByTestId('blox-library-open').click();
    await editor.getByTestId('blox-add-element-button').press('Enter');
    await expect(section.getByTestId('blox-tree-element')).toHaveCount(1);

    const canvas = await frame(editor);
    // 新增按钮未设 URL（href="#"），与站点既有主按钮（真实链接）区分开。
    const themedButton = canvas.locator('a.yk-btn-v-filled[href="#"]').last();
    await expect(themedButton).toHaveCount(1);
    // Partial presets preserve the base variant; configured properties override its utilities.
    await expect(themedButton).toHaveClass(/bg-primary/);
    await expect(themedButton).toHaveCSS('color', 'rgb(255, 255, 255)');
    await expect(themedButton).toHaveClass(/yk-btn-v-hover/);

    // 真实键盘模态聚焦（不用 hover 冒充）：先按 Tab 确立键盘交互模态，再聚焦目标按钮，
    // :focus-visible 命中主题焦点环规则（画布 iframe 有 zoom 缩放，宽度按缩放后下限断言）。
    await editor.keyboard.press('Tab');
    const themedHandle = await themedButton.elementHandle();
    await canvas.evaluate((el) => el.focus(), themedHandle);
    await expect(themedButton).toBeFocused();
    await expect(themedButton).toHaveCSS('outline-style', 'solid');
    expect(await themedButton.evaluate((el) => parseFloat(getComputedStyle(el).outlineWidth)))
      .toBeGreaterThanOrEqual(1.4);

    // 局部显式颜色 → 完整局部路径：被编辑按钮退回 bg-primary 原类；站点其它主题按钮不受影响。
    await section.getByTestId('blox-tree-element').last().click();
    await editor.getByTestId('blox-style-tab').click();
    await performPreviewUpdate(editor, async () => {
      await editor.getByTestId('blox-color-picker-trigger').first().click();
      await expect(editor.getByTestId('blox-editor-color-picker')).toBeVisible();
      await editor.getByTestId('blox-editor-color-token-primary').click();
    });
    await editor.keyboard.press('Escape');
    await expect(editor.getByTestId('blox-editor-color-picker')).toBeHidden();
    const localButton = canvas.locator('a.inline-flex.bg-primary[href="#"]').last();
    await expect(localButton).toHaveCount(1);
    await expect(localButton).toHaveAttribute('style', /color:var\(--yk-color-primary\)/);
    await restoreClean(editor);
  } finally {
    await editor.close();
  }

  // 清理：预设全部清空后发布，前台恢复无变体输出。
  await page.getByTestId('blox-theme-v-filled-base-bg').selectOption('');
  await page.getByTestId('blox-theme-v-filled-hover-bg').selectOption('');
  await page.getByTestId('blox-theme-v-filled-focus').selectOption('');
  await page.getByTestId('blox-theme-v-outline-base-color').selectOption('');
  await page.getByTestId('blox-design-page-theme-publish').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
  expect(await frontTheme()).toBe(initialFront);

  expect(consoleEntries, 'button variant management must keep the console clean').toEqual([]);
});

test('partial site typography survives draft reload and only affects narrow viewports @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design settings baseline');
  // The seeded homepage uses explicit visual sizes. Add an automatic-size heading in this isolated site's draft.
  await openEditor(page);
  await addTemporaryHeading(page);
  const saved = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_home_api.php'
    && r.request().method() === 'POST');
  await page.getByTestId('blox-save').click();
  expect((await (await saved).json()).code).toBe(0);
  await page.goto('/admin/blox_design.php');
  await page.getByTestId('blox-design-page-tab-theme').click();
  await page.getByTestId('blox-design-page-theme-role-h2').click();
  await expect(page.getByTestId('blox-design-page-theme-size')).toHaveValue('');
  await page.getByTestId('blox-design-page-theme-device-t').click();
  await page.getByTestId('blox-design-page-theme-size').fill('31');
  await page.getByTestId('blox-design-page-theme-device-m').click();
  await page.getByTestId('blox-design-page-theme-size').fill('23');
  await page.getByTestId('blox-design-page-theme-save').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-amber-50/);
  await page.reload();
  await page.getByTestId('blox-design-page-tab-theme').click();
  await page.getByTestId('blox-design-page-theme-role-h2').click();
  await expect(page.getByTestId('blox-design-page-theme-size')).toHaveValue('');
  await page.getByTestId('blox-design-page-theme-device-m').click();
  await expect(page.getByTestId('blox-design-page-theme-size')).toHaveValue('23');
  const popup = page.waitForEvent('popup');
  await page.getByTestId('blox-design-page-theme-preview').click();
  const preview = await popup;
  try {
    await preview.waitForLoadState('domcontentloaded');
    const heading = preview.locator('h2.yk-type-h2').first();
    const desktop = await heading.evaluate(el => getComputedStyle(el).fontSize);
    for (const [width, size] of [[390, '23px'], [767, '23px'], [768, '31px'], [1023, '31px'], [1024, desktop], [1440, desktop]]) {
      await preview.setViewportSize({ width, height: 900 });
      await expect(heading).toHaveCSS('font-size', size);
    }
    await preview.setViewportSize({ width: 390, height: 844 });
    await preview.screenshot({ path: testInfo.outputPath('partial-typography-mobile.png') });
  } finally { await preview.close(); }
  await page.getByTestId('blox-design-page-theme-size').fill('');
  await page.getByTestId('blox-design-page-theme-device-t').click();
  await page.getByTestId('blox-design-page-theme-size').fill('');
  await page.getByTestId('blox-design-page-theme-save').click();
  await expect(page.getByTestId('blox-design-page-theme-status')).toHaveClass(/bg-emerald-50/);
});
