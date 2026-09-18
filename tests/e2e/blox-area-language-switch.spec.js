/**
 * 网页头/网页脚编辑器顶栏可直接切到其它语言版本（与模板库多语言面板同一判定），
 * 有未保存修改时先确认。
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { addTemporaryHeading, observeConsole } = require('./helpers');

test('footer editor switches to another language version and guards unsaved edits @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop toolbar baseline');
  const consoleEntries = observeConsole(page);
  const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  const editorUrl = `/admin/blox_editor.php?template=${fixtures.blox_footer_template}&back=home`;
  await page.goto(editorUrl, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();

  const switcher = page.getByTestId('blox-area-language-switch');
  await expect(switcher).toBeVisible();
  const links = switcher.locator('a');
  expect(await links.count()).toBeGreaterThanOrEqual(2);
  await expect(switcher.locator('a[aria-current="page"]')).toHaveCount(1);
  const current = switcher.locator('a[aria-current="page"]');
  const target = switcher.locator('a:not([aria-current])').first();
  const targetHref = await target.getAttribute('href');
  expect(targetHref).toMatch(/area_lang=/);
  expect(await target.getAttribute('title')).toMatch(/ · /);
  for (const link of await links.all()) {
    expect(['draft', 'independent', 'advanced', 'default', 'inherit', 'preview', 'none']).toContain(await link.getAttribute('data-area-language-state'));
  }

  // 有未保存修改：取消确认则留在原页
  await addTemporaryHeading(page);
  await expect(page.getByTestId('blox-dirty')).toBeVisible();
  page.once('dialog', (dialog) => dialog.dismiss());
  await target.click();
  await expect(page).toHaveURL(new RegExp(`template=${fixtures.blox_footer_template}`));
  await expect(current).toHaveAttribute('aria-current', 'page');

  // 放弃修改后切换：进入目标语言（编辑器或模板库多语言面板）
  page.once('dialog', (dialog) => dialog.accept());
  const targetLanguage = new URL(targetHref, 'http://yikaicms.local').searchParams.get('area_lang');
  await Promise.all([
    page.waitForURL((url) => url.searchParams.get('area_lang') === targetLanguage),
    target.click(),
  ]);
  if (new URL(page.url()).pathname === '/admin/blox_editor.php') {
    await expect(page.getByTestId(`blox-area-language-${targetLanguage}`)).toHaveAttribute('aria-current', 'page');
  } else {
    await expect(page.getByTestId('blox-language-areas')).toBeVisible();
  }
  expect(consoleEntries).toEqual([]);
});
