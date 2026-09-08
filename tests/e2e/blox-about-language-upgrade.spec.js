const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');
const { openEditor, frame, waitPreviewSettled } = require('./helpers');

const fixture = (action) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'about-language-fixture.php'), action], { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' }).trim();
const languages = [
  ['zh-CN', '/', '关于企业', '为客户提供专业服务。', '品质与创新', '了解更多'],
  ['en', '/en/', 'About our company', 'Professional services for our customers.', 'Quality and innovation', 'Learn more'],
  ['ja', '/ja/', '私たちについて', 'お客様に専門的なサービスを提供します。', '品質と革新', '詳しく見る'],
];

async function publicLanguages(browser, baseURL, urls, testInfo) {
  const context = await browser.newContext({ baseURL, viewport: testInfo.project.use.viewport, storageState: { cookies: [], origins: [] } });
  const page = await context.newPage();
  const errors = [];
  page.on('pageerror', error => errors.push(String(error)));
  page.on('response', response => { if (response.status() >= 500) errors.push(`${response.status()} ${response.url()}`); });
  const hash = fixture('hash');
  const phpErrors = fixture('errors');
  try {
    for (const [lang, url, title, body, caption, button] of languages) {
      expect((await page.goto(url)).status()).toBe(200);
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      await expect(page.locator('#ik-adminbar')).toHaveCount(0);
      const heading = page.getByRole('heading', { name: title, exact: true });
      await expect(heading).toBeVisible();
      await expect(page.getByText(body, { exact: true })).toBeVisible();
      await expect(page.getByText(caption, { exact: true })).toBeVisible();
      await expect(page.getByRole('link', { name: button, exact: true }).first()).toHaveAttribute('href', urls[lang]);
      if (lang !== 'zh-CN') {
        await expect(page.getByText('为客户提供专业服务。', { exact: true })).toHaveCount(0);
      }
      await heading.scrollIntoViewIfNeeded();
      await page.screenshot({ path: testInfo.outputPath(`about-${lang}.png`) });
      await page.getByRole('link', { name: button, exact: true }).first().click();
      await expect(page.locator('html')).toHaveAttribute('lang', lang);
      expect(new URL(page.url()).pathname.startsWith(lang === 'zh-CN' ? '/' : `/${lang}/`)).toBe(true);
    }
    expect(fixture('hash')).toBe(hash);
    expect(fixture('errors')).toBe(phpErrors);
    expect(errors).toEqual([]);
  } finally { await context.close(); }
}

test.afterEach(() => fixture('restore'));

test('classic conversion publishes one ordinary layout with three localized contents @ci', async ({ page, browser, baseURL }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'Conversion toolbar exercised on desktop; anonymous language matrix runs on all viewports.');
  const urls = JSON.parse(fixture('legacy'));
  await openEditor(page);
  const section = page.getByTestId('blox-tree-section').first();
  await section.locator('[data-section-drag-handle]').first().click();
  await section.locator('[data-home-column-tree$=".text"]').click();
  await page.getByTestId('blox-convert-about').click();
  await expect(page.locator('[data-testid="blox-tree-element"][data-home-block-type="about"]')).toHaveCount(0);
  await waitPreviewSettled(page);
  await expect((await frame(page)).getByRole('heading', { name: '关于企业', exact: true })).toBeVisible();
  page.on('dialog', dialog => dialog.accept());
  const published = page.waitForResponse(response => response.url().includes('/blox_home_api.php') && new URLSearchParams(response.request().postData() || '').get('action') === 'publish');
  await page.getByTestId('blox-publish').click();
  expect((await (await published).json()).code).toBe(0);
  await openEditor(page);
  await expect((await frame(page)).getByRole('heading', { name: '关于企业', exact: true })).toBeVisible();
  await publicLanguages(browser, baseURL, urls, testInfo);
});

test('pre-fix published snapshot localizes without republishing or changing stored layout @ci', async ({ browser, baseURL }, testInfo) => {
  const urls = JSON.parse(fixture('converted'));
  await publicLanguages(browser, baseURL, urls, testInfo);
});
