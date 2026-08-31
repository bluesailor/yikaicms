const { test, expect } = require('@playwright/test');
const { openEditor, frame, performPreviewUpdate, observeConsole, observeUnsafeWrites, canvasScrollTop, waitPreviewSettled } = require('./helpers');

const field = (page, key) => page.locator('[data-control-key="' + key + '"]');

async function selectAbout(page) {
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  const element = page.locator('[data-testid="blox-tree-element"][data-home-block-type="about"]').first();
  const section = element.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]');
  await section.locator('[data-section-drag-handle]').first().click();
  if (await structure.isVisible()) await structure.click();
  await section.locator('[data-home-column-tree$=".text"]').click();
}

async function inheritedAbout(page) {
  await openEditor(page);
  // Arrange an old document that inherits every field; never write to the server.
  await performPreviewUpdate(page, () => page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const element = app.sections.flatMap(s => s.columns.flatMap(c => c.elements)).find(e => e.type === 'home-block' && e.data.block_type === 'about');
    Object.keys(app.homeFieldSeeds.about).forEach(key => { element.data[key] = ''; });
    app.refreshPreview();
  }));
  await waitPreviewSettled(page);
  const before = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
  await selectAbout(page);
  const seeds = await page.evaluate(() => window.Alpine.$data(document.body).homeFieldSeeds.about);
  return { before, seeds };
}

test('inherited copy and thumbnail match the canvas without materializing overrides @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  const { before, seeds } = await inheritedAbout(page);
  const title = field(page, 'override_title').locator('input');
  await expect(title).toHaveValue('');
  await expect(title).toHaveAttribute('placeholder', seeds.override_title);
  await expect(field(page, 'override_content').locator('textarea')).toHaveAttribute('placeholder', seeds.override_content);
  await expect(page.getByTestId('blox-home-source-override_title')).toHaveAttribute('data-source', 'inherit');
  await title.focus();
  await title.press('Tab');
  await page.getByTestId('blox-home-group-media').click();
  const picture = page.getByTestId('blox-about-image-control').locator('img');
  await expect(picture).toHaveAttribute('src', seeds.override_image);
  await expect.poll(() => picture.evaluate(img => img.complete && img.naturalWidth > 0)).toBe(true);
  await expect((await frame(page)).locator('[data-yk-home-field="override_image"]').first()).toHaveAttribute('src', seeds.override_image);
  await expect(page.getByTestId('blox-home-inherit-override_image')).toBeHidden();
  expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(before);
  await page.screenshot({ path: testInfo.outputPath('about-inherited-image.png') });
  expect(errors).toEqual([]);
  expect(writes).toEqual([]);
});

test('restoring an image or title preserves other fields and can be undone @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  const { seeds } = await inheritedAbout(page);
  await performPreviewUpdate(page, () => field(page, 'override_title').locator('input').fill('Inheritance draft title'));
  await page.getByTestId('blox-home-group-media').click();
  await page.getByTestId('blox-about-image-control').locator('summary').click();
  await performPreviewUpdate(page, () => page.getByTestId('blox-about-image-url').fill('/assets/images/demo/banner-2.svg'));
  await waitPreviewSettled(page);
  const scroll = await canvasScrollTop(page);
  await expect(page.getByTestId('blox-home-source-override_image')).toHaveAttribute('data-source', 'override');
  await performPreviewUpdate(page, () => page.getByTestId('blox-home-inherit-override_image').press('Enter'));
  await expect(page.getByTestId('blox-about-image-control').locator('img')).toHaveAttribute('src', seeds.override_image);
  await expect(page.getByTestId('blox-about-image-url')).toHaveValue('');
  await waitPreviewSettled(page);
  expect(Math.abs((await canvasScrollTop(page)) - scroll)).toBeLessThan(8);
  await page.getByTestId('blox-home-group-media').click();
  const historyIndex = () => page.evaluate(() => window.Alpine.$data(document.body).historyStore().index);
  const restoredIndex = await historyIndex();
  await performPreviewUpdate(page, () => page.keyboard.press('ControlOrMeta+z'));
  expect(await historyIndex()).toBe(restoredIndex - 1);
  await expect(page.getByTestId('blox-about-image-url')).toHaveValue('/assets/images/demo/banner-2.svg');
  await performPreviewUpdate(page, () => page.keyboard.press('ControlOrMeta+Shift+z'));
  expect(await historyIndex()).toBe(restoredIndex);
  await expect(page.getByTestId('blox-about-image-url')).toHaveValue('');
  await performPreviewUpdate(page, () => page.keyboard.press('ControlOrMeta+z'));
  expect(await historyIndex()).toBe(restoredIndex - 1);
  await page.getByTestId('blox-home-group-content').click();
  await expect(field(page, 'override_title').locator('input')).toHaveValue('Inheritance draft title');
  await performPreviewUpdate(page, () => page.getByTestId('blox-home-inherit-override_title').click());
  await expect(field(page, 'override_title').locator('input')).toHaveValue('');
  await expect((await frame(page)).locator('[data-yk-home="about"]').first()).toContainText(seeds.override_title);
  expect(await page.evaluate(() => window.Alpine.$data(document.body).selEl.data.override_image)).toBe('/assets/images/demo/banner-2.svg');
  await page.screenshot({ path: testInfo.outputPath('about-inherited-content.png') });
  expect(errors).toEqual([]);
  expect(writes).toEqual([]);
});

test('saving the disposable draft keeps inheritance and leaves the public homepage unchanged @ci', async ({ page }) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  await inheritedAbout(page);
  const draftTitle = 'Only in the inherited-content draft';
  await performPreviewUpdate(page, () => field(page, 'override_title').locator('input').fill(draftTitle));
  const saved = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_home_api.php' && r.request().method() === 'POST');
  if (await page.getByTestId('blox-save').isVisible()) await page.getByTestId('blox-save').click();
  else {
    await page.getByTestId('blox-mobile-actions-open').click();
    await page.locator('.blox-mobile-actions-menu button').filter({ has: page.locator('.ti-device-floppy') }).click();
  }
  expect((await (await saved).json()).code).toBe(0);
  await openEditor(page);
  await selectAbout(page);
  await expect(field(page, 'override_title').locator('input')).toHaveValue(draftTitle);
  const values = await page.evaluate(() => window.Alpine.$data(document.body).selEl.data);
  expect(values.override_image).toBe('');
  expect(values.override_content).toBe('');
  const publicPage = await page.request.get('/');
  expect(publicPage.status()).toBe(200);
  expect(await publicPage.text()).not.toContain(draftTitle);
  expect(writes).toEqual(['home:save']);
  expect(errors).toEqual([]);
});
