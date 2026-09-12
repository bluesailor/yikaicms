const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled, observeUnsafeWrites } = require('./helpers');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'site-design-state-fixture.php'), action],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

for (const area of ['header', 'footer']) {
  test(`${area} preview links keep page channel and language context @ci`, async ({ page }) => {
    test.setTimeout(90000);
    const state = JSON.parse(fixture('seed'));
    const writes = observeUnsafeWrites(page);
    try {
      await page.goto('/admin/site_design.php');
      const options = await page.getByTestId('site-design-context').locator('option').evaluateAll(nodes =>
        nodes.map(node => ({ key: node.value, label: node.textContent })));
      for (const lang of ['zh-CN', 'en', 'ja']) {
        for (const kind of ['page', 'channel']) {
          const target = options.find(option => option.key.startsWith(`${kind}:`) && option.label.includes(`[${lang}]`));
          expect(target, `${kind} fixture for ${lang}`).toBeTruthy();
          await page.goto(`/admin/site_design.php?context=${encodeURIComponent(target.key)}`);
          const expected = new URL(await page.getByTestId('site-design-context-preview').getAttribute('href'), page.url());
          expected.searchParams.set('preview', '');
          // Some section roots intentionally redirect to their first child page.
          const destination = await page.request.get(expected.href);
          expect(destination.ok()).toBe(true);
          await page.goto(`/admin/blox_editor.php?template=${state[`${area}_active`]}&area_lang=${lang}&preview_context=${encodeURIComponent(target.key)}`);
          await waitPreviewSettled(page);
          for (const id of ['blox-front-preview', 'blox-mobile-front-preview']) {
            const actual = new URL(await page.getByTestId(id).getAttribute('href'), page.url());
            expect(actual.pathname).toBe(expected.pathname);
            expect([...actual.searchParams]).toEqual([...expected.searchParams]);
          }
          expect(await page.evaluate(() => window.Alpine.$data(document.body).previewContext)).toBe(target.key);
          const mobile = await page.getByTestId('blox-mobile-actions-open').isVisible();
          if (mobile) await page.getByTestId('blox-mobile-actions-open').click();
          const popupPromise = page.waitForEvent('popup');
          await page.getByTestId(mobile ? 'blox-mobile-front-preview' : 'blox-front-preview').click();
          const popup = await popupPromise;
          try {
            await popup.waitForLoadState('domcontentloaded');
            expect(new URL(popup.url()).pathname).toBe(new URL(destination.url()).pathname);
            await expect(popup.locator('body')).not.toBeEmpty();
          } finally { await popup.close(); }
        }
      }
      for (const context of ['page:999999', 'https://example.com/', ['invalid']]) {
        const query = new URLSearchParams({ template: String(state[`${area}_active`]), area_lang: 'en' });
        query.set(Array.isArray(context) ? 'preview_context[]' : 'preview_context', String(context));
        await page.goto(`/admin/blox_editor.php?${query}`);
        await waitPreviewSettled(page);
        for (const id of ['blox-front-preview', 'blox-mobile-front-preview']) {
          await expect(page.getByTestId(id)).toHaveAttribute('href', /^\/en\/?\?preview$/);
        }
        expect(await page.evaluate(() => window.Alpine.$data(document.body).previewContext)).toBe('home');
      }
      expect(writes).toEqual([]);
    } finally { fixture('restore'); }
  });
}
