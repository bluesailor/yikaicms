const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  expectClean,
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

test('page canvas includes the effective readonly header and footer @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop canvas context baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  await openPageEditor(page, fixtures.blox_page);
  const contentFrame = await frame(page);
  const headerArea = contentFrame.locator('[data-yk-context-area="header"]');
  const footerArea = contentFrame.locator('[data-yk-context-area="footer"]');
  const header = contentFrame.getByTestId('blox-context-edit-header');
  const footer = contentFrame.getByTestId('blox-context-edit-footer');

  await expect(header).toBeVisible();
  await expect(footer).toBeVisible();
  await expect(header).not.toHaveAttribute('href', /.+/);
  await expect(footer).not.toHaveAttribute('href', /.+/);
  await expect(headerArea).toHaveAttribute('data-yk-context-url', /\/admin\/(?:blox_editor\.php\?template=\d+|site_design\.php#site-design-area-header)/);
  await expect(footerArea).toHaveAttribute('data-yk-context-url', /\/admin\/(?:blox_editor\.php\?template=\d+|site_design\.php#site-design-area-footer)/);
  await expect(contentFrame.locator('.yk-home-context-area')).toHaveCount(2);
  await expectClean(page);
});

test('page draft stays private until explicit publish @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single write-path baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  const sourceUrl = `${fixtures.blox_page_url}&result_probe=${Date.now()}`;
  await page.goto(sourceUrl, { waitUntil: 'domcontentloaded' });
  const source = new URL(page.url());
  const sourceReturnTo = source.pathname + source.search;
  const editorHref = await page.locator('.ik-ab-page-edit').getAttribute('href');
  expect(editorHref).toBeTruthy();
  await page.goto(editorHref, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  if (process.env.SMOKE_BLOX_ADVANCED === '0') {
    // 2026-08-28 起 Blox 全部能力对免费版开放，免费模式下该标记同样是 1；
    // 仍受授权限制的只有远程模板包下载（见下方 free mode 用例）。
    await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '1');
    await expect(page.getByTestId('blox-prebuilt-open')).toBeVisible();
    await page.getByTestId('blox-design-open').click();
    await expect(page.getByTestId('blox-design-tab-colors')).toBeVisible();
    await expect(page.getByTestId('blox-design-token-row')).not.toHaveCount(0);
    // 全局命名样式随本次边界调整开放，免费模式下该页签同样可见
    await expect(page.getByTestId('blox-design-tab-styles')).toBeVisible();
    await page.keyboard.press('Escape');
  }
  await addTemporaryHeading(page);
  if (process.env.SMOKE_BLOX_ADVANCED === '0') {
    // 显示条件（Query Loop 高级数据能力的一部分）随本次边界调整开放
    await expect(page.getByTestId('blox-condition-tab')).toBeVisible();
  }

  const marker = `R30 page publish ${Date.now()}`;
  const headingInput = page.locator('[data-control-key="text"] input[type="text"]').first();
  await expect(headingInput).toBeVisible();
  await performPagePreviewUpdate(page, () => headingInput.fill(marker));

  const draftSummaryOpen = page.getByTestId('blox-draft-summary-open');
  const draftSummaryPanel = page.getByTestId('blox-draft-summary-panel');
  await expect(draftSummaryOpen).toBeVisible();
  await draftSummaryOpen.click();
  await expect(draftSummaryPanel).toBeVisible();
  await expect(draftSummaryPanel.getByTestId('blox-draft-summary-item')).not.toHaveCount(0);
  await draftSummaryPanel.locator('[data-testid="blox-draft-summary-locate"]:visible').first().click();
  await expect(draftSummaryPanel).toBeHidden();

  await page.route('**/admin/blox_page_api.php*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ code: 1, msg: 'Injected save failure' }),
  }), { times: 1 });
  const failedSaveResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'save_draft';
  });
  await page.getByTestId('blox-save').click();
  expect((await (await failedSaveResponse).json()).code).toBe(1);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  expect(await page.getByTestId('blox-back').getAttribute('href')).not.toContain('yk_edit_receipt');

  const saveResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'save_draft';
  });
  await page.getByTestId('blox-save').click();
  const saveResult = await (await saveResponse).json();
  expect(saveResult.code).toBe(0);
  expect(saveResult.data.return_receipt).toMatch(/^[a-f0-9]{48}$/);
  await expectClean(page);
  await expect(draftSummaryOpen).toBeVisible();

  const back = page.getByTestId('blox-back');
  const draftBack = new URL(await back.getAttribute('href'), 'http://yikaicms.local');
  expect(draftBack.searchParams.get('yk_edit_receipt')).toBe(saveResult.data.return_receipt);

  const draftFrontend = await page.request.get(`${fixtures.blox_page_url}&preview=1`);
  expect(draftFrontend.ok()).toBe(true);
  expect(await draftFrontend.text()).not.toContain(marker);

  await Promise.all([
    page.waitForURL((url) => url.pathname + url.search === sourceReturnTo),
    back.click(),
  ]);
  await expect(page.getByTestId('frontend-return-focus-status'))
    .toHaveText('草稿已保存，前台仍显示已发布版本');
  await expect(page.getByTestId('frontend-return-focus-status')).toHaveClass(/is-draft/);
  expect(page.url()).not.toContain('yk_edit_receipt');

  const unpublished = page.getByTestId('admin-unpublished-changes');
  const pageDraftItem = unpublished.locator('[data-draft-kind="page"]');
  await expect(unpublished).toBeVisible();
  await unpublished.locator('summary').click();
  await expect(pageDraftItem).toBeVisible();
  await expect(pageDraftItem.getByText('继续编辑')).toHaveAttribute('href', editorHref);
  const draftPreviewHref = await pageDraftItem.getByText('预览草稿').getAttribute('href');
  expect(draftPreviewHref).toBeTruthy();
  const draftPreviewUrl = new URL(draftPreviewHref, 'http://yikaicms.local');
  expect(draftPreviewUrl.searchParams.get('preview')).toBe('draft');
  expect(draftPreviewUrl.searchParams.get('blox_draft')).toBe(`page:${fixtures.blox_page}`);

  for (const width of [1440, 768, 390]) {
    await page.setViewportSize({ width, height: width === 390 ? 844 : 900 });
    const adminBarBox = await page.locator('#ik-adminbar').boundingBox();
    const draftMenuBox = await unpublished.locator('.ik-ab-draft-menu').boundingBox();
    expect(adminBarBox).not.toBeNull();
    expect(draftMenuBox).not.toBeNull();
    expect(adminBarBox.x + adminBarBox.width).toBeLessThanOrEqual(width + 1);
    expect(draftMenuBox.x).toBeGreaterThanOrEqual(0);
    expect(draftMenuBox.x + draftMenuBox.width).toBeLessThanOrEqual(width + 1);
  }

  await page.goto(draftPreviewHref, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-draft-preview-bar')).toBeVisible();
  await expect(page.getByTestId('blox-draft-preview-bar')).toContainText('正在预览草稿');
  await expect(page.locator('#ik-adminbar')).toHaveCount(0);
  expect(await page.content()).toContain(marker);
  await page.getByText('退出预览').click();
  await page.waitForLoadState('domcontentloaded');
  expect(page.url()).not.toContain('preview=draft');
  expect(page.url()).not.toContain('blox_draft');
  expect(await page.content()).not.toContain(marker);
  await page.setViewportSize({ width: 1440, height: 900 });

  const publishTarget = page.locator('[data-yk-sec-id]').first();
  const publishSectionId = await publishTarget.getAttribute('data-yk-sec-id');
  expect(publishSectionId).toBeTruthy();
  await publishTarget.hover();
  const draftEditorHref = await page.locator('#yk-edit-btn').getAttribute('href');
  expect(draftEditorHref).toBeTruthy();
  expect(draftEditorHref).not.toContain('yk_edit_receipt');
  await page.goto(draftEditorHref, { waitUntil: 'domcontentloaded' });
  await expect((await frame(page)).getByText(marker, { exact: true })).toBeVisible();

  await page.route('**/admin/blox_page_api.php*', (route) => route.fulfill({
    status: 200,
    contentType: 'application/json',
    body: JSON.stringify({ code: 1, msg: 'Injected publish failure' }),
  }), { times: 1 });
  page.once('dialog', (dialog) => dialog.accept());
  const failedPublishResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'publish';
  });
  await page.getByTestId('blox-publish-page').click();
  expect((await (await failedPublishResponse).json()).code).toBe(1);
  expect(await page.getByTestId('blox-back').getAttribute('href')).not.toContain('yk_edit_receipt');

  page.once('dialog', (dialog) => dialog.accept());
  const publishResponse = page.waitForResponse((candidate) => {
    const url = new URL(candidate.url());
    const body = new URLSearchParams(candidate.request().postData() || '');
    return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'publish';
  });
  await page.getByTestId('blox-publish-page').click();
  const publishResult = await (await publishResponse).json();
  expect(publishResult.code).toBe(0);
  expect(publishResult.data.return_receipt).toMatch(/^[a-f0-9]{48}$/);
  await expectClean(page);
  await expect(draftSummaryOpen).toBeHidden();

  const publishedBack = new URL(await page.getByTestId('blox-back').getAttribute('href'), 'http://yikaicms.local');
  expect(publishedBack.searchParams.get('yk_edit_receipt')).toBe(publishResult.data.return_receipt);
  await Promise.all([
    page.waitForURL((url) => url.pathname + url.search === sourceReturnTo),
    page.getByTestId('blox-back').click(),
  ]);
  await expect(page.getByTestId('frontend-return-focus-status')).toHaveText('已发布，并返回到修改位置');
  await expect(page.getByTestId('frontend-return-focus-status')).toHaveClass(/is-published/);
  await expect(page.locator(`[data-yk-sec-id="${publishSectionId}"]`).first()).toHaveClass(/yk-return-focus/);
  await expect(page.locator('[data-draft-kind="page"]')).toHaveCount(0);
  expect(page.url()).not.toContain('yk_edit_receipt');
  expect(await page.content()).toContain(marker);

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
  // Blox 能力对免费版全开，标记为 1；本用例现在验的是「免费版拿得到全部编辑能力，
  // 但远程模板解析仍被授权闸挡住」。
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '1');

  await page.getByTestId('blox-prebuilt-open').click();
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
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '1');

  expect(fixtures.product_cat).toBeGreaterThan(0);
  await openPageEditor(page, fixtures.product_cat);
  await expect(page.locator('body')).toHaveAttribute('data-blox-advanced', '1');
  expect(consoleEntries).toEqual([]);
});

