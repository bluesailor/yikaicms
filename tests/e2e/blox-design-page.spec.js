const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('standalone design page mutates through the existing API contract @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design management interaction baseline');
  const consoleEntries = observeConsole(page);

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-design-page')).toBeVisible();
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  const before = await page.getByTestId('blox-design-page-token-row').count();
  const initial = await page.evaluate(() => {
    const data = window.Alpine.$data(document.querySelector('[data-testid="blox-design-page"]'));
    return JSON.parse(JSON.stringify(data.state));
  });

  const requests = [];
  await page.route('**/admin/blox_design_api.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    requests.push(Object.fromEntries(body.entries()));
    const next = JSON.parse(JSON.stringify(initial));
    next.revision += 1;
    next.tokens.push({
      id: 'c_e2e000000001',
      name: body.get('name'),
      category: body.get('category'),
      value: body.get('value'),
      status: 'active',
      locked: false,
      system: false,
      version: 1,
    });
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({ code: 0, msg: '', data: next }),
    });
  });

  await page.getByTestId('blox-design-page-new-token-name').fill('E2E Accent');
  await page.getByTestId('blox-design-page-new-token-color').click();
  await expect(page.getByTestId('blox-color-picker')).toBeVisible();
  await page.getByTestId('blox-color-picker-text').fill('#0d9488');
  await page.getByTestId('blox-color-picker-text').press('Enter');
  await page.keyboard.press('Escape');
  await page.getByTestId('blox-design-page-add-token').click();
  await expect(page.getByTestId('blox-design-page-token-row')).toHaveCount(before + 1);
  await expect(page.getByTestId('blox-design-page-token-row').last().locator('input[type="text"]').first()).toHaveValue('E2E Accent');
  expect(requests).toHaveLength(1);
  expect(requests[0].action).toBe('token_add');
  expect(requests[0].value).toBe('#0d9488');
  expect(requests[0].revision).toBe(String(initial.revision));
  expect(requests[0]._token).not.toBe('');
  expect(consoleEntries, 'standalone design management must keep the console clean').toEqual([]);
});

test('color picker remains usable inside every supported viewport @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-new-token-color').click();

  const picker = page.getByTestId('blox-color-picker');
  await expect(picker).toBeVisible();
  const box = await picker.boundingBox();
  const viewport = page.viewportSize();
  expect(box).not.toBeNull();
  expect(viewport).not.toBeNull();
  expect(box.x).toBeGreaterThanOrEqual(0);
  expect(box.y).toBeGreaterThanOrEqual(0);
  expect(box.x + box.width).toBeLessThanOrEqual(viewport.width);
  expect(box.y + box.height).toBeLessThanOrEqual(viewport.height);
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);

  const custom = page.getByTestId('blox-color-picker-text');
  await custom.fill('#zzzzzz');
  await custom.press('Enter');
  await expect(custom).toHaveAttribute('aria-invalid', 'true');
  await expect(custom).toHaveValue('#3b82f6');

  await page.keyboard.press('Escape');
  await expect(picker).toBeHidden();
  expect(consoleEntries, 'color picker must keep the console clean').toEqual([]);
});

test('page title design previews languages and responsive focus without overflow @ci', async ({ page }) => {
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-page-hero').click();

  const section = page.getByTestId('blox-design-page-hero');
  await expect(section).toBeVisible();
  const samples = await page.evaluate(() => {
    const data = window.Alpine.$data(document.querySelector('[data-testid="blox-design-page"]'));
    return Object.fromEntries(Object.entries(data.pageHeroSamples).map(([code, sample]) => [code, {
      language: sample.language,
      title: sample.title,
      description: sample.description,
      home: sample.home,
    }]));
  });
  expect(Object.keys(samples).sort()).toEqual(['en', 'ja', 'zh-CN']);
  expect(samples['zh-CN'].home).toBe('首页');
  expect(samples.en.home).toBe('Home');
  expect(samples.ja.home).toBe('ホーム');
  for (const sample of Object.values(samples)) {
    expect(sample.language).not.toBe('');
    expect(sample.title).not.toBe('');
  }

  await page.getByTestId('blox-design-page-hero-mobile').click();
  await expect(page.getByTestId('blox-design-page-hero-mobile')).toHaveAttribute('aria-pressed', 'true');
  const previewFrame = page.getByTestId('blox-design-page-hero-frame');
  await expect(previewFrame).toHaveAttribute('style', /max-width:\s*390px/);
  await expect.poll(() => previewFrame.evaluate((node) => node.offsetWidth)).toBeLessThanOrEqual(390);

  await section.locator('#blox-design-page-hero-bg').fill('/images/product-demo.jpg');
  await section.locator('#blox-design-page-hero-focus-x').fill('0');
  await section.locator('#blox-design-page-hero-focus-y').fill('100');
  const previewHero = section.locator('.relative.bg-cover').first();
  await expect(previewHero).toHaveAttribute('style', /background-position:\s*0% 100%/);

  await page.evaluate(() => {
    const data = window.Alpine.$data(document.querySelector('[data-testid="blox-design-page"]'));
    data.pageHeroSamples[data.pageHeroLanguage].title = '这是一个用于验证三百九十像素手机画布不会横向溢出的很长页面标题';
  });
  expect(await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth)).toBeLessThanOrEqual(1);
  expect(await previewHero.locator('h3').evaluate((node) => node.scrollWidth <= node.clientWidth + 1)).toBe(true);
  expect(consoleEntries, 'page title design preview must keep the console clean').toEqual([]);
});

// 2026-08-28：Blox 全部能力对免费版开放，命名样式不再受授权限制。
// 本用例改为守住「免费版拿得到完整设计系统」——锁态 UI 仍由 advanced 标记驱动，
// 保留在 blox_design.php 里以备日后重划边界，但在免费模式下不应出现。
test('free mode keeps the full design system available @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop free-mode capability baseline');
  test.skip(process.env.SMOKE_BLOX_ADVANCED !== '0', 'free-mode assertion');

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-design-page-colors')).toBeVisible();
  await expect(page.getByTestId('blox-design-page-add-token')).toBeVisible();
  await expect(page.getByTestId('blox-design-page-tab-styles')).toHaveAttribute('aria-disabled', 'false');
  await expect(page.getByTestId('blox-design-page-advanced-locked')).toBeHidden();
});
