const path = require('path');
const { test, expect } = require('@playwright/test');
const installMarketThemes = require('./theme-market-fixture');
const { frame, openEditor, waitPreviewSettled } = require('./helpers');

const root = path.resolve(__dirname, '../..');
let cleanup = () => {};

test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business', 'minimal']);
});

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

async function assertFooterEditorContext(page, theme, testInfo) {
  const compact = testInfo.project.name !== 'desktop-1440';
  if (compact) {
    const device = testInfo.project.name === 'mobile-390' ? 'mobile' : 'tablet';
    await page.getByTestId(`blox-device-${device}`).click();
    await waitPreviewSettled(page);
  }

  const contentFrame = await frame(page);
  const footer = contentFrame.locator('[data-yk-area="footer"]');
  const readOnlyHeader = contentFrame.locator('.yk-ctx-dim header').first();
  await expect(footer).toBeVisible();
  await expect(readOnlyHeader).toBeVisible();
  if (theme === 'business' || theme === 'minimal') {
    await expect(contentFrame.locator('.yk-ctx-dim #siteHeader')).toBeVisible();
  }

  const metrics = await contentFrame.evaluate((mobile) => {
    const header = document.querySelector('.yk-ctx-dim header');
    if (!header) return null;
    const rect = header.getBoundingClientRect();
    const visible = (node) => {
      const style = getComputedStyle(node);
      const box = node.getBoundingClientRect();
      return style.display !== 'none' && style.visibility !== 'hidden' && box.width > 0 && box.height > 0;
    };
    const menuButton = header.querySelector('#mobileMenuBtn, [data-yk-drawer-open]');
    const buttonRect = menuButton && visible(menuButton) ? menuButton.getBoundingClientRect() : null;
    const navRows = new Set(Array.from(header.querySelectorAll('nav a'))
      .filter(visible)
      .map((link) => Math.round(link.getBoundingClientRect().top)));
    return {
      mobile,
      viewportWidth: window.innerWidth,
      documentWidth: document.documentElement.scrollWidth,
      headerWidth: rect.width,
      headerHeight: rect.height,
      menuButton: buttonRect ? { width: buttonRect.width, height: buttonRect.height } : null,
      navRows: navRows.size,
    };
  }, compact);

  expect(metrics).not.toBeNull();
  expect(metrics.documentWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.headerWidth).toBeLessThanOrEqual(metrics.viewportWidth + 1);
  expect(metrics.headerHeight).toBeGreaterThanOrEqual(44);
  expect(metrics.headerHeight).toBeLessThanOrEqual(metrics.mobile ? 176 : 220);
  if (metrics.mobile) {
    expect(metrics.menuButton).not.toBeNull();
    expect(metrics.menuButton.width).toBeGreaterThanOrEqual(43);
    expect(metrics.menuButton.height).toBeGreaterThanOrEqual(43);
  } else {
    expect(metrics.navRows).toBeGreaterThan(0);
    expect(metrics.navRows).toBeLessThanOrEqual(2);
  }
}

test('default homepage opens its active Blox footer directly @ci', async ({ page }, testInfo) => {
  await activateTheme(page, 'default');
  await openEditor(page);

  const contentFrame = await frame(page);
  const footerArea = contentFrame.locator('[data-yk-context-area="footer"]');
  const footerEdit = contentFrame.getByTestId('blox-context-edit-footer');
  const footerUrl = new URL(
    await footerArea.getAttribute('data-yk-context-url'),
    page.url()
  ).href;

  expect(footerUrl).toMatch(/\/admin\/blox_editor\.php\?template=\d+&back=home$/);
  await Promise.all([
    page.waitForURL(footerUrl, { waitUntil: 'domcontentloaded' }),
    pointerClick(page, footerEdit),
  ]);

  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await assertFooterEditorContext(page, 'default', testInfo);
});

for (const slug of ['business', 'minimal']) {
  test(`${slug} homepage can open the footer editor @ci`, async ({ page }, testInfo) => {
    try {
      await activateTheme(page, slug);
      await openEditor(page);

      const contentFrame = await frame(page);
      const footerArea = contentFrame.locator('[data-yk-context-area="footer"]');
      const footerEdit = contentFrame.getByTestId('blox-context-edit-footer');
      await expect(footerEdit).toBeVisible();

      const footerUrl = new URL(
        await footerArea.getAttribute('data-yk-context-url'),
        page.url()
      ).href;
      expect(footerUrl).toMatch(/\/admin\/blox_editor\.php\?template=\d+&back=home$/);
      await Promise.all([
        page.waitForURL(footerUrl, { waitUntil: 'domcontentloaded' }),
        pointerClick(page, footerEdit),
      ]);

      await expect(page.getByTestId('blox-canvas')).toBeVisible();
      await assertFooterEditorContext(page, slug, testInfo);
    } finally {
      await activateTheme(page, 'default');
    }
  });
}

test('business secondary pages do not render the homepage CTA from the shared footer @ci', async ({ page }) => {
  try {
    await activateTheme(page, 'business');
    await page.goto('/news.html', { waitUntil: 'domcontentloaded' });
    await expect(page.getByText('准备好开始合作了吗？', { exact: true })).toHaveCount(0);
    await expect(page.locator('footer')).toBeVisible();
  } finally {
    await activateTheme(page, 'default');
  }
});
