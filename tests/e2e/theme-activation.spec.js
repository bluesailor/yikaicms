const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};

test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business']);
});

test.afterAll(() => cleanup());

test('activating a local theme redirects to its fresh active state @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one focused activation check is sufficient');

  await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
  const business = page.getByTestId('theme-local-list').locator('[data-theme-slug="business"]');
  const defaultTheme = page.getByTestId('theme-local-list').locator('[data-theme-slug="default"]');
  // 2.0.5（RFC-1）：启用按钮打开对话框，并列当前配色与主题配色，默认保留当前配色
  const activate = async (card, colors = 'keep') => {
    await card.getByTestId('theme-activate').click();
    const dialog = card.getByTestId('theme-switch-dialog');
    await expect(dialog).toBeVisible();
    await dialog.getByTestId(`theme-switch-colors-${colors}`).check();
    await Promise.all([
      page.waitForURL((url) => url.pathname === '/admin/theme.php' && url.search === ''),
      dialog.getByTestId('theme-switch-confirm').click(),
    ]);
  };
  // 前台主题头里内联的主色（后台页面不输出它）
  const primary = async () => ((await (await page.request.get('/')).text()).match(/--color-primary:\s*(#[0-9a-fA-F]{6})/) || [])[1]?.toUpperCase();
  const before = await primary();

  try {
    await activate(business);
    await expect(page.locator('body')).toContainText('business');
    await expect(business).toHaveClass(/ring-2/);
    await expect(business.getByTestId('theme-activate')).toHaveCount(0);
    expect(await primary(), '默认保留当前配色').toBe(before);
  } finally {
    if (await defaultTheme.getByTestId('theme-activate').count()) {
      await activate(defaultTheme, 'theme');
    }
  }
});

test('legacy theme fonts stay read-only and can be cleared @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'one focused settings check is sufficient');

  // 2.0.5（RFC-1 决策 2）：字体统一到「外观 → 字体」；旧外观设置里的字体只读、照旧生效，确认后可清除
  const settings = page.locator('form[action="/admin/theme.php?tab=settings"]');
  const legacy = page.getByTestId('theme-legacy-fonts');
  const front = async () => (await page.request.get('/?e2e_fonts=' + Date.now())).text();
  await page.goto('/admin/theme.php?tab=settings', { waitUntil: 'domcontentloaded' });
  await expect(legacy).toBeVisible();
  await expect(settings.locator('input[type="text"][name="theme_style[typography][body_font]"]')).toHaveCount(0);

  // 模拟老站：旧版本在这里存过正文字体。隐藏字段随设置表单原样提交，保存其他设置不会把它清掉
  await settings.locator('input[name="theme_style[typography][body_font]"]').evaluate((input) => { input.value = 'Georgia, serif'; });
  await Promise.all([
    page.waitForLoadState('domcontentloaded'),
    settings.locator('button[type="submit"]').last().click(),
  ]);
  await page.goto('/admin/theme.php?tab=settings', { waitUntil: 'domcontentloaded' });
  await expect(legacy).toContainText('Georgia, serif');
  expect(await front()).toContain('Georgia, serif');

  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForURL((url) => url.pathname === '/admin/theme.php'),
    legacy.getByTestId('theme-clear-legacy-fonts').click(),
  ]);
  await page.goto('/admin/theme.php?tab=settings', { waitUntil: 'domcontentloaded' });
  await expect(legacy).not.toContainText('Georgia, serif');
  await expect(legacy.getByTestId('theme-clear-legacy-fonts')).toHaveCount(0);
  expect(await front()).not.toContain('Georgia, serif');
});
