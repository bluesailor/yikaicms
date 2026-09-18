const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { createBackgroundVideo } = require('./fixtures/background-video');
const { addTemporaryHeading, frame, headingTextField, openPageEditor, performPagePreviewUpdate, expectClean, waitPreviewSettled } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const poster = '/assets/images/demo/yikaicms-industrial-600.webp';
// 遮挡测试用另一张图：与区块海报同一 URL 会被编辑器当作重复背景清掉（首页背景去重）
const obstruction = '/assets/images/demo/stats-architecture.webp';
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-baseline-fixture.php'), action], { cwd: root });

test.beforeAll(() => fixture('cache-pretty'));
test.afterAll(() => fixture('restore'));

async function command(page, action, button) {
  const pending = page.waitForResponse(response => {
    const body = new URLSearchParams(response.request().postData() || '');
    return new URL(response.url()).pathname === '/admin/blox_page_api.php' && body.get('action') === action;
  });
  if (action === 'publish') page.once('dialog', dialog => dialog.accept());
  await page.getByTestId(button).click();
  const response = await pending;
  expect(response.status()).toBe(200);
  expect((await response.json()).code).toBe(0);
  await expectClean(page);
}

async function pixelHash(video) {
  return video.evaluate(node => {
    const canvas = document.createElement('canvas');
    canvas.width = 64;
    canvas.height = 36;
    const context = canvas.getContext('2d');
    context.drawImage(node, 0, 0, 64, 36);
    return context.getImageData(0, 0, 64, 36).data.reduce((hash, byte) => ((hash * 31) + byte) >>> 0, 0);
  });
}

