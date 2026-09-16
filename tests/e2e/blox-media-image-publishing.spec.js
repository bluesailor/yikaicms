const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const fs = require('node:fs');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const { addTemporaryHeading, frame, openPageEditor, performPagePreviewUpdate, expectClean, waitPreviewSettled } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'catalog-baseline-fixture.php'), action], { cwd: root });
let cleanupThemes = () => {};
test.beforeAll(() => {
  cleanupThemes = installMarketThemes(root, ['business', 'minimal']);
  fixture('cache-pretty');
});
test.afterAll(() => { fixture('restore'); cleanupThemes(); });

test('real image uploads replace without distortion and button alignment survives publishing @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Edit once; public 1440/768/390 viewports run inside the scenario');
  test.setTimeout(180000);
  const marker = `E3 images ${Date.now()}`;
  const alt = `${marker} illustration`;
  const actionText = 'Project details';
  const images = {
    wide: { width: 960, height: 540, color: [20, 104, 180], name: `e3-wide-${Date.now()}.png` },
    portrait: { width: 480, height: 720, color: [20, 130, 90], name: `e3-portrait-${Date.now()}.png` },
  };
  const evidence = [], errors = [];
  await page.goto(fixtures.blox_page_url);
  const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
  expect(canonical.origin).toBe(new URL(baseURL).origin);
  expect(canonical.search).toBe('');
  const publicUrl = canonical.pathname;
  const section = target => target.locator('section').filter({ has: target.getByRole('heading', { name: marker, exact: true }) }).last();
  const imageIn = target => target.getByAltText(alt, { exact: true });

  async function command(action, button) {
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
  async function select(type) {
    await page.locator(`[data-testid="blox-tree-element"][data-element-type="${type}"]`).last().locator('[data-element-drag-handle]').click();
    await page.getByTestId('blox-content-tab').click();
  }
  async function add(type) {
    await waitPreviewSettled(page);
    await page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label').click();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId(`blox-add-element-${type}`).press('Enter');
  }
  const selectedData = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).selEl.data)));
  async function align(value) {
    await select('button');
    await page.getByTestId('blox-style-tab').click();
    const labels = { left: '左对齐', center: '居中', right: '右对齐' };
    const control = page.locator('[data-control-key="align"]').getByRole('button', { name: labels[value], exact: true });
    if (await control.getAttribute('aria-pressed') !== 'true') await performPagePreviewUpdate(page, () => control.click());
    await expect(control).toHaveAttribute('aria-pressed', 'true');
  }
  async function selectInCanvas(type) {
    await waitPreviewSettled(page);
    const canvas = await frame(page);
    const target = type === 'image' ? imageIn(canvas)
      : section(canvas).getByRole('link', { name: actionText, exact: true });
    const editorUrl = page.url(), canvasUrl = canvas.url(), tabs = page.context().pages().length;
    await target.evaluate(node => node.scrollIntoView({ block: 'center' }));
    await waitPreviewSettled(page);
    // Map a hit-tested iframe point through CSS zoom before sending a real click.
    const point = await target.evaluate(node => {
      const rect = node.getBoundingClientRect(), x = rect.x + rect.width / 2, y = rect.y + rect.height / 2;
      return { x, y, width: window.innerWidth, hit: node.contains(document.elementFromPoint(x, y)) };
    });
    expect(point.hit).toBe(true);
    const bounds = await page.getByTestId('blox-canvas').boundingBox();
    const scale = bounds.width / point.width;
    await page.mouse.click(bounds.x + point.x * scale, bounds.y + point.y * scale);
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).selEl?.type)).toBe(type);
    await page.getByTestId('blox-content-tab').click();
    if (type === 'image') await expect(page.locator('[data-control-key="alt"] input')).toHaveValue(alt);
    else await expect(page.locator('[data-control-key="text"] input').first()).toHaveValue(actionText);
    await waitPreviewSettled(page);
    expect(page.url()).toBe(editorUrl);
    expect(canvas.url()).toBe(canvasUrl);
    expect(page.context().pages()).toHaveLength(tabs);
    evidence.push({ canvasSelection: type, editorUrl, canvasUrl, tabs });
  }
  async function upload(kind) {
    const asset = images[kind];
    const encoded = await page.evaluate(({ width, height, color }) => {
      const canvas = document.createElement('canvas');
      canvas.width = width;
      canvas.height = height;
      const ctx = canvas.getContext('2d');
      ctx.fillStyle = `rgb(${color.join(',')})`;
      ctx.fillRect(0, 0, width, height);
      ctx.strokeStyle = '#ffffff';
      ctx.lineWidth = 8;
      ctx.strokeRect(16, 16, width - 32, height - 32);
      ctx.beginPath();
      ctx.arc(width / 2, height / 2, Math.min(width, height) / 5, 0, 2 * Math.PI);
      ctx.stroke();
      return canvas.toDataURL('image/png').split(',')[1];
    }, asset);
    await page.getByTestId('blox-element-image-media').click();
    const pending = page.waitForResponse(response => response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/media_api.php'
      && new URL(response.url()).searchParams.get('action') === 'upload');
    await performPagePreviewUpdate(page, () => page.locator('[x-ref="mediaDialog"] input[type="file"]').setInputFiles({
      name: asset.name, mimeType: 'image/png', buffer: Buffer.from(encoded, 'base64'),
    }));
    const response = await pending;
    expect(response.status()).toBe(200);
    const result = await response.json();
    expect(result.code).toBe(0);
    expect(result.data.type).toBe('image');
    expect(String(result.data.id)).toMatch(/^[1-9]\d*$/);
    expect(result.data.url).toMatch(/^\/uploads\/images\//);
    asset.url = result.data.url;
    asset.id = Number(result.data.id);
    await expect(page.getByTestId('blox-element-image-url')).toHaveValue(asset.url);
    await expect(page.locator('[x-ref="mediaDialog"]')).toBeHidden();
  }
  async function checkImage(target, kind, label) {
    const asset = images[kind], image = imageIn(target);
    await image.scrollIntoViewIfNeeded();
    await expect.poll(() => image.evaluate(node => node.complete && node.naturalWidth > 0 && Boolean(node.currentSrc))).toBe(true);
    const metrics = await image.evaluate(node => {
      const rect = node.getBoundingClientRect(), parent = node.parentElement.getBoundingClientRect();
      const canvas = document.createElement('canvas');
      canvas.width = 20;
      canvas.height = 20;
      const ctx = canvas.getContext('2d');
      ctx.drawImage(node, 0, 0, 20, 20);
      return { src: node.getAttribute('src'), currentSrc: new URL(node.currentSrc).pathname,
        srcset: node.getAttribute('srcset'), width: rect.width, height: rect.height,
        naturalWidth: node.naturalWidth, naturalHeight: node.naturalHeight,
        left: rect.left, right: rect.right, parentLeft: parent.left, parentRight: parent.right,
        pixel: [...ctx.getImageData(4, 4, 1, 1).data] };
    });
    const stem = asset.url.replace(/\.png$/, '');
    expect([asset.url, `${stem}_medium.png`]).toContain(metrics.currentSrc);
    expect([asset.url, `${stem}_medium.png`]).toContain(metrics.src);
    expect(metrics.naturalWidth / metrics.naturalHeight).toBeCloseTo(asset.width / asset.height, 2);
    expect(metrics.width / metrics.height).toBeCloseTo(asset.width / asset.height, 2);
    expect(metrics.left).toBeGreaterThanOrEqual(metrics.parentLeft - 1);
    expect(metrics.right).toBeLessThanOrEqual(metrics.parentRight + 1);
    expect(metrics.pixel[3]).toBe(255);
    for (let index = 0; index < 3; index++) expect(Math.abs(metrics.pixel[index] - asset.color[index])).toBeLessThanOrEqual(3);
    const other = images[kind === 'wide' ? 'portrait' : 'wide'].url;
    if (other) expect(metrics.srcset || '').not.toContain(other.replace(/\.png$/, ''));
    await expect(image.locator('..')).toHaveAttribute('href', publicUrl + '#image-details');
    await expect(image.locator('..')).toHaveAttribute('target', '_blank');
    evidence.push({ label, kind, ...metrics });
    return image;
  }
  async function checkButton(target, value, label) {
    const button = section(target).getByRole('link', { name: actionText, exact: true });
    await expect(button).toHaveAttribute('href', publicUrl + '#button-details');
    await expect(button).not.toHaveAttribute('target', '_blank');
    const metrics = await button.evaluate(node => {
      const rect = node.getBoundingClientRect(), parent = node.parentElement.getBoundingClientRect();
      return { left: rect.left, right: rect.right, width: rect.width, parentLeft: parent.left, parentRight: parent.right,
        textAlign: getComputedStyle(node.parentElement).textAlign,
        direction: getComputedStyle(node.parentElement).direction, overflow: node.scrollWidth - node.clientWidth };
    });
    const physicalAlign = metrics.textAlign === 'start' ? (metrics.direction === 'rtl' ? 'right' : 'left')
      : metrics.textAlign === 'end' ? (metrics.direction === 'rtl' ? 'left' : 'right') : metrics.textAlign;
    expect(physicalAlign).toBe(value);
    expect(metrics.overflow).toBeLessThanOrEqual(1);
    // A full-width button would make all three alignment checks vacuous.
    expect(metrics.parentRight - metrics.parentLeft - metrics.width).toBeGreaterThan(20);
    if (value === 'left') expect(Math.abs(metrics.left - metrics.parentLeft)).toBeLessThanOrEqual(1);
    if (value === 'center') expect(Math.abs(metrics.left + metrics.right - metrics.parentLeft - metrics.parentRight)).toBeLessThanOrEqual(2);
    if (value === 'right') expect(Math.abs(metrics.right - metrics.parentRight)).toBeLessThanOrEqual(1);
    evidence.push({ label, align: value, ...metrics });
  }
  async function visit(kind, alignment, cache, width = 1440, theme = 'default', follow = false) {
    const context = await browser.newContext({ baseURL, viewport: { width, height: 960 }, storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });
    try {
      const visitor = await context.newPage();
      visitor.on('pageerror', error => errors.push(error.message));
      visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
      visitor.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
      const response = await visitor.goto(publicUrl);
      expect(response.status()).toBe(200);
      if (cache) expect(response.headers()['x-cache']).toBe(cache);
      await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
      const label = `${theme}-${kind || 'absent'}-${alignment}-${width}`;
      evidence.push({ label, publicUrl, cache: response.headers()['x-cache'] });
      if (!kind) {
        await expect(imageIn(visitor)).toHaveCount(0);
        return;
      }
      if (theme === 'minimal') await expect(visitor.locator('body')).toHaveClass(/minimal-theme/);
      else if (theme === 'default') await expect(visitor.locator('body')).toHaveClass(/yk-site-body/);
      else {
        await expect(visitor.locator('body')).not.toHaveClass(/yk-site-body|minimal-theme/);
        await expect(visitor.locator('#siteHeader')).toBeAttached();
      }
      const image = await checkImage(visitor, kind, label);
      await checkButton(visitor, alignment, label);
      expect(await visitor.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
      await section(visitor).screenshot({ path: info.outputPath(`${label}.png`) });
      if (follow) {
        const popupPromise = context.waitForEvent('page');
        await image.click();
        const popup = await popupPromise;
        await popup.waitForLoadState('domcontentloaded');
        expect(new URL(popup.url()).pathname).toBe(publicUrl);
        expect(new URL(popup.url()).hash).toBe('#image-details');
        await popup.close();
        await section(visitor).getByRole('link', { name: actionText, exact: true }).click();
        expect(new URL(visitor.url()).hash).toBe('#button-details');
        expect(context.pages()).toHaveLength(1);
      }
    } finally { await context.close(); }
  }
  async function reopen(kind, alignment) {
    await command('save_draft', 'blox-save');
    await page.goto('/admin/page.php');
    await openPageEditor(page, fixtures.blox_page);
    await page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label').click();
    await select('image');
    await expect(page.getByTestId('blox-element-image-url')).toHaveValue(images[kind].url);
    await expect(page.locator('[data-control-key="alt"] input')).toHaveValue(alt);
    await expect(page.locator('[data-control-key="click_action"] select')).toHaveValue('link');
    await expect(page.locator('[data-control-key="link_new_tab"] input')).toBeChecked();
    await checkImage(await frame(page), kind, 'reopened-canvas');
    await checkButton(await frame(page), alignment, 'reopened-canvas');
    await expectClean(page);
  }

  await visit(null, 'left', 'MISS');
  await visit(null, 'left', 'HIT');
  await openPageEditor(page, fixtures.blox_page);
  await addTemporaryHeading(page);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-heading-text').fill(marker));
  await add('image');
  await upload('wide');
  await performPagePreviewUpdate(page, async () => {
    await page.locator('[data-control-key="alt"] input').fill(alt);
    await page.locator('[data-control-key="click_action"] select').selectOption('link');
    await page.locator('[data-control-key="link_url"] input').fill(publicUrl + '#image-details');
    await page.locator('[data-control-key="link_new_tab"] input').check();
  });
  await add('button');
  await performPagePreviewUpdate(page, async () => {
    await page.locator('[data-control-key="text"] input').first().fill(actionText);
    await page.locator('[data-control-key="url"] input').fill(publicUrl + '#button-details');
  });
  await align('left');
  await selectInCanvas('image');
  await selectInCanvas('button');
  await reopen('wide', 'left');
  await command('publish', 'blox-publish-page');
  for (const width of [1440, 768, 390]) await visit('wide', 'left', width === 1440 ? 'MISS' : 'HIT', width);

  await select('image');
  const original = await selectedData();
  await upload('portrait');
  expect(await selectedData()).toEqual({ ...original, src: images.portrait.url });
  await checkImage(await frame(page), 'portrait', 'replacement-canvas');
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await select('image');
  expect(await selectedData()).toEqual(original);
  await checkImage(await frame(page), 'wide', 'undo-canvas');
  await expectClean(page);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await select('image');
  expect(await selectedData()).toEqual({ ...original, src: images.portrait.url });
  await page.getByTestId('blox-element-image-media').click();
  await expect(page.locator('[x-ref="mediaDialog"] [data-dialog-initial]')).toBeFocused();
  await page.keyboard.press('Escape');
  await expect(page.locator('[x-ref="mediaDialog"]')).toBeHidden();
  await expect(page.getByTestId('blox-element-image-url')).toHaveValue(images.portrait.url);
  await select('button');
  const originalButton = await selectedData();
  await align('center');
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await select('button');
  expect(await selectedData()).toEqual(originalButton);
  await checkButton(await frame(page), 'left', 'button-undo-canvas');
  await checkImage(await frame(page), 'portrait', 'button-undo-preserves-image');
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await select('button');
  expect(await selectedData()).toEqual({ ...originalButton, align: 'center' });
  await reopen('portrait', 'center');
  await visit('wide', 'left', 'MISS');
  await visit('wide', 'left', 'HIT');
  await command('publish', 'blox-publish-page');
  for (const width of [1440, 768, 390]) await visit('portrait', 'center', width === 1440 ? 'MISS' : 'HIT', width);

  await select('image');
  await page.getByTestId('blox-element-image-media').click();
  const media = page.locator('[x-ref="mediaDialog"]');
  await media.locator('input[data-dialog-initial]').fill(images.wide.name);
  const listing = page.waitForResponse(response => {
    const url = new URL(response.url());
    return url.pathname === '/admin/media_api.php' && url.searchParams.get('keyword') === images.wide.name;
  });
  await media.locator('input[data-dialog-initial]').press('Enter');
  const result = await (await listing).json();
  expect(result.code).toBe(0);
  // PDO may return numeric IDs as strings; verify identity across both driver representations.
  const listedImage = result.data.items.find(item => String(item.id) === String(images.wide.id));
  expect(listedImage).toEqual(expect.objectContaining({ url: images.wide.url, type: 'image' }));
  const item = page.getByTestId('blox-media-item').filter({ hasText: images.wide.name });
  await expect(item).toHaveCount(1);
  await expect(item.locator('img')).toHaveCSS('object-fit', 'contain');
  await performPagePreviewUpdate(page, () => item.click());
  await expect(page.getByTestId('blox-element-image-url')).toHaveValue(images.wide.url);
  await align('right');
  await reopen('wide', 'right');
  await visit('portrait', 'center', 'MISS');
  await command('publish', 'blox-publish-page');
  for (const width of [1440, 768, 390]) await visit('wide', 'right', width === 1440 ? 'MISS' : 'HIT', width, 'default', width === 390);
  for (const theme of ['business', 'minimal']) {
    fixture(theme);
    for (const width of [1440, 768, 390]) await visit('wide', 'right', null, width, theme);
  }
  expect(errors).toEqual([]);
  await info.attach('image-alignment-publishing', { body: JSON.stringify({ images, evidence }, null, 2), contentType: 'application/json' });
});
