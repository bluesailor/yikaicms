const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const { addTemporaryHeading, frame, openPageEditor, performPagePreviewUpdate, expectClean, waitPreviewSettled } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-baseline-fixture.php'), action], { cwd: root });

test.beforeAll(() => fixture('cache-pretty'));
test.afterAll(() => fixture('restore'));

test('four basic elements survive reopening and replace the anonymous cached page @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop persistence; responsive editing has its own baseline');
  test.setTimeout(120000);
  const marker = `E3 public ${Date.now()}`;
  const evidence = [];
  const publicErrors = [];
  await page.goto(fixtures.blox_page_url);
  const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
  expect(canonical.origin).toBe(new URL(baseURL).origin);
  expect(canonical.search).toBe('');
  const publicUrl = canonical.pathname;

  async function visit(cache, published) {
    const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
      const visitor = await context.newPage();
      visitor.on('pageerror', error => publicErrors.push(error.message));
      visitor.on('console', message => { if (message.type() === 'error') publicErrors.push(message.text()); });
      visitor.on('response', response => { if (response.status() >= 500) publicErrors.push(`${response.status()} ${response.url()}`); });
      const response = await visitor.goto(publicUrl);
      expect(response.status()).toBe(200);
      expect(response.headers()['x-cache']).toBe(cache);
      await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
      const heading = visitor.getByRole('heading', { name: marker, exact: true });
      if (!published) {
        await expect(heading).toHaveCount(0);
      } else {
        await expect(heading).toBeVisible();
        const button = visitor.getByRole('link', { name: `${marker} action`, exact: true });
        await expect(button).toHaveAttribute('href', publicUrl + '#e3-target');
        await expect(button).toHaveAttribute('target', '_blank');
        const image = visitor.getByAltText(`${marker} image`, { exact: true });
        await expect(image).toHaveAttribute('src', '/images/logo.png');
        await expect.poll(() => image.evaluate(el => el.complete && el.naturalWidth > 0)).toBe(true);
        const container = visitor.locator('.yk-container').last();
        await expect(container).toHaveCSS('padding-top', '24px');
        await expect(container).toHaveCSS('border-top-left-radius', '16px');
        const popupPromise = context.waitForEvent('page');
        await button.click();
        const popup = await popupPromise;
        await popup.waitForLoadState('domcontentloaded');
        expect(new URL(popup.url()).pathname).toBe(publicUrl);
        expect(new URL(popup.url()).hash).toBe('#e3-target');
        await popup.close();
      }
      evidence.push({ url: publicUrl, cache, published });
    } finally { await context.close(); }
  }

  await visit('MISS', false);
  await visit('HIT', false);
  await openPageEditor(page, fixtures.blox_page);
  await addTemporaryHeading(page);
  const editorUrl = page.url();
  const originalIds = await (await frame(page)).locator('[data-yk-el-type]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-yk-el-id')));
  expect(originalIds.every(Boolean)).toBe(true);
  expect(new Set(originalIds).size).toBe(originalIds.length);
  const text = page.locator('[data-control-key="text"] input').first();
  await performPagePreviewUpdate(page, () => text.fill(marker));
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(text).not.toHaveValue(marker);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await expect(text).toHaveValue(marker);
  expect(await (await frame(page)).locator('[data-yk-el-type]').evaluateAll(nodes => nodes.map(node => node.getAttribute('data-yk-el-id')))).toEqual(originalIds);

  async function add(type) {
    await waitPreviewSettled(page);
    await page.getByTestId('blox-tree-section').last().click();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId(`blox-add-element-${type}`).press('Enter');
  }
  await add('button');
  await performPagePreviewUpdate(page, async () => {
    await text.fill(`${marker} action`);
    await page.locator('[data-control-key="url"] input').fill(publicUrl + '#e3-target');
    await page.locator('[data-control-key="new_tab"] input').check();
  });
  await add('image');
  // The picker is deterministic; the selected image itself is a real local asset.
  await page.route('**/admin/media_api.php?*', route => route.fulfill({ json: {
    code: 0, data: { items: [{ id: 1, name: 'E3 image', url: '/images/logo.png', type: 'image' }], page: 1, pages: 1, total: 1 },
  } }));
  await page.getByTestId('blox-element-image-media').click();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-media-item').first().click());
  await performPagePreviewUpdate(page, () => page.locator('[data-control-key="alt"] input').fill(`${marker} image`));
  await page.unroute('**/admin/media_api.php?*');
  await add('container');
  await page.getByTestId('blox-style-tab').click();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-padding-md').click());
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-radius-xl').click());
  await waitPreviewSettled(page);
  const canvas = await frame(page);
  const canvasHeading = canvas.getByRole('heading', { name: marker, exact: true });
  await canvasHeading.evaluate(el => el.scrollIntoView({ block: 'center' }));
  await waitPreviewSettled(page);
  // Chromium's CSS-zoom iframe actionability uses unscaled coordinates. Map the
  // actual hit-tested point, then send a real pointer event, not dispatchEvent.
  const point = await canvasHeading.evaluate(el => {
    const r = el.getBoundingClientRect(), x = r.x + r.width / 2, y = r.y + r.height / 2;
    return { x, y, width: window.innerWidth, hit: el.contains(document.elementFromPoint(x, y)) };
  });
  expect(point.hit).toBe(true);
  const bounds = await page.getByTestId('blox-canvas').boundingBox();
  const scale = bounds.width / point.width;
  await page.mouse.click(bounds.x + point.x * scale, bounds.y + point.y * scale);
  await page.getByTestId('blox-content-tab').click();
  await expect(text).toHaveValue(marker);
  expect(page.url()).toBe(editorUrl);

  async function command(action, button) {
    const pending = page.waitForResponse(response => {
      const body = new URLSearchParams(response.request().postData() || '');
      return new URL(response.url()).pathname === '/admin/blox_page_api.php' && body.get('action') === action;
    });
    if (action === 'publish') page.once('dialog', dialog => dialog.accept());
    await page.getByTestId(button).click();
    expect((await (await pending).json()).code).toBe(0);
    await expectClean(page);
  }
  await command('save_draft', 'blox-save');
  await page.goto('/admin/page.php');
  await openPageEditor(page, fixtures.blox_page);
  await expect((await frame(page)).getByRole('heading', { name: marker, exact: true })).toBeVisible();
  await expect((await frame(page)).getByAltText(`${marker} image`, { exact: true })).toBeVisible();
  await expect((await frame(page)).getByRole('link', { name: `${marker} action`, exact: true })).toHaveAttribute('target', '_blank');
  await expect((await frame(page)).locator('.yk-container').last()).toHaveCSS('padding-top', '24px');
  // Saving may invalidate the cache, but it must not expose the draft.
  await visit('MISS', false);
  await visit('HIT', false);
  await command('publish', 'blox-publish-page');
  await visit('MISS', true);
  await visit('HIT', true);
  expect(publicErrors).toEqual([]);
  await info.attach('anonymous-cache-evidence', { body: JSON.stringify(evidence, null, 2), contentType: 'application/json' });
});