for (const scope of ['section', 'container-element']) {
  for (const source of ['generated', 'mp4']) {
    test(`${scope} video survives save reopen publish and anonymous device fallback ${source === 'generated' ? '@ci' : '@local'}`, async ({ page, browser, baseURL }, info) => {
      test.skip(info.project.name !== 'desktop-1440', 'Desktop editing; anonymous visitors cover 1440/768/390 below');
      test.setTimeout(150000);
      const sample = scope === 'section' ? 'blox-test-flower.mp4' : 'blox-test-friday.mp4';
      test.skip(source === 'mp4' && !fs.existsSync(path.join(root, 'uploads/videos', sample)), 'Local MP4 samples are not installed');
      await page.goto(fixtures.blox_page_url);
      const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
      expect(canonical.origin).toBe(new URL(baseURL).origin);
      expect(canonical.search).toBe('');
      const publicUrl = canonical.pathname;
      const asset = source === 'generated' ? await createBackgroundVideo(page, root) : { url: `/uploads/videos/${sample}` };
      // Published fixtures outlive worker restarts; the isolated runner removes uploads.
      const marker = `E6 ${scope} ${source} ${Date.now()}`;
      const evidence = [], errors = [];
      const surfaceIn = target => {
        return target.locator(`video[data-blox-video-src="${asset.url}"]`).locator('..').locator('..');
      };
      async function visit(published, width = 1440, reducedMotion = 'no-preference') {
        const context = await browser.newContext({ baseURL, viewport: { width, height: 900 }, reducedMotion, storageState: { cookies: [], origins: [] } });
        try {
          const visitor = await context.newPage();
          const requests = [];
          visitor.on('request', request => { if (new URL(request.url()).pathname === asset.url) requests.push(request.url()); });
          visitor.on('pageerror', error => errors.push(error.message));
          visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
          visitor.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
          const response = await visitor.goto(publicUrl);
          expect(response.status()).toBe(200);
          await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
          const surface = surfaceIn(visitor);
          if (!published) {
            await expect(surface).toHaveCount(0);
            await expect(visitor.getByRole('heading', { name: marker, exact: true })).toHaveCount(0);
            expect(requests).toEqual([]);
          } else {
            await expect(surface).toHaveCount(1);
            await expect(visitor.getByRole('heading', { name: marker, exact: true })).toBeVisible();
            expect(await surface.evaluate(node => node.matches('section') ? 'section' : node.matches('.yk-container.blox-has-bg') ? 'container-element' : 'unexpected')).toBe(scope);
            if (scope === 'section') {
              expect(await surface.getByRole('heading', { name: marker, exact: true }).evaluate(node => {
                const paint = [];
                for (let parent = node.parentElement; parent && parent.tagName !== 'SECTION'; parent = parent.parentElement) {
                  const css = getComputedStyle(parent);
                  if (css.backgroundImage !== 'none' || css.backgroundColor !== 'rgba(0, 0, 0, 0)') paint.push([css.backgroundImage, css.backgroundColor]);
                }
                return paint;
              })).toEqual([]);
            } else {
              await expect(surface.getByRole('heading', { name: `${marker} content`, exact: true })).toBeVisible();
              await expect(surface.getByRole('heading', { name: `${marker} content`, exact: true })).toHaveCSS('color', 'rgb(255, 255, 255)');
              await expect(surface.locator(':scope > .blox-bg-overlay')).toHaveCSS('background-color', 'rgba(0, 0, 0, 0.6)');
            }
            await surface.scrollIntoViewIfNeeded();
            await expect(surface).toHaveCSS('background-image', /yikaicms-industrial-600.webp/);
            expect(await surface.evaluate(node => node.getBoundingClientRect().height)).toBeGreaterThan(100);
            const video = surface.locator(':scope > .blox-bg-media > video');
            await expect(video).toHaveAttribute('data-blox-video-src', asset.url);
            await expect(video).toHaveAttribute('poster', poster);
            await expect(video).toHaveAttribute('data-blox-mobile-video', 'poster');
            await expect(video.locator('..')).toHaveCSS('pointer-events', 'none');
            if (width === 390 || reducedMotion === 'reduce') {
              await expect(video).not.toHaveAttribute('src', /.+/);
              await expect(video).toHaveCSS('opacity', '0');
              expect(await visitor.evaluate(async url => {
                const image = new Image();
                image.src = url;
                await image.decode();
                return image.naturalWidth > 0;
              }, poster)).toBe(true);
              await visitor.waitForTimeout(300);
              expect(requests).toEqual([]);
            } else {
              await expect(video).toHaveAttribute('src', asset.url);
              await expect.poll(() => video.evaluate(node => node.readyState)).toBeGreaterThanOrEqual(3);
              await expect.poll(() => video.evaluate(node => node.currentTime)).toBeGreaterThan(0.05);
              expect(await video.evaluate(node => node.videoWidth)).toBeGreaterThan(0);
              expect(await video.evaluate(node => node.error)).toBeNull();
              const first = await pixelHash(video);
              await expect.poll(() => pixelHash(video)).not.toBe(first);
              await expect(video).toHaveCSS('opacity', '1');
              expect(requests.length).toBeGreaterThan(0);
            }
            await surface.screenshot({ path: info.outputPath(`published-${width}-${reducedMotion}.png`) });
          }
          evidence.push({ publicUrl, published, width, reducedMotion, cache: response.headers()['x-cache'], videoRequests: requests.length });
        } finally { await context.close(); }
      }

      try {
        await visit(false);
        await visit(false);
        expect(evidence.at(-1).cache).toBe('HIT');
        await page.emulateMedia({ reducedMotion: 'reduce' });
        await openPageEditor(page, fixtures.blox_page);
        await addTemporaryHeading(page);
        await performPagePreviewUpdate(page, () => headingTextField(page).fill(marker));
        const section = page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label');
        if (scope === 'container-element') {
          await page.getByTestId('blox-library-open').click();
          await page.getByTestId('blox-add-element-container').press('Enter');
          await page.getByTestId('blox-style-tab').click();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-padding-xl').click());
          await page.getByTestId('blox-library-open').click();
          await page.getByTestId('blox-add-element-heading').press('Enter');
          await performPagePreviewUpdate(page, () => headingTextField(page).fill(`${marker} content`));
          await page.getByTestId('blox-style-tab').click();
          await page.locator('[data-control-key="color"]').getByTestId('blox-color-picker-trigger').click();
          await performPagePreviewUpdate(page, async () => {
            await page.getByTestId('blox-editor-color-text').fill('#ffffff');
            await page.getByTestId('blox-editor-color-text').press('Tab');
          });
          await page.keyboard.press('Escape');
          await page.locator('[data-testid="blox-tree-element"][data-element-type="container"]').last().locator('[data-element-drag-handle]').click();
          await page.getByTestId('blox-style-tab').click();
        } else {
          await section.click();
          await page.getByTestId('blox-style-tab').click();
          await expect(page.getByTestId('blox-section-property-grid')).toBeVisible();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-section-padding-xl').click());
        }
        const videoControl = scope === 'section' ? page.getByTestId('blox-section-background-video-control') : page.locator('[data-control-key="bg_video"]');
        const imageInput = scope === 'section' ? page.getByTestId('blox-section-bg-image') : page.locator('[data-control-key="bg_image"] input');
        await performPagePreviewUpdate(page, () => imageInput.fill(poster));
        // Only the catalog response is deterministic; download, decoding and playback are real.
        await page.route('**/admin/media_api.php?*', route => route.fulfill({ json: {
          code: 0, data: { items: [{ id: 1, name: path.basename(asset.url), url: asset.url, type: 'video' }], page: 1, pages: 1, total: 1 },
        } }));
        await videoControl.getByRole('button').first().click();
        await performPagePreviewUpdate(page, () => page.getByTestId('blox-media-item').first().click());
        await page.unroute('**/admin/media_api.php?*');
        await expect(videoControl.locator('input')).toHaveValue(asset.url);
        if (scope === 'container-element') {
          await performPagePreviewUpdate(page, () => page.locator('[data-control-key="bg_overlay"] select').selectOption('60'));
        }

        if (scope === 'section') {
          await page.getByTestId('blox-tree-container').last().click();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-background-image-url').fill(obstruction));
          await page.getByTestId('blox-tree-column').last().locator(':scope > div').first().click();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-column-background-image-url').fill(obstruction));
          await section.click();
          const warning = page.getByTestId('blox-bg-video-obstruction');
          await expect(warning).toBeVisible();
          await expect(warning).toContainText('2');
          const before = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sel));
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-clear-bg-video-obstructions').click());
          await expect(warning).toBeHidden();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
          expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sel))).toBe(before);
          // Undo restores the prior editing layer as well as the document.
          await section.click();
          await expect(warning).toBeVisible();
          await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
          await expect(warning).toBeHidden();
        }
        await waitPreviewSettled(page);
        await command(page, 'save_draft', 'blox-save');
        await page.goto('/admin/page.php');
        await openPageEditor(page, fixtures.blox_page);
        const canvasSurface = surfaceIn(await frame(page));
        await expect(canvasSurface.locator(':scope > .blox-bg-media > video')).toHaveAttribute('data-blox-video-src', asset.url);
        await page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label').click();
        if (scope === 'container-element') {
          await page.locator('[data-testid="blox-tree-element"][data-element-type="container"]').last().locator('[data-element-drag-handle]').click();
        }
        await page.getByTestId('blox-style-tab').click();
        await expect(videoControl.locator('input')).toHaveValue(asset.url);
        await expect(imageInput).toHaveValue(poster);
        await expectClean(page);
        await visit(false);
        await visit(false);
        expect(evidence.at(-1).cache).toBe('HIT');
        await command(page, 'publish', 'blox-publish-page');
        await visit(true);
        expect(evidence.at(-1).cache).toBe('MISS');
        await visit(true, 768);
        expect(evidence.at(-1).cache).toBe('HIT');
        await visit(true, 390);
        await visit(true, 1440, 'reduce');
        expect(errors).toEqual([]);
      } finally {
        await info.attach('background-video-lifecycle', { body: JSON.stringify({ scope, source, asset: asset.url, evidence, errors }, null, 2), contentType: 'application/json' });
      }
    });
  }
}
