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
  openEditor,
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
    await expect(page.getByTestId('blox-templates-open')).toBeVisible();
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

test('free mode opens homepage and local templates while remote resolve stays locked @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single free-edition capability baseline');
  test.skip(process.env.SMOKE_BLOX_ADVANCED !== '0', 'free-mode assertion');

  const consoleEntries = observeConsole(page);
  await openEditor(page);
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '0');

  await page.getByTestId('blox-templates-open').click();
  await expect(page.getByTestId('blox-template-tab-local')).toHaveAttribute('aria-selected', 'true');
  await expect.poll(() => page.getByTestId('blox-template-item').count()).toBeGreaterThan(0);

  const response = await page.locator('body').evaluate(async (body) => {
    const state = window.Alpine.$data(body);
    const form = new URLSearchParams();
    form.set('action', 'get');
    form.set('context', 'page');
    form.set('key', 'remote:free-edition-boundary');
    form.set('_token', state.csrf);
    const request = await fetch('/admin/blox_template_api.php', { method: 'POST', body: form });
    return request.json();
  });
  expect(response.code).not.toBe(0);
  expect(String(response.msg || '')).not.toBe('');

  expect(fixtures.channel_list).toBeGreaterThan(0);
  await openPageEditor(page, fixtures.channel_list);
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '0');

  expect(fixtures.product_cat).toBeGreaterThan(0);
  await openPageEditor(page, fixtures.product_cat);
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '0');
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

test('stable section deep link selects the same persisted block @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop hover and locator baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  await openPageEditor(page, fixtures.blox_page);
  const targetTreeSection = page.getByTestId('blox-tree-section').last();
  const sectionId = await targetTreeSection.getAttribute('data-section-id');
  expect(sectionId).toBeTruthy();

  await page.goto(
    `/admin/blox_editor.php?id=${fixtures.blox_page}&focus_section=${encodeURIComponent(sectionId)}`,
    { waitUntil: 'domcontentloaded' }
  );
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.locator(`[data-testid="blox-tree-section"][data-section-id="${sectionId}"]`))
    .toHaveClass(/border-blue-400/);
  await expect((await frame(page)).locator(`[data-yk-sec-id="${sectionId}"]`)).toHaveClass(/yk-selected/);

  const frontendUrl = `${fixtures.blox_page_url}${fixtures.blox_page_url.includes('?') ? '&' : '?'}v=${Date.now()}`;
  await page.goto(frontendUrl, { waitUntil: 'domcontentloaded' });
  const frontendSection = page.locator('[data-yk-sec-id]').first();
  const frontendSectionId = await frontendSection.getAttribute('data-yk-sec-id');
  expect(frontendSectionId).toBeTruthy();
  await frontendSection.hover();
  await expect(page.locator('#yk-edit-btn')).toHaveAttribute(
    'href',
    `/admin/blox_editor.php?id=${fixtures.blox_page}&focus_section=${encodeURIComponent(frontendSectionId)}`
  );
  expect(consoleEntries).toEqual([]);
});

test('auto-redirect parent edits the page visitors actually see @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single navigation baseline');
  expect(fixtures.redirect_parent).toBeGreaterThan(0);
  expect(fixtures.redirect_target).toBeGreaterThan(0);

  const expectedEditor = `/admin/blox_editor.php?id=${fixtures.redirect_target}&from_parent=${fixtures.redirect_parent}`;
  await page.goto('/admin/page.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId(`page-redirect-target-${fixtures.redirect_parent}`))
    .toContainText(fixtures.redirect_target_name);
  await expect(page.getByTestId(`page-primary-edit-${fixtures.redirect_parent}`))
    .toHaveAttribute('href', expectedEditor);

  await page.goto(`/admin/blox_editor.php?id=${fixtures.redirect_parent}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(
    `/admin/blox_editor\\.php\\?id=${fixtures.redirect_target}&from_parent=${fixtures.redirect_parent}$`
  ));
  await expect(page.getByTestId('blox-redirect-source')).toBeVisible();
  await expect(page.getByTestId('blox-redirect-source')).toContainText(fixtures.redirect_parent_name);
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
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
    await bgImage.fill('/images/case-demo.jpg');
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
  // 编辑器页头在 <1024px 整体切移动布局（blox_editor.php 的 max-width:1023px 媒体查询隐藏
  // 语言切换与桌面动作条），断点要按视口宽度取——tablet-768 同样落在移动布局里，
  // 只认项目名 mobile-390 会让它去断言一个被 CSS 隐藏的元素可见。
  const isMobile = (testInfo.project.use?.viewport?.width ?? 1440) < 1024;
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
