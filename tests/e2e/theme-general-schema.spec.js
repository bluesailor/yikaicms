const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

// Write tests run only against the disposable local smoke installation prepared by run-local.js.
test('Default layout schema validates writes, preserves dependencies and changes real frontend CSS @ci', async ({ page }) => {
  test.skip(test.info().project.name !== 'desktop-1440', 'one serialized settings-write baseline');
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/theme.php?tab=settings');
  const panel = page.getByTestId('theme-settings-panel');
  const schema = page.getByTestId('theme-general-schema');
  await expect(schema).toBeVisible();
  await expect(schema.locator('select,input')).toHaveCount(5);
  const width = page.locator('#theme_general_content_max_width');
  await expect(width).toHaveAttribute('min', '760');
  await expect(width).toHaveAttribute('max', '1920');
  const initial = await panel.locator('form').evaluate(form => new URLSearchParams(new FormData(form)).toString());
  const save = async () => {
    await Promise.all([page.waitForNavigation(), panel.locator('button[type="submit"]').click()]);
  };
  const submit = async body => page.request.post('/admin/theme.php?tab=settings', {
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: body.toString(),
  });
  try {
    await page.locator('#theme_general_site_layout').selectOption('boxed');
    await width.fill('1080');
    await page.locator('#theme_general_site_background').evaluate(el => {
      el.value = '#abcdef'; el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await save();
    await expect(width).toHaveValue('1080');
    await expect(page.locator('#theme_general_site_background')).toHaveValue('#abcdef');
    const saved = await panel.locator('form').evaluate(form => new URLSearchParams(new FormData(form)).toString());

    const front = await page.context().newPage();
    try {
      await front.goto('/?theme-schema-check=1');
      await expect(front.locator('main').first()).toHaveCSS('max-width', '1080px');
      await expect(front.locator('body')).toHaveCSS('background-color', 'rgb(171, 205, 239)');
      const beforePrimary = await front.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-primary'));
      for (const input of ['1921', '1200px', '1e3']) {
        const bad = new URLSearchParams(saved);
        bad.set('theme_style[general][content_max_width]', input);
        bad.set('primary_color', '#FF0000');
        const response = await submit(bad);
        expect(response.status()).toBe(200);
        expect(await response.text()).toContain('设置未保存');
      }
      const unknown = new URLSearchParams(saved);
      unknown.set('theme_style[general][callback]', 'system');
      expect(await (await submit(unknown)).text()).toContain('不支持的布局字段');
      const malformed = new URLSearchParams(saved);
      for (const key of [...malformed.keys()]) if (key.startsWith('theme_style[')) malformed.delete(key);
      malformed.set('theme_style', 'invalid');
      const malformedResponse = await submit(malformed);
      expect(malformedResponse.status()).toBe(200);
      expect(await malformedResponse.text()).toContain('设置未保存');
      const mismatch = new URLSearchParams(saved);
      mismatch.set('theme_settings_target', 'business');
      expect(await (await submit(mismatch)).text()).toContain('当前主题已变化');
      const csrf = new URLSearchParams(saved);
      csrf.delete('_token');
      csrf.set('theme_style[general][content_max_width]', '1400');
      await submit(csrf);
      await front.reload();
      await expect(front.locator('main').first()).toHaveCSS('max-width', '1080px');
      expect(await front.evaluate(() => getComputedStyle(document.documentElement).getPropertyValue('--color-primary'))).toBe(beforePrimary);
    } finally { await front.close(); }

    await page.locator('#theme_general_color_mode').selectOption('dark');
    await expect(page.locator('#theme_general_site_background')).toBeHidden();
    await expect(page.locator('#theme_general_site_background')).toBeDisabled();
    await expect(page.getByTestId('theme-general-dependency')).toBeVisible();
    await save();
    await page.locator('#theme_general_color_mode').selectOption('light');
    await expect(page.locator('#theme_general_site_background')).toHaveValue('#abcdef');
    await save();
    await schema.screenshot({ path: test.info().outputPath('theme-general-schema.png') });
    await page.setViewportSize({ width: 390, height: 844 });
    await expect(schema).toBeVisible();
    const box = await schema.boundingBox();
    expect(box.x + box.width).toBeLessThanOrEqual(390);
    await schema.screenshot({ path: test.info().outputPath('theme-general-schema-mobile.png') });
    expect(consoleEntries).toEqual([]);
  } finally {
    // Restore visible settings for subsequent specs; setup.php --restore restores the original database too.
    await submit(new URLSearchParams(initial));
  }
});
