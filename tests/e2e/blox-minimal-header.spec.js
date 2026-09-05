const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');
const { frame, openEditor, waitPreviewSettled } = require('./helpers');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};
test.beforeAll(() => { cleanup = installMarketThemes(root, ['minimal']); });
test.afterAll(() => cleanup());

async function activateTheme(page, slug) {
  await page.goto('/admin/theme.php', { waitUntil: 'domcontentloaded' });
  if (await page.locator('main').getByText(`当前主题：${slug}`, { exact: false }).isVisible()) {
    return;
  }
  const form = page.locator(`form:has(input[name="action"][value="activate"]):has(input[name="slug"][value="${slug}"])`);
  await expect(form).toBeVisible();
  page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/theme.php'),
    form.getByRole('button').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.locator('main')).toContainText(`当前主题：${slug}`);
}

async function pointerClick(page, locator) {
  await expect(locator).toBeVisible();
  await locator.evaluate((element) => element.scrollIntoView({ block: 'center', inline: 'center' }));
  await page.waitForTimeout(50);
  try {
    await locator.click({ timeout: 1500 });
    return;
  } catch (_) {
    // The editor canvas is zoomed, so Playwright can misclassify iframe coordinates.
  }
  await locator.evaluate((element) => {
    const rect = element.getBoundingClientRect();
    const MouseEvent = element.ownerDocument.defaultView.MouseEvent;
    const options = {
      bubbles: true,
      cancelable: true,
      composed: true,
      clientX: rect.x + rect.width / 2,
      clientY: rect.y + rect.height / 2,
      view: element.ownerDocument.defaultView,
    };
    element.dispatchEvent(new MouseEvent('mousedown', options));
    element.dispatchEvent(new MouseEvent('mouseup', options));
    element.dispatchEvent(new MouseEvent('click', options));
  });
}

async function headerMetrics(page, headerSelector) {
  return page.evaluate((selector) => {
    const header = document.querySelector(selector);
    if (!header) return null;
    const rect = header.getBoundingClientRect();
    const visible = (node) => {
      const style = getComputedStyle(node);
      const box = node.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0;
    };
    const menuButton = header.querySelector('#mobileMenuBtn, [data-yk-drawer-open]');
    const buttonRect = menuButton && visible(menuButton) ? menuButton.getBoundingClientRect() : null;
    const logo = header.querySelector('a[href="/"] img, a[href="/"] span');
    const navRows = new Set(Array.from(header.querySelectorAll('nav a'))
      .filter(visible)
      .map((link) => Math.round(link.getBoundingClientRect().top)));
    return {
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      headerWidth: rect.width,
      headerHeight: rect.height,
      logoVisible: logo ? visible(logo) : false,
      menuButton: buttonRect ? { width: buttonRect.width, height: buttonRect.height } : null,
      navRows: navRows.size,
    };
  }, headerSelector);
}

test('minimal homepage keeps its native header editable as the current theme header @ci', async ({ page }, testInfo) => {
  const compact = testInfo.project.name !== 'desktop-1440';
  await activateTheme(page, 'minimal');
  await openEditor(page);
  await waitPreviewSettled(page);

  // Minimal 现已接入 bloxAreaHtml：自定义 Header 未开启/未命中时，
  // 首页编辑器的"编辑网页头"入口必须指向当前主题页头（current_header=1），
  // 不能回退成泛化的设计系统入口
  const contentFrame = await frame(page);
  const headerArea = contentFrame.locator('[data-yk-context-area="header"]');
  const headerEdit = contentFrame.getByTestId('blox-context-edit-header');
  await expect(headerEdit).toBeVisible();
  const headerUrl = new URL(
    await headerArea.getAttribute('data-yk-context-url'),
    page.url()
  ).href;
  expect(headerUrl).toMatch(/\/admin\/blox_editor\.php\?template=\d+&current_header=1&back=home&open=header-settings$/);

  if (compact) {
    // 紧凑画布不进入页头编辑器，仅校验入口指向正确
    await activateTheme(page, 'default');
    return;
  }

  await Promise.all([
    page.waitForURL(headerUrl, { waitUntil: 'domcontentloaded' }),
    pointerClick(page, headerEdit),
  ]);
  await expect(page.getByTestId('blox-canvas')).toBeVisible();

  // 编辑起点是 Minimal 原生页头的可编辑对应物：白底 + Logo/导航/抽屉
  const content = await frame(page);
  const canvasHeader = content.locator('[data-yk-area="header"]');
  await expect(canvasHeader).toBeVisible();
  await expect(canvasHeader.locator('header')).toContainText(/./);
  const canvasMetrics = await headerMetrics(content, '[data-yk-area="header"] header');
  expect(canvasMetrics.logoVisible).toBe(true);
  expect(canvasMetrics.documentWidth).toBeLessThanOrEqual(canvasMetrics.viewportWidth + 1);

  // 编辑完成后"返回首页编辑"可点击（未保存提醒由既有离开守卫负责）
  const back = page.getByTestId('blox-back');
  await expect(back).toBeVisible();
  expect(await back.getAttribute('href')).toBe('/admin/blox_editor.php?home=1');

  await activateTheme(page, 'default');
});

test('minimal frontend header is visible and does not overflow @ci', async ({ page }, testInfo) => {
  await activateTheme(page, 'minimal');
  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const header = page.locator('#siteHeader');
  await expect(header).toBeVisible();
  await expect(header.locator('nav').first()).toBeAttached();

  const metrics = await headerMetrics(page, '#siteHeader');
  expect(metrics).not.toBeNull();
  expect(metrics.logoVisible).toBe(true);
  expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.headerWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.headerHeight).toBeGreaterThanOrEqual(44);
  expect(metrics.headerHeight).toBeLessThanOrEqual(testInfo.project.name === 'mobile-390' ? 176 : 160);
  if (testInfo.project.name === 'mobile-390') {
    expect(metrics.menuButton).not.toBeNull();
    expect(metrics.menuButton.width).toBeGreaterThanOrEqual(43);
    expect(metrics.menuButton.height).toBeGreaterThanOrEqual(43);
  } else {
    expect(metrics.navRows).toBeGreaterThan(0);
    expect(metrics.navRows).toBeLessThanOrEqual(2);
  }

  await activateTheme(page, 'default');
});