test('legacy page editor and page list converge on Blox @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single navigation baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  await page.goto(`/admin/page_edit_advance.php?id=${fixtures.blox_page}`, { waitUntil: 'domcontentloaded' });
  await expect(page).toHaveURL(new RegExp(`/admin/blox_editor\\.php\\?id=${fixtures.blox_page}$`));
  await expect(page.getByTestId('blox-canvas')).toBeVisible();

  await page.goto('/admin/page.php', { waitUntil: 'domcontentloaded' });
  const homeRow = page.getByTestId('page-home-row');
  await expect(homeRow.locator('a[href="/admin/setting_home.php"]')).toHaveCount(0);
  await expect(homeRow.getByTestId('page-home-edit'))
    .toHaveAttribute('href', '/admin/blox_editor.php?home=1');
  await expect(homeRow.getByTestId('page-home-edit')).toHaveText('编辑首页');
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
  const frontendTarget = new URL(frontendUrl, 'http://yikaicms.local');
  const frontendReturnTo = frontendTarget.pathname + frontendTarget.search;
  await page.goto(frontendUrl, { waitUntil: 'domcontentloaded' });
  const frontendSection = page.locator('[data-yk-sec-id]').first();
  const frontendSectionId = await frontendSection.getAttribute('data-yk-sec-id');
  const frontendSectionLabel = await frontendSection.getAttribute('data-yk-sec-label');
  expect(frontendSectionId).toBeTruthy();
  expect(frontendSectionLabel).toBeTruthy();
  const focusedFrontendReturn = new URL(frontendReturnTo, 'http://yikaicms.local');
  focusedFrontendReturn.searchParams.set('yk_focus_section', frontendSectionId);
  const focusedFrontendReturnTo = focusedFrontendReturn.pathname + focusedFrontendReturn.search;
  await frontendSection.hover();
  await expect(page.locator('#yk-edit-btn')).toHaveAttribute(
    'href',
    `/admin/blox_editor.php?id=${fixtures.blox_page}&return_to=${encodeURIComponent(focusedFrontendReturnTo)}&focus_section=${encodeURIComponent(frontendSectionId)}`
  );
  await page.goto(
    `/admin/blox_editor.php?id=${fixtures.blox_page}&focus_section=${encodeURIComponent(frontendSectionId)}`,
    { waitUntil: 'domcontentloaded' }
  );
  const focusedTreeSection = page.locator(
    `[data-testid="blox-tree-section"][data-section-id="${frontendSectionId}"]`,
  );
  await expect(focusedTreeSection).toHaveClass(/border-blue-400/);
  await expect(focusedTreeSection).toHaveAttribute('data-section-label', frontendSectionLabel);
  await expect(focusedTreeSection.getByTestId('blox-tree-section-label')).toHaveText(frontendSectionLabel);
  await expect(focusedTreeSection.getByTestId('blox-tree-section-label')).toHaveAttribute('title', frontendSectionLabel);
  expect(consoleEntries).toEqual([]);
});

