const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, observeConsole } = require('./helpers');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const app = (page, fn) => page.evaluate(body => new Function('a', `return (${body})(a)`)(window.Alpine.$data(document.body)), fn.toString());

test('page layout overrides publish independently and restore live inheritance @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'serialized publication baseline');
  const errors = observeConsole(page);
  await page.goto('/admin/theme.php?tab=settings');
  const form = page.getByTestId('theme-settings-panel').locator('form');
  const initial = await form.evaluate(el => new URLSearchParams(new FormData(el)).toString());
  const settings = new URLSearchParams(initial);
  settings.set('theme_style[general][page_header_hidden]', '1');
  settings.set('theme_style[general][page_content_gutter]', '24');
  await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(settings) });
  const publish = async () => {
    await page.getByTestId('blox-save').click();
    await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|clean|published/);
    page.once('dialog', dialog => dialog.accept());
    await page.getByTestId('blox-publish-page').click();
    await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/);
  };
  const front = await page.context().newPage();
  try {
    await openPageEditor(page, fixtures.blox_page);
    await page.getByTestId('blox-page-frame-open').click();
    await page.getByTestId('layout-inherit-header').click();
    await expect(page.getByTestId('blox-page-frame-header')).not.toBeChecked();
    await page.getByTestId('blox-page-frame-header').check();
    await page.getByTestId('layout-mode-page_content_gutter').selectOption('set');
    await page.getByTestId('layout-value-page_content_gutter').fill('0');
    await page.getByTestId('layout-mode-page_content_max_width').selectOption('set');
    await page.getByTestId('layout-value-page_content_max_width').fill('960');
    await page.getByTestId('layout-mode-page_content_background').selectOption('clear');
    await page.screenshot({ path: info.outputPath('layout-desktop.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(page.getByTestId('blox-page-frame-apply')).toBeVisible();
    await page.screenshot({ path: info.outputPath('layout-mobile.png') });
    await page.getByTestId('blox-page-frame-apply').click();
    await page.setViewportSize({ width: 1440, height: 900 });
    expect(await app(page, a => a.docSettings.page_header_hidden)).toBe(false);
    expect(await app(page, a => a.docSettings.page_content_gutter)).toBe(0);
    await publish();
    await front.goto(fixtures.blox_page_url);
    const css = await front.locator('main').evaluate(el => ({ padding: getComputedStyle(el).paddingLeft, width: getComputedStyle(el).maxWidth, bg: getComputedStyle(el).backgroundColor }));
    expect(css).toEqual({ padding: '0px', width: '960px', bg: 'rgba(0, 0, 0, 0)' });
    await expect(front.locator('body > header, body .yk-blox-header').first()).toBeVisible();
    await front.goto('/');
    await expect(front.locator('[data-yk-page-layout]')).toHaveCount(0);
    await page.getByTestId('blox-page-frame-open').click();
    await page.getByTestId('layout-inherit-header').click();
    for (const key of ['page_content_gutter', 'page_content_max_width', 'page_content_background']) await page.getByTestId('layout-mode-' + key).selectOption('inherit');
    await page.getByTestId('blox-page-frame-apply').click();
    expect(await app(page, a => Object.prototype.hasOwnProperty.call(a.docSettings, 'page_header_hidden'))).toBe(false);
    await publish();
    await front.goto(fixtures.blox_page_url);
    expect(await front.locator('main').evaluate(el => getComputedStyle(el).paddingLeft)).toBe('24px');
    await expect(front.locator('body > header, body .yk-blox-header')).toHaveCount(0);
    expect(errors.filter(e => /pageerror|Alpine Expression Error/.test(e))).toEqual([]);
  } finally {
    await page.request.post('/admin/theme.php?tab=settings', { form: Object.fromEntries(new URLSearchParams(initial)) });
    await front.close();
  }
});
