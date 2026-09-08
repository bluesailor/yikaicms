const { test, expect } = require('./site-diagnostics');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  restoreClean,
  expectClean,
  performPreviewUpdate,
  waitPreviewSettled,
} = require('./helpers');

const language = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
const locales = {
  en: {
    title: /^Blox Editor · Home$/,
    library: 'Element library',
    editHeader: 'Edit header',
    context: 'Header · Theme default',
    // 出厂首页已移除「价格方案」，改用同样三语齐全的「常见问题」自定义版块：
    // 本用例考的是本地化标题要显示、中文基底不能泄漏，换块不影响覆盖。
    customBlock: 'FAQ',
    customBlockBase: '常见问题',
  },
  ja: {
    title: /^Blox エディター · ホーム$/,
    library: '要素ライブラリ',
    editHeader: 'ヘッダーを編集',
    context: 'ヘッダー · テーマ標準',
    customBlock: 'よくある質問',
    customBlockBase: '常见问题',
  },
};

test('single-language homepage remains editable in Blox @language', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  test.skip(!locales[language], 'run with --lang=en or --lang=ja');

  const consoleEntries = observeConsole(page);
  const unsafeWrites = observeUnsafeWrites(page);
  const expected = locales[language];

  await openEditor(page);
  await expect(page).toHaveTitle(expected.title);
  await expect(page.getByText(expected.library).first()).toBeVisible();
  await expect(page.getByTestId('blox-tree')).toContainText(expected.customBlock);
  await expect(page.getByTestId('blox-tree')).not.toContainText(expected.customBlockBase);

  const contentFrame = await frame(page);
  expect(await contentFrame.locator('html').getAttribute('lang')).toBe(language);
  const headerContext = contentFrame.locator('[data-testid="blox-context-edit-header"]');
  await expect(headerContext).toHaveText(expected.editHeader);
  await expect(headerContext.locator('..')).toHaveAttribute('data-yk-preview-label', expected.context);

  await addTemporaryHeading(page);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  await restoreClean(page);

  expect(unsafeWrites, 'editing preview must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('single-language current header survives reopening and publishes to the anonymous homepage @language', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop persistent header lifecycle');
  test.skip(!locales[language], 'run with --lang=en or --lang=ja');
  test.setTimeout(90000);
  const marker = `E5 ${language} header ${Date.now()}`;
  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const visitor = await context.newPage();
  const publicErrors = observeConsole(visitor);
  visitor.on('response', response => { if (response.status() >= 500) publicErrors.push(String(response.status())); });
  async function publicHome(published) {
    expect((await visitor.goto('/')).status()).toBe(200);
    await expect(visitor.locator('html')).toHaveAttribute('lang', language);
    await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
    if (published) await expect(visitor.locator('.yk-blox-header').getByRole('heading', { name: marker, exact: true })).toBeVisible();
    else await expect(visitor.getByRole('heading', { name: marker, exact: true })).toHaveCount(0);
  }
  async function command(action, button) {
    const response = page.waitForResponse(candidate => {
      const body = new URLSearchParams(candidate.request().postData() || '');
      return new URL(candidate.url()).pathname === '/admin/blox_template_api.php' && body.get('action') === action;
    });
    await page.getByTestId(button).click();
    const result = await response;
    expect(result.status()).toBe(200);
    expect((await result.json()).code).toBe(0);
    await expectClean(page);
  }
  try {
    await publicHome(false);
    await openEditor(page);
    await waitPreviewSettled(page);
    const homeCanvas = await frame(page);
    const area = homeCanvas.locator('[data-yk-context-area="header"]');
    const entry = new URL(await area.getAttribute('data-yk-context-url'), baseURL);
    expect(entry.origin).toBe(new URL(baseURL).origin);
    expect(entry.searchParams.get('current_header')).toBe('1');
    const templateId = entry.searchParams.get('template');
    expect(templateId).toMatch(/^\d+$/);
    const navigated = page.waitForURL(entry.href);
    await homeCanvas.getByTestId('blox-context-edit-header').press('Enter');
    await navigated;
    await waitPreviewSettled(page);
    await expect((await frame(page)).locator('html')).toHaveAttribute('lang', language);
    await addTemporaryHeading(page);
    await performPreviewUpdate(page, () => page.locator('[data-control-key="text"] input[type="text"]').fill(marker));
    await command('save_draft', 'blox-save');
    // Saving leaves current-render mode so reopening must read the saved draft.
    const savedUrl = page.url();
    expect(new URL(savedUrl).searchParams.has('current_header')).toBe(false);
    await publicHome(false);
    await page.goto('/admin/blox_templates.php?type=header');
    await page.goto(savedUrl);
    await waitPreviewSettled(page);
    await expect((await frame(page)).getByRole('heading', { name: marker, exact: true })).toBeVisible();
    page.once('dialog', dialog => dialog.accept());
    await command('publish', 'blox-publish-template');
    await publicHome(true);
    await openEditor(page);
    const activeArea = (await frame(page)).locator('[data-yk-context-area="header"]');
    const activeEntry = new URL(await activeArea.getAttribute('data-yk-context-url'), baseURL);
    expect(activeEntry.searchParams.get('template')).toBe(templateId);
    expect(activeEntry.searchParams.has('current_header')).toBe(false);
    expect(publicErrors, 'anonymous header must load without errors').toEqual([]);
  } finally {
    await context.close();
  }
});
