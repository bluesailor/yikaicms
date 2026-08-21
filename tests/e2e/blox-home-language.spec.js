const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  restoreClean,
} = require('./helpers');

const language = process.env.BLOX_E2E_SITE_LANG || 'zh-CN';
const locales = {
  en: {
    title: /^Blox Editor · Home$/,
    library: 'Element library',
    editHeader: 'Edit header',
    context: 'Header · Theme default',
    pricing: 'Pricing Plans',
  },
  ja: {
    title: /^Blox エディター · ホーム$/,
    library: '要素ライブラリ',
    editHeader: 'ヘッダーを編集',
    context: 'ヘッダー · テーマ標準',
    pricing: '料金プラン',
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
  await expect(page.getByTestId('blox-tree')).toContainText(expected.pricing);
  await expect(page.getByTestId('blox-tree')).not.toContainText('价格方案');

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