test('frontend return target preserves source and guards unsaved edits @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single return-navigation baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  const sourceUrl = `${fixtures.blox_page_url}${fixtures.blox_page_url.includes('?') ? '&' : '?'}return_probe=${Date.now()}`;
  const source = new URL(sourceUrl, 'http://yikaicms.local');
  const returnTo = source.pathname + source.search;
  const editorBase = `/admin/blox_editor.php?id=${fixtures.blox_page}&return_to=${encodeURIComponent(returnTo)}`;

  await page.goto(sourceUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.locator('.ik-ab-page-edit')).toHaveAttribute('href', editorBase);
  const section = page.locator('[data-yk-sec-id]').first();
  const sectionId = await section.getAttribute('data-yk-sec-id');
  const sectionLabel = await section.getAttribute('data-yk-sec-label');
  expect(sectionId).toBeTruthy();
  expect(sectionLabel).toBeTruthy();
  await section.hover();
  const focusedReturn = new URL(returnTo, 'http://yikaicms.local');
  focusedReturn.searchParams.set('yk_focus_section', sectionId);
  const focusedReturnTo = focusedReturn.pathname + focusedReturn.search;
  const preciseEditorUrl = `/admin/blox_editor.php?id=${fixtures.blox_page}&return_to=${encodeURIComponent(focusedReturnTo)}&focus_section=${encodeURIComponent(sectionId)}`;
  await expect(page.locator('#yk-edit-btn')).toHaveAttribute('href', preciseEditorUrl);

  await page.goto(preciseEditorUrl, { waitUntil: 'domcontentloaded' });
  const back = page.getByTestId('blox-back');
  await expect(back).toHaveAttribute('href', focusedReturnTo);
  await expect(back).toContainText('返回页面');
  await expect(page.locator(`[data-testid="blox-tree-section"][data-section-id="${sectionId}"]`))
    .toHaveClass(/border-blue-400/);

  const sectionName = page.getByTestId('blox-section-name');
  const originalName = await sectionName.inputValue();
  await performPagePreviewUpdate(page, async () => {
    await sectionName.fill(`Return guard ${Date.now()}`);
    await sectionName.blur();
  });
  await expect(page.getByTestId('blox-dirty')).toBeVisible();

  let leaveConfirmSeen = false;
  page.once('dialog', async (dialog) => {
    leaveConfirmSeen = dialog.type() === 'confirm' && dialog.message().includes('未保存');
    await dialog.dismiss();
  });
  await back.click();
  expect(leaveConfirmSeen).toBe(true);
  await expect(page).toHaveURL(new RegExp('/admin/blox_editor\\.php'));

  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(sectionName).toHaveValue(originalName);
  await expectClean(page);
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await Promise.all([
    page.waitForURL((url) => url.pathname + url.search === returnTo),
    back.click(),
  ]);
  const returnedSection = page.locator(`[data-yk-sec-id="${sectionId}"]`).first();
  await expect(returnedSection).toHaveClass(/yk-return-focus/);
  await expect(page.getByTestId('frontend-return-focus-status')).toHaveText(`已返回：${sectionLabel}`);
  await expect(returnedSection).toHaveCSS('animation-name', 'none');
  expect(page.url()).not.toContain('yk_focus_section');

  await page.goto(`${returnTo}&yk_focus_section=missing-${Date.now()}`, { waitUntil: 'domcontentloaded' });
  await page.waitForURL((url) => url.pathname + url.search === returnTo);
  await expect(page.getByTestId('frontend-return-focus-status')).toHaveCount(0);

  for (const malicious of [
    'https://evil.example/page',
    '//evil.example/page',
    '/admin/users.php',
    '/%61dmin/users.php',
    '/service/../admin/users.php',
  ]) {
    const response = await page.request.get(
      `/admin/blox_editor.php?id=${fixtures.blox_page}&return_to=${encodeURIComponent(malicious)}`,
    );
    expect(response.ok()).toBe(true);
    expect(await response.text()).toContain('href="/admin/page.php" data-testid="blox-back"');
  }
  expect(consoleEntries).toEqual([]);
});

