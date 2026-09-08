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
test.beforeAll(() => { cleanupThemes = installMarketThemes(root, ['business', 'minimal']); fixture('pretty'); });
test.afterAll(() => { fixture('restore'); cleanupThemes(); });

test('container direction inheritance and shared wrap survive reopening and publishing @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Edit once; anonymous three-device checks run inside the scenario');
  test.setTimeout(180000);
  const marker = `E3 layout ${Date.now()}`;
  const labels = ['01 Plan', '02 Design', '03 Build customer content', '04 Coordinate site launch'];
  const evidence = [], errors = [];
  await page.goto(fixtures.blox_page_url);
  const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
  expect(canonical.origin).toBe(new URL(baseURL).origin);
  expect(canonical.search).toBe('');
  const publicUrl = canonical.pathname;
  const sectionIn = target => target.locator('section').filter({ has: target.getByRole('heading', { name: marker, exact: true }) }).last();
  const surfaceIn = target => sectionIn(target).locator('.yk-container');
  const containerRow = () => page.locator('[data-testid="blox-tree-element"][data-element-type="container"]').last().locator('[data-element-drag-handle]');
  const data = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).selEl.data)));
  const documentData = () => page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).sections)));
  async function selectContainer() {
    await containerRow().click();
    await page.getByTestId('blox-style-tab').click();
  }
  async function direction(device, value) {
    await page.getByTestId(`blox-container-responsive-device-${device}`).click();
    const control = page.getByTitle(value === 'row' ? '横向排列' : '纵向排列', { exact: true });
    await expect(control).toBeVisible();
    await performPagePreviewUpdate(page, () => control.click());
  }
  async function wrap(value) {
    const names = { auto: '自动', wrap: '允许换行', nowrap: '不换行' };
    const control = page.getByRole('button', { name: names[value], exact: true });
    await expect(control).toBeVisible();
    await performPagePreviewUpdate(page, () => control.click());
  }
  async function command(action, button) {
    const responsePromise = page.waitForResponse(response => {
      const body = new URLSearchParams(response.request().postData() || '');
      return new URL(response.url()).pathname === '/admin/blox_page_api.php' && body.get('action') === action;
    });
    if (action === 'publish') page.once('dialog', dialog => dialog.accept());
    await page.getByTestId(button).click();
    const response = await responsePromise;
    expect(response.status()).toBe(200);
    expect((await response.json()).code).toBe(0);
    await expectClean(page);
  }
  async function reopen(expected) {
    await command('save_draft', 'blox-save');
    await page.goto('/admin/page.php');
    await openPageEditor(page, fixtures.blox_page);
    await waitPreviewSettled(page);
    await page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-section-label').click();
    await selectContainer();
    expect(await data()).toEqual(expected);
    await expectClean(page);
  }
  async function selectCanvasContainer(id) {
    await page.locator('[data-testid="blox-tree-element"][data-element-type="heading"]').last().locator('[data-element-drag-handle]').click();
    await waitPreviewSettled(page);
    const canvas = await frame(page), surface = surfaceIn(canvas);
    const editorUrl = page.url();
    await surface.evaluate(node => node.scrollIntoView({ block: 'center' }));
    await waitPreviewSettled(page);
    const point = await surface.evaluate(node => {
      const box = node.getBoundingClientRect(), style = getComputedStyle(node);
      const x = box.left + parseFloat(style.paddingLeft) / 2, y = box.top + parseFloat(style.paddingTop) / 2;
      return { x, y, width: innerWidth, hit: document.elementFromPoint(x, y) === node };
    });
    expect(point.hit).toBe(true);
    const bounds = await page.getByTestId('blox-canvas').boundingBox();
    const scale = bounds.width / point.width;
    await page.mouse.click(bounds.x + point.x * scale, bounds.y + point.y * scale);
    await expect.poll(() => page.evaluate(() => window.Alpine.$data(document.body).selEl?.id)).toBe(id);
    await page.getByTestId('blox-style-tab').click();
    expect(page.url()).toBe(editorUrl);
    await expectClean(page);
  }
  async function measure(target, width, state, label) {
    const surface = surfaceIn(target);
    const mobile = width < 768;
    const gap = mobile ? 8 : 16;
    const column = mobile && state !== 'inherited';
    const wrapping = state === 'nowrap' ? 'nowrap' : state === 'wrap' || !column ? 'wrap' : 'nowrap';
    await expect(surface).toHaveCount(1);
    await expect(surface).toHaveCSS('flex-direction', column ? 'column' : 'row');
    await expect(surface).toHaveCSS('flex-wrap', wrapping);
    await expect(surface).toHaveCSS('gap', `${gap}px`);
    await expect(surface).toHaveCSS('padding-left', mobile ? '12px' : '24px');
    const buttons = surface.getByRole('link');
    await expect(buttons).toHaveText(labels);
    for (let index = 0; index < labels.length; index++) {
      await expect(buttons.nth(index)).toHaveAttribute('href', `${publicUrl}#layout-${index}`);
      await expect(buttons.nth(index)).not.toHaveAttribute('target', '_blank');
    }
    const metrics = await surface.evaluate(node => {
      const rect = element => {
        const box = element.getBoundingClientRect();
        return { left: box.left, right: box.right, top: box.top, bottom: box.bottom, width: box.width, height: box.height };
      };
      const style = getComputedStyle(node), bounds = rect(node);
      return { viewport: innerWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
        innerLeft: bounds.left + parseFloat(style.paddingLeft), innerRight: bounds.right - parseFloat(style.paddingRight),
        items: [...node.querySelectorAll('a')].map(button => {
          const range = document.createRange();
          range.selectNodeContents(button);
          return { item: rect(button.parentElement), button: rect(button), text: button.textContent,
            overflow: button.scrollWidth - button.clientWidth,
            fragments: [...range.getClientRects()].map(box => ({ left: box.left, right: box.right, top: box.top, bottom: box.bottom })) };
        }) };
    });
    await info.attach(`${label}-metrics`, { body: JSON.stringify(metrics, null, 2), contentType: 'application/json' });
    const rows = [];
    for (const entry of metrics.items) {
      if (!rows.some(top => Math.abs(top - entry.item.top) < 1)) rows.push(entry.item.top);
      expect(entry.overflow).toBeLessThanOrEqual(1);
      expect(entry.item.left).toBeGreaterThanOrEqual(metrics.innerLeft - 1);
      expect(entry.item.right).toBeLessThanOrEqual(metrics.innerRight + 1);
      for (const part of entry.fragments) {
        expect(part.left).toBeGreaterThanOrEqual(entry.button.left - 1);
        expect(part.right).toBeLessThanOrEqual(entry.button.right + 1);
        expect(part.top).toBeGreaterThanOrEqual(entry.button.top - 1);
        expect(part.bottom).toBeLessThanOrEqual(entry.button.bottom + 1);
      }
    }
    expect(metrics.viewport).toBe(width);
    expect(metrics.overflow).toBeLessThanOrEqual(1);
    if (column) {
      expect(rows).toHaveLength(labels.length);
      for (const entry of metrics.items) expect(entry.item.width).toBeCloseTo(metrics.innerRight - metrics.innerLeft, 0);
    } else if (width >= 1024 || wrapping === 'nowrap') {
      expect(rows).toHaveLength(1);
    } else if (width === 768) {
      // Exercise genuine wrapping, not just a flex-wrap class on items that fit.
      expect(rows.length).toBeGreaterThan(1);
      expect(rows.length).toBeLessThan(labels.length);
    } else {
      expect(rows.length).toBeGreaterThan(1);
      expect(rows.length).toBeLessThan(labels.length);
      expect(Math.abs(metrics.items[0].item.top - metrics.items[1].item.top)).toBeLessThan(1);
      expect(metrics.items[0].item.width).toBeLessThan(metrics.innerRight - metrics.innerLeft - 20);
    }
    for (let index = 1; index < metrics.items.length; index++) {
      const previous = metrics.items[index - 1].item, current = metrics.items[index].item;
      if (Math.abs(current.top - previous.top) < 1) expect(current.left).toBeGreaterThanOrEqual(previous.right + gap - 1);
      else expect(current.top).toBeGreaterThanOrEqual(previous.bottom + gap - 1);
    }
    evidence.push({ label, state, wrapping, rows, ...metrics });
    return surface;
  }
  async function canvasMatrix(state) {
    for (const [device, width] of [['desktop', 1280], ['tablet', 768], ['mobile', 390]]) {
      await page.getByTestId(`blox-container-responsive-device-${device}`).click();
      await waitPreviewSettled(page);
      await measure(await frame(page), width, state, `canvas-${state}-${device}`);
    }
  }
  async function publishMatrix(state, themes = ['default', 'business', 'minimal']) {
    await command('publish', 'blox-publish-page');
    for (const theme of themes) {
      fixture(theme === 'default' ? 'pretty' : theme);
      const context = await browser.newContext({ baseURL, viewport: { width: 1440, height: 960 }, reducedMotion: 'reduce', storageState: { cookies: [], origins: [] } });
      try {
        const visitor = await context.newPage();
        visitor.on('pageerror', error => errors.push(error.message));
        visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
        visitor.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
        for (const width of [1440, 768, 390]) {
          await visitor.setViewportSize({ width, height: 960 });
          const response = await visitor.goto(publicUrl);
          expect(response.status()).toBe(200);
          await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
          if (theme === 'minimal') await expect(visitor.locator('body')).toHaveClass(/minimal-theme/);
          else if (theme === 'default') await expect(visitor.locator('body')).toHaveClass(/yk-site-body/);
          else {
            await expect(visitor.locator('body')).not.toHaveClass(/minimal-theme|yk-site-body/);
            await expect(visitor.locator('#siteHeader')).toBeAttached();
          }
          const label = `${theme}-${state}-${width}`;
          const surface = await measure(visitor, width, state, label);
          await sectionIn(visitor).screenshot({ path: info.outputPath(`${label}.png`) });
          expect(await surface.count()).toBe(1);
        }
      } finally { await context.close(); }
    }
    fixture('pretty');
  }

  await openPageEditor(page, fixtures.blox_page);
  await addTemporaryHeading(page);
  await performPagePreviewUpdate(page, () => page.locator('[data-control-key="text"] input').first().fill(marker));
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-container').press('Enter');
  for (let index = 0; index < labels.length; index++) {
    await selectContainer();
    await page.getByTestId('blox-library-open').click();
    await page.getByTestId('blox-add-element-button').press('Enter');
    await page.getByTestId('blox-content-tab').click();
    await performPagePreviewUpdate(page, async () => {
      await page.locator('[data-control-key="text"] input').first().fill(labels[index]);
      await page.locator('[data-control-key="url"] input').fill(`${publicUrl}#layout-${index}`);
    });
  }
  await selectContainer();
  for (const [device, value, padding, gap] of [['desktop', 'row', 'md', 'md'], ['tablet', 'row', 'md', 'md'], ['mobile', 'column', 'sm', 'sm']]) {
    await direction(device, value);
    await performPagePreviewUpdate(page, async () => {
      await page.getByTestId(`blox-container-padding-${padding}`).click();
      await page.getByTestId(`blox-container-gap-${gap}`).click();
    });
  }
  const before = await data();
  expect(before.direction).toEqual({ d: 'row', t: 'row', m: 'column' });
  expect(before.wrap).toBe('auto');
  expect(before.children.map(child => child.data.text)).toEqual(labels);
  expect(new Set(before.children.map(child => child.id)).size).toBe(labels.length);
  for (const child of before.children) {
    expect(child.data.new_tab).toBe(false);
    child.data.new_tab = '0';
  }
  await reopen(before);
  const originalDocument = await documentData();
  const containerId = await page.evaluate(() => window.Alpine.$data(document.body).selEl.id);
  await selectCanvasContainer(containerId);
  expect(await documentData()).toEqual(originalDocument);
  await canvasMatrix('override');
  await publishMatrix('override');

  await page.getByTestId('blox-container-responsive-device-mobile').click();
  await expect(page.getByTestId('blox-container-direction-inherit')).toBeVisible();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-container-direction-inherit').click());
  const inherited = { ...before, direction: { d: 'row', t: 'row' } };
  const inheritedDocument = structuredClone(originalDocument);
  delete inheritedDocument.at(-1).columns[0].elements.find(element => element.id === containerId).data.direction.m;
  expect(await data()).toEqual(inherited);
  expect(await documentData()).toEqual(inheritedDocument);
  await expect(page.getByTestId('blox-container-direction-inherit')).toBeHidden();
  await expect(page.getByTestId('blox-container-gap-inherit')).toBeVisible();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await selectContainer();
  expect(await data()).toEqual(before);
  expect(await documentData()).toEqual(originalDocument);
  await expectClean(page);
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await selectContainer();
  expect(await data()).toEqual(inherited);
  expect(await documentData()).toEqual(inheritedDocument);
  await reopen(inherited);
  expect(await documentData()).toEqual(inheritedDocument);
  await canvasMatrix('inherited');
  await publishMatrix('inherited');

  await direction('mobile', 'column');
  await wrap('nowrap');
  const noWrap = { ...before, wrap: 'nowrap' };
  expect(await data()).toEqual(noWrap);
  await reopen(noWrap);
  await canvasMatrix('nowrap');
  await publishMatrix('nowrap', ['default']);
  await wrap('wrap');
  const explicitWrap = { ...before, wrap: 'wrap' };
  expect(await data()).toEqual(explicitWrap);
  await reopen(explicitWrap);
  await canvasMatrix('wrap');
  await publishMatrix('wrap', ['default']);
  expect(errors).toEqual([]);
  await info.attach('container-layout-publishing', { body: JSON.stringify({ before, inherited, noWrap, explicitWrap, evidence }, null, 2), contentType: 'application/json' });
});
