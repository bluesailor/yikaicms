const { test, expect } = require('@playwright/test');
const { openEditor, addTemporaryHeading, frame, restoreClean } = require('./helpers');

const mark = (text, variant = 'circle') => `
  <span class="yk-annotated-mark yk-annotated-mark--${variant}" style="--yk-annotated-color:#6366f1"
        data-yk-annotated data-annotation-animate="1" data-annotation-trigger="viewport" data-annotation-speed="normal">
    <span class="yk-annotated-mark__text">${text}</span>
    <svg class="yk-annotated-mark__svg" viewBox="0 0 100 40" preserveAspectRatio="none" aria-hidden="true" focusable="false">
      <path class="yk-annotated-mark__stroke" d="M51 5 C75 3 97 8 98 19 C101 32 79 37 49 36 C20 36 2 30 2 20 C1 10 23 5 51 5 Z" pathLength="100"></path>
    </svg>
  </span>`;

async function openFixture(page, withScript = true) {
  await page.route('**/__blox-annotated-text', route => route.fulfill({
    contentType: 'text/html',
    body: `<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
      <link rel="stylesheet" href="/assets/css/blox-annotated-text.css">
      <style>body{margin:0;font:24px/1.5 system-ui,sans-serif;color:#1e293b;background:#f8fafc}
      main{max-width:840px;margin:auto;padding:36px 20px}h2{font-size:clamp(28px,5vw,48px);line-height:1.3}
      .spacer{height:1100px}</style></head><body><main>
      <h2 class="yk-annotated-text">让${mark('每一个想法')}被看见</h2>
      <p>正文不依赖 SVG 朗读，也能正常选中与复制。</p>
      <div class="spacer"></div><h2>再一次${mark('描画短语')}</h2>
      </main>${withScript ? '<script src="/assets/js/blox-annotated-text.js"></script>' : ''}</body></html>`,
  }));
  await page.goto('/__blox-annotated-text');
}

test('annotated text stays readable and within a narrow viewport @ci', async ({ page }, testInfo) => {
  await openFixture(page);
  const first = page.locator('[data-yk-annotated]').first();
  await expect(first).toContainText('每一个想法');
  const bounds = await first.boundingBox();
  expect(bounds.x).toBeGreaterThanOrEqual(0);
  expect(bounds.x + bounds.width).toBeLessThanOrEqual(testInfo.project.use.viewport.width + 1);
  expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBeLessThanOrEqual(testInfo.project.use.viewport.width);
  await page.waitForTimeout(800);
  await page.screenshot({ path: testInfo.outputPath('annotated-text.png') });
});

test('offscreen annotation draws once when it enters view @ci', async ({ page }) => {
  await openFixture(page);
  const second = page.locator('[data-yk-annotated]').last();
  await expect(second).toHaveClass(/is-annotation-pending/);
  expect(await second.locator('path').evaluate(node => node.style.strokeDashoffset)).toBe('100');
  await second.scrollIntoViewIfNeeded();
  await expect.poll(() => second.locator('path').evaluate(node => node.style.strokeDashoffset)).toBe('0');
  await expect(second).toHaveClass(/is-annotation-drawing/);
});

test('reduced motion and no-script keep a complete static mark @ci', async ({ page }) => {
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await openFixture(page);
  const first = page.locator('[data-yk-annotated]').first();
  await expect(first).not.toHaveClass(/is-annotation-pending/);
  expect(await first.locator('path').evaluate(node => node.style.strokeDashoffset)).toBe('');
  await openFixture(page, false);
  expect(await page.locator('[data-yk-annotated]').first().locator('path').evaluate(node => node.style.strokeDashoffset)).toBe('');
});

test('editor exposes annotated text and its content and style controls @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Editor palette is checked once on desktop');
  await openEditor(page);
  const tile = page.getByTestId('blox-add-element-annotated-text');
  await expect(tile).toBeAttached();
  const schema = await page.evaluate(() => {
    const app = window.Alpine && window.Alpine.$data(document.body);
    return app && app.elSchema('annotated-text');
  });
  expect(schema.label).toBeTruthy();
  expect(schema.controls.map(control => control.key)).toEqual(expect.arrayContaining([
    'text', 'mark_text', 'level', 'variant', 'mark_color', 'animate', 'draw_trigger', 'draw_speed',
  ]));
  await addTemporaryHeading(page);
  const preview = await frame(page);
  const before = await preview.locator('[data-yk-el-type="annotated-text"]').count();
  await page.getByTestId('blox-library-open').click();
  await tile.press('Enter');
  await expect(preview.locator('[data-yk-el-type="annotated-text"]')).toHaveCount(before + 1);
  await expect(preview.locator('[data-yk-annotated]').last()).toContainText('想法');
  await restoreClean(page);
});