test('custom section name survives history and save before returning to automatic label @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'single section-name write-path baseline');
  expect(fixtures.blox_page).toBeGreaterThan(0);

  const consoleEntries = observeConsole(page);
  await openPageEditor(page, fixtures.blox_page);
  const treeSection = page.getByTestId('blox-tree-section').first();
  const sectionId = await treeSection.getAttribute('data-section-id');
  expect(sectionId).toBeTruthy();
  await treeSection.click();

  const input = page.getByTestId('blox-section-name');
  const originalName = await input.inputValue();
  const automaticName = await page.getByTestId('blox-section-auto-name').textContent();
  expect(automaticName).toBeTruthy();

  const marker = `价格<1000元 ${Date.now()}`;
  await performPagePreviewUpdate(page, async () => {
    await input.fill(`  价格<1000元\u200B\n${marker.split(' ').pop()}  `);
    await input.blur();
  });
  await expect(input).toHaveValue(marker);
  await expect(treeSection.getByTestId('blox-tree-section-label')).toContainText(marker);

  await expect(page.getByTestId('blox-undo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-undo').click());
  await expect(input).toHaveValue(originalName);
  await expect(page.getByTestId('blox-redo')).toBeEnabled();
  await performPagePreviewUpdate(page, () => page.getByTestId('blox-redo').click());
  await expect(input).toHaveValue(marker);

  const saveDraft = async () => {
    const response = page.waitForResponse((candidate) => {
      const url = new URL(candidate.url());
      const body = new URLSearchParams(candidate.request().postData() || '');
      return url.pathname === '/admin/blox_page_api.php' && body.get('action') === 'save_draft';
    });
    await page.getByTestId('blox-save').click();
    expect((await (await response).json()).code).toBe(0);
    await expectClean(page);
  };

  await saveDraft();
  await page.goto(
    `/admin/blox_editor.php?id=${fixtures.blox_page}&focus_section=${encodeURIComponent(sectionId)}`,
    { waitUntil: 'domcontentloaded' }
  );
  await expect(page.getByTestId('blox-section-name')).toHaveValue(marker);

  await performPagePreviewUpdate(page, () => page.getByTestId('blox-section-name-reset').click());
  await expect(page.getByTestId('blox-section-name')).toHaveValue('');
  await expect(page.locator(`[data-testid="blox-tree-section"][data-section-id="${sectionId}"]`))
    .toHaveAttribute('data-section-label', automaticName);
  await saveDraft();

  await page.goto(
    `/admin/blox_editor.php?id=${fixtures.blox_page}&focus_section=${encodeURIComponent(sectionId)}`,
    { waitUntil: 'domcontentloaded' }
  );
  await expect(page.getByTestId('blox-section-name')).toHaveValue('');
  await expect(page.getByTestId('blox-section-auto-name')).toHaveText(automaticName);

  if (originalName) {
    await performPagePreviewUpdate(page, async () => {
      await page.getByTestId('blox-section-name').fill(originalName);
      await page.getByTestId('blox-section-name').blur();
    });
    await saveDraft();
  }
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
  await page.getByTestId('blox-add-element-accordion').press('Enter');

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
  // 编辑器页头在 <1200px 切紧凑操作菜单（blox_editor.php 的 max-width:1199px 媒体查询隐藏
  // 语言切换与桌面动作条），断点要按视口宽度取——tablet-768 同样落在移动布局里，
  // 只认项目名 mobile-390 会让它去断言一个被 CSS 隐藏的元素可见。
  const isMobile = (testInfo.project.use?.viewport?.width ?? 1440) < 1200;
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
