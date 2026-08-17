const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  openPageEditor,
  performPagePreviewUpdate,
  restoreClean,
} = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(
  path.resolve(__dirname, '../smoke/fixtures.json'),
  'utf8'
));

test('page draft stays private until explicit publish @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single write-path baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  await openPageEditor(page, fixtures.blox_page);
  if (process.env.SMOKE_BLOX_ADVANCED === '0') {
    await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '0');
    await expect(page.getByTestId('blox-templates-open')).toHaveCount(0);
    await page.getByTestId('blox-design-open').click();
    await expect(page.getByTestId('blox-design-tab-colors')).toBeVisible();
    await expect(page.getByTestId('blox-design-token-row')).not.toHaveCount(0);
    await expect(page.getByTestId('blox-design-tab-styles')).toBeHidden();
    await page.keyboard.press('Escape');
  }
  await addTemporaryHeading(page);
  if (process.env.SMOKE_BLOX_ADVANCED === '0') {
    await expect(page.getByTestId('blox-condition-tab')).toBeHidden();
  }

  const marker = `R30 page publish ${Date.now()}`;
  const headingInput = page.locator('[data-control-key="text"] input[type="text"]').first();
  await expect(headingInput).toBeVisible();
  await performPagePreviewUpdate(page, () => headingInput.fill(marker));

  const saveResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'save_draft';
  });
  await page.getByTestId('blox-save').click();
  expect((await (await saveResponse).json()).code).toBe(0);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();

  const draftFrontend = await page.request.get(`${fixtures.blox_page_url}&preview=1`);
  expect(draftFrontend.ok()).toBe(true);
  expect(await draftFrontend.text()).not.toContain(marker);

  page.once('dialog', (dialog) => dialog.accept());
  const publishResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'publish';
  });
  await page.getByTestId('blox-publish-page').click();
  expect((await (await publishResponse).json()).code).toBe(0);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();

  const publishedFrontend = await page.request.get(`${fixtures.blox_page_url}&preview=1&v=${Date.now()}`);
  expect(publishedFrontend.ok()).toBe(true);
  expect(await publishedFrontend.text()).toContain(marker);
  expect(consoleEntries).toEqual([]);
});

test('legacy page editor and page list converge on Blox @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single navigation baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  await page.goto(`/admin/page_edit_advance.php?id=${fixtures.blox_page}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/admin/blox_editor\\.php\\?id=${fixtures.blox_page}$`));
  await expect(page.getByTestId('blox-canvas')).toBeVisible();

  await page.goto('/admin/page.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId(`page-primary-edit-${fixtures.blox_page}`))
    .toHaveAttribute('href', `/admin/blox_editor.php?id=${fixtures.blox_page}`);
});

test('standard page accordion has structured FAQ editing @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single interaction baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  await openPageEditor(page, fixtures.blox_page);
  const clearSelection = page.getByTestId('blox-clear-selection');
  if (await clearSelection.isVisible()) await clearSelection.click();

  const sectionsBefore = await page.getByTestId('blox-tree-section').count();
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(sectionsBefore + 1);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-accordion').click();

  const items = page.getByTestId('blox-accordion-item');
  await expect(page.getByTestId('blox-accordion-items')).toBeVisible();
  await expect(items).toHaveCount(2);

  const marker = `Structured FAQ ${Date.now()}`;
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-question').first().fill(marker));
  await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-add').click());
  await expect(items).toHaveCount(3);
  await expect(page.getByTestId('blox-accordion-move-up').first()).toBeDisabled();
  await expect(page.getByTestId('blox-accordion-move-down').last()).toBeDisabled();

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-accordion-delete').last().click());
  await expect(items).toHaveCount(2);
  expect(consoleEntries).toEqual([]);
});

test('section image background controls keep overlay and preview in sync @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop style-panel baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  await openPageEditor(page, fixtures.blox_page);
  await page.getByTestId('blox-tree-section').last().click();
  await page.getByTestId('blox-style-tab').click();

  const bgImage = page.getByTestId('blox-section-bg-image');
  await performPagePreviewUpdate(page, async () => {
    await bgImage.fill('/uploads/images/case-demo.jpg');
    await bgImage.blur();
  });
  await expect(page.getByTestId('blox-section-overlay-opacity')).toHaveValue('45');

  await performPagePreviewUpdate(page, async () => {
    await page.getByTestId('blox-section-min-height').selectOption('md');
    await page.getByTestId('blox-bg-position-top-right').click();
  });

  const renderedSection = (await frame(page)).locator('[data-yk-sec]').last();
  await expect(renderedSection).toHaveAttribute('style', /background-position:right top;.*min-height:480px;/);
  await expect(renderedSection.locator(':scope > [aria-hidden="true"]')).toHaveCSS('opacity', '0.45');

  await restoreClean(page);
  expect(consoleEntries).toEqual([]);
});

test('page editor switches between translated Blox documents @local', async ({ page }, testInfo) => {
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  const isMobile = testInfo.project.name === 'mobile-390';
  const chooseLanguage = async (language) => {
    if (isMobile) {
      await page.getByTestId('blox-mobile-actions-open').click();
      await page.getByTestId(`blox-mobile-language-${language}`).click();
      return;
    }
    await page.getByTestId(`blox-language-${language}`).click();
  };

  await openPageEditor(page, fixtures.blox_page);
  if (isMobile) {
    await expect(page.getByTestId('blox-language-switch')).toBeHidden();
    await expect(page.getByTestId('blox-mobile-actions')).toBeVisible();
  } else {
    await expect(page.getByTestId('blox-language-switch')).toBeVisible();
    await expect(page.getByTestId('blox-language-zh-CN')).toHaveAttribute('aria-current', 'page');
  }

  await chooseLanguage('en');
  await expect(page).toHaveURL(/\/admin\/blox_editor\.php\?id=28$/);
  if (!isMobile) {
    await expect(page.getByTestId('blox-language-en')).toHaveAttribute('aria-current', 'page');
  }
  await expect((await frame(page)).locator('[data-yk-sec]')).not.toHaveCount(0);

  await chooseLanguage('ja');
  await expect(page).toHaveURL(/\/admin\/blox_editor\.php\?id=53$/);
  if (!isMobile) {
    await expect(page.getByTestId('blox-language-ja')).toHaveAttribute('aria-current', 'page');
  }
  await expect((await frame(page)).locator('[data-yk-sec]')).not.toHaveCount(0);
  expect(consoleEntries).toEqual([]);
});
