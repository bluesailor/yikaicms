const { test, expect } = require('@playwright/test');
const { openEditor, frame, observeConsole, observeUnsafeWrites, performPreviewUpdate, waitPreviewSettled, canvasScrollTop } = require('./helpers');

async function openBlock(page, type) {
  await openEditor(page);
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  const element = page.locator('[data-testid="blox-tree-element"][data-home-block-type="' + type + '"]').first();
  const section = element.locator('xpath=ancestor::*[@data-testid="blox-tree-section"]');
  await section.locator('[data-section-drag-handle]').first().click();
  if (await structure.isVisible()) await structure.click();
  if (type === 'about') await section.locator('[data-home-column-tree$=".text"]').click();
  else await element.locator('[data-element-drag-handle]').click();
  return section;
}

const field = (page, key) => page.locator('[data-control-key="' + key + '"]');

test('about starts with content and image edits preserve text and undo @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  await openBlock(page, 'about');
  const original = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
  await expect(field(page, 'override_title')).toBeVisible();
  await expect(field(page, 'block_type')).toHaveCount(0);
  await expect(field(page, 'title_decor_style')).toHaveCount(0);
  await page.getByTestId('blox-home-group-more').click();
  await expect(field(page, 'enabled')).toBeVisible();
  await expect(field(page, 'block_type')).toBeVisible();
  await page.getByTestId('blox-home-group-layout').press('Enter');
  await expect(field(page, 'override_layout')).toBeVisible();
  await page.getByTestId('blox-home-group-content').click();
  expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(original);
  await performPreviewUpdate(page, () => field(page, 'override_title').locator('input').fill('Company draft'));
  const about = (await frame(page)).locator('[data-yk-home="about"]').first();
  await expect(about).toContainText('Company draft');
  await page.getByTestId('blox-home-group-media').click();
  await expect(page.getByTestId('blox-about-image-media')).toBeVisible();
  await page.getByTestId('blox-about-image-control').locator('summary').click();
  const imageBefore = await page.getByTestId('blox-about-image-url').inputValue();
  await performPreviewUpdate(page, () => page.getByTestId('blox-about-image-url').fill('/assets/images/demo/banner-2.svg'));
  await expect(about.locator('img').first()).toHaveAttribute('src', '/assets/images/demo/banner-2.svg');
  await expect(about).toContainText('Company draft');
  await page.screenshot({ path: testInfo.outputPath('about-image-panel.png') });
  await page.getByTestId('blox-home-group-media').click();
  await performPreviewUpdate(page, () => page.keyboard.press('ControlOrMeta+z'));
  await expect(page.getByTestId('blox-about-image-url')).toHaveValue(imageBefore);
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});

test('CTA copy links and background remain editable without changing tabs @ci', async ({ page }, testInfo) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  await openBlock(page, 'cta');
  await performPreviewUpdate(page, () => field(page, 'override_button_text').locator('input').fill('Contact draft'));
  await performPreviewUpdate(page, () => field(page, 'override_button_url').locator('input').fill('/contact.html?from=draft'));
  const cta = (await frame(page)).locator('[data-yk-home="cta"]').first();
  await expect(cta.locator('a').first()).toHaveAttribute('href', '/contact.html?from=draft');
  await expect(cta.locator('a').first()).toContainText('Contact draft');
  await waitPreviewSettled(page);
  const scroll = await canvasScrollTop(page);
  await page.getByTestId('blox-home-group-media').click();
  for (const key of ['bg_image', 'bg_color', 'bg_overlay_color', 'bg_overlay_opacity', 'text_light']) await expect(field(page, key)).toHaveCount(1);
  await page.getByTestId('blox-cta-background-control').locator('summary').click();
  await performPreviewUpdate(page, () => page.getByTestId('blox-cta-background-url').fill('/assets/images/demo/banner-2.svg'));
  await expect(cta).toHaveAttribute('style', /banner-2\.svg/);
  await waitPreviewSettled(page);
  expect(Math.abs((await canvasScrollTop(page)) - scroll)).toBeLessThan(8);
  await page.screenshot({ path: testInfo.outputPath('cta-background-panel.png') });
  const groups = page.getByTestId('blox-home-content-groups');
  expect(await groups.locator('button').evaluateAll(nodes => nodes.every(node => node.scrollWidth <= node.clientWidth + 1))).toBe(true);
  await page.getByTestId('blox-home-group-content').click();
  await expect(field(page, 'override_button_text').locator('input')).toHaveValue('Contact draft');
  await page.locator('input[x-model="ctrlQuery"]').fill('bg_image');
  await expect(page.getByTestId('blox-cta-background-media')).toBeVisible();
  await expect(groups).toHaveCount(0);
  await page.locator('input[x-model="ctrlQuery"]').fill('');
  await page.getByTestId('blox-modified-only').click();
  await expect(page.getByTestId('blox-cta-background-url')).toHaveValue('/assets/images/demo/banner-2.svg');
  await expect(groups).toHaveCount(0);
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});

test('structure field and column selection reveal their group without losing edits @ci', async ({ page }) => {
  const section = await openBlock(page, 'about');
  const original = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
  const structure = page.getByTestId('blox-mobile-structure');
  if (await structure.isVisible()) await structure.click();
  await section.locator('[data-home-field-tree$=".override_image"]').click();
  await expect(page.getByTestId('blox-home-group-media')).toHaveAttribute('aria-pressed', 'true');
  await expect(field(page, 'override_image')).toBeVisible();
  if (await structure.isVisible()) await structure.click();
  await section.locator('[data-home-column-tree$=".text"]').click();
  await expect(page.getByTestId('blox-home-group-content')).toHaveAttribute('aria-pressed', 'true');
  await expect(field(page, 'override_content')).toBeVisible();
  expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(original);
});

test('clicking the company image in the canvas opens its image settings @ci', async ({ page }) => {
  const errors = observeConsole(page), writes = observeUnsafeWrites(page);
  await openBlock(page, 'about');
  const canvasButton = page.getByTestId('blox-mobile-canvas-view');
  if (await canvasButton.isVisible()) await canvasButton.click();
  const image = (await frame(page)).locator('[data-yk-home-field="override_image"]').first();
  await image.evaluate(node => node.scrollIntoView({ block: 'center', behavior: 'instant' }));
  // Map frame-local coordinates through the editor's CSS zoom before a real mouse click.
  const point = await image.evaluate(node => {
    const rect = node.getBoundingClientRect();
    const host = window.frameElement.getBoundingClientRect();
    return { x: host.left + (rect.left + rect.width / 2) * host.width / window.innerWidth,
      y: host.top + (rect.top + rect.height / 2) * host.height / window.innerHeight };
  });
  await page.mouse.click(point.x, point.y);
  await expect(page.getByTestId('blox-home-group-media')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-about-image-media')).toBeVisible();
  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
