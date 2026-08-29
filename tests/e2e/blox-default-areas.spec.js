const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { addTemporaryHeading, observeConsole, performPreviewUpdate } = require('./helpers');

const AREA_TEMPLATES = [
  { slug: 'corporate-site-header', name: 'Corporate Site Header' },
  { slug: 'corporate-site-footer', name: 'Corporate Site Footer' },
];

async function submit(page, form) {
  // waitForNavigation 已废弃且有竞态（重定向落在同 URL 时可能挂满 45s，CI 偶发）。
  // 先武装 POST 响应等待再点击，然后等重定向落地——时序上不可能错过。
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

async function installAndPublish(page, template) {
  const installForm = page.locator('form').filter({
    has: page.locator(`input[name="slug"][value="${template.slug}"]`),
  });
  await expect(installForm).toHaveCount(1);
  await submit(page, installForm);

  const rows = page.locator('tbody tr').filter({ hasText: template.name });
  await expect(rows.first()).toBeVisible();
  if (await rows.locator('form:has(input[name="action"][value="unpublish"])').count()) return;
  const publishForm = rows.locator('form:has(input[name="action"][value="publish"])').first();
  await expect(publishForm).toHaveCount(1);
  await submit(page, publishForm);
}

async function unpublishAreas(page) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  for (const template of AREA_TEMPLATES) {
    const rows = page.locator('tbody tr').filter({ hasText: template.name });
    if (!await rows.first().isVisible().catch(() => false)) continue;
    const form = rows.locator('form:has(input[name="action"][value="unpublish"])').first();
    if (await form.count()) await submit(page, form);
  }
}

test('published default corporate areas stay responsive @ci', async ({ page }, testInfo) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
  const consoleEntries = observeConsole(page);
  try {
    await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
    for (const template of AREA_TEMPLATES) await installAndPublish(page, template);

    const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
    await page.goto(`${fixtures.blox_page_url}&preview=1`, { waitUntil: 'networkidle' });
    await page.evaluate(() => document.fonts && document.fonts.ready);

    const header = page.locator('.yk-blox-header');
    const footer = page.locator('.yk-blox-footer');
    await expect(header).toBeVisible();
    await expect(footer).toBeVisible();
    const utilitySearch = header.locator('section form[role="search"] input[name="keyword"]').first();
    const languageSwitcher = header.locator('[data-yk-language-switcher="dropdown"]');
    const languageTrigger = languageSwitcher.locator('[data-yk-language-trigger]');
    const languageMenu = languageSwitcher.locator('[data-yk-language-menu]');
    if (testInfo.project.name === 'mobile-390') {
      await expect(utilitySearch).toBeHidden();
      await expect(languageTrigger).toBeHidden();
    } else {
      await expect(utilitySearch).toBeVisible();
      await expect(languageTrigger).toBeVisible();
      await expect(languageMenu).toBeHidden();
      await languageTrigger.click();
      await expect(languageMenu).toBeVisible();
      await expect(languageMenu.locator('a[hreflang]')).toHaveCount(3);
      await expect(languageMenu.locator('a[aria-current="page"]')).toHaveCount(1);
      const menuBox = await languageMenu.boundingBox();
      const viewport = page.viewportSize();
      expect(menuBox).not.toBeNull();
      expect(menuBox.x).toBeGreaterThanOrEqual(0);
      expect(menuBox.x + menuBox.width).toBeLessThanOrEqual(viewport.width + 1);
      await page.locator('main').click({ position: { x: 1, y: 1 } });
      await expect(languageMenu).toBeHidden();
      await languageTrigger.click();
      await languageMenu.locator('a[aria-current="page"]').focus();
      await page.keyboard.press('Escape');
      await expect(languageMenu).toBeHidden();
      await expect(languageTrigger).toBeFocused();
    }
    await expect(header).toHaveAttribute('data-yk-sticky-behavior', 'always');
    await expect(header).toHaveAttribute('data-yk-sticky-desktop', '1');
    await expect(header).toHaveAttribute('data-yk-sticky-tablet', '1');
    await expect(header).toHaveAttribute('data-yk-sticky-mobile', '1');
    const phone = footer.locator('a[href^="tel:"]');
    await expect(phone).toBeVisible();
    await expect(phone).not.toHaveText('');
    await expect(footer).toContainText(String(new Date().getFullYear()));

    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(header).toHaveClass(/yk-stuck/);
    await expect(header).not.toHaveClass(/yk-sticky-hidden/);
    await header.evaluate((element) => element.setAttribute('data-yk-sticky-behavior', 'scroll-up'));
    await page.evaluate(() => window.scrollTo(0, 0));
    await expect(header).not.toHaveClass(/yk-stuck/);
    await page.evaluate(() => window.scrollTo(0, document.documentElement.scrollHeight));
    await expect(header).toHaveClass(/yk-sticky-hidden/);
    await page.evaluate(() => window.scrollBy(0, -200));
    await expect(header).not.toHaveClass(/yk-sticky-hidden/);
    await header.evaluate((element) => element.setAttribute('data-yk-sticky-behavior', 'always'));
    await page.evaluate(() => window.scrollTo(0, 0));

    const mega = header.locator('.yk-mega');
    const drawer = header.locator('[data-yk-nav-drawer]');
    if (testInfo.project.name === 'desktop-1440') {
      await expect(mega).toBeVisible();
      await expect(drawer).toBeHidden();
    } else {
      await expect(mega).toBeHidden();
      await expect(drawer).toBeVisible();
      const drawerOpen = drawer.locator('[data-yk-drawer-open]');
      const drawerPanel = drawer.locator('[data-yk-drawer-panel]');
      await expect(drawerOpen).toBeVisible();
      await expect(drawerOpen).toHaveAttribute('aria-expanded', 'false');
      const triggerBox = await drawerOpen.boundingBox();
      expect(triggerBox.width).toBeGreaterThanOrEqual(44);
      expect(triggerBox.height).toBeGreaterThanOrEqual(44);
      await drawerOpen.click();
      await expect(drawerPanel).toBeVisible();
      await expect(drawerPanel).toHaveAttribute('aria-hidden', 'false');
      await expect(drawerPanel.locator('form[role="search"]')).toBeVisible();
      await expect(drawerPanel.locator('[data-yk-language-switcher="inline"]')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(drawerPanel).toBeHidden();
      await expect(drawerOpen).toHaveAttribute('aria-expanded', 'false');
      await expect(drawerOpen).toBeFocused();
    }

    if (testInfo.project.name !== 'tablet-768') {
      const visualOptions = { threshold: 0.3, maxDiffPixelRatio: 0.05 };
      const expectedHeaderHeight = testInfo.project.name === 'desktop-1440' ? 149 : 78;
      const naturalHeaderBox = await header.boundingBox();
      expect(naturalHeaderBox).not.toBeNull();
      expect(naturalHeaderBox.height).toBeGreaterThanOrEqual(expectedHeaderHeight - 2);
      expect(naturalHeaderBox.height).toBeLessThanOrEqual(expectedHeaderHeight + 2);
      // Chromium font metrics can round the same area by 1-2px across runners.
      await header.evaluate((element, height) => {
        element.style.setProperty('box-sizing', 'border-box', 'important');
        element.style.setProperty('height', `${height}px`, 'important');
        element.style.setProperty('min-height', `${height}px`, 'important');
        element.style.setProperty('max-height', `${height}px`, 'important');
        element.style.setProperty('overflow', 'hidden', 'important');
      }, expectedHeaderHeight);
      const headerSnapshotBox = await header.boundingBox();
      const headerSnapshot = await page.screenshot({
        animations: 'disabled',
        clip: {
          x: Math.floor(headerSnapshotBox.x),
          y: Math.floor(headerSnapshotBox.y),
          width: Math.round(headerSnapshotBox.width),
          height: expectedHeaderHeight,
        },
      });
      expect(headerSnapshot).toMatchSnapshot('r35-default-corporate-header.png', visualOptions);
      const expectedFooterHeight = testInfo.project.name === 'desktop-1440' ? 453 : 648;
      const naturalFooterBox = await footer.boundingBox();
      expect(naturalFooterBox).not.toBeNull();
      expect(naturalFooterBox.height).toBeGreaterThanOrEqual(expectedFooterHeight - 2);
      expect(naturalFooterBox.height).toBeLessThanOrEqual(expectedFooterHeight + 2);
      await footer.evaluate((element, height) => {
        element.style.setProperty('box-sizing', 'border-box', 'important');
        element.style.setProperty('height', `${height}px`, 'important');
        element.style.setProperty('min-height', `${height}px`, 'important');
        element.style.setProperty('max-height', `${height}px`, 'important');
        element.style.setProperty('overflow', 'hidden', 'important');
      }, expectedFooterHeight);
      await footer.scrollIntoViewIfNeeded();
      const footerSnapshotBox = await footer.boundingBox();
      const footerSnapshot = await page.screenshot({
        animations: 'disabled',
        clip: {
          x: Math.floor(footerSnapshotBox.x),
          y: Math.floor(footerSnapshotBox.y),
          width: Math.round(footerSnapshotBox.width),
          height: expectedFooterHeight,
        },
        mask: [footer.locator('[data-yk-copyright-text]')],
        maskColor: '#030712',
      });
      expect(footerSnapshot).toMatchSnapshot('r35-default-corporate-footer.png', visualOptions);
    }

    await page.goto(fixtures.blox_page_url, { waitUntil: 'networkidle' });
    const liveHeader = page.locator('.yk-blox-header');
    const liveFooter = page.locator('.yk-blox-footer');
    await expect(liveHeader).toHaveAttribute('data-yk-edit', /\/admin\/blox_editor\.php\?template=\d+/);
    await expect(liveFooter).toHaveAttribute('data-yk-edit', /\/admin\/blox_editor\.php\?template=\d+/);

    const regionNavigator = page.getByTestId('admin-edit-regions');
    const regionSummary = regionNavigator.locator('summary');
    const regionMenu = page.getByTestId('admin-edit-region-menu');
    await expect(regionNavigator).toBeVisible();
    await regionSummary.click();
    await expect(regionMenu).toBeVisible();
    const regionHeadings = {
      'zh-CN': ['当前页面', '页头', '正文', '页脚'],
      en: ['Current page', 'Header', 'Body', 'Footer'],
      ja: ['現在のページ', 'ヘッダー', '本文', 'フッター'],
    }[await page.locator('html').getAttribute('lang')];
    await expect(regionMenu.locator('.ik-ab-region-heading')).toHaveText(regionHeadings);
    await expect(regionMenu.locator('a[href*="focus_section="]')).not.toHaveCount(0);
    await expect(regionMenu.locator('a[href*="focus_element="]')).not.toHaveCount(0);
    const labeledSection = page.locator('[data-yk-sec-id][data-yk-sec-label]').first();
    await expect(labeledSection).toHaveCount(1);
    const labeledSectionId = await labeledSection.getAttribute('data-yk-sec-id');
    const labeledSectionText = await labeledSection.getAttribute('data-yk-sec-label');
    expect(labeledSectionId).toBeTruthy();
    expect(labeledSectionText).toBeTruthy();
    const labeledSectionLink = regionMenu.locator(
      `a[href*="focus_section=${encodeURIComponent(labeledSectionId)}"]`,
    );
    await expect(labeledSectionLink).toHaveText(labeledSectionText);
    await expect(labeledSectionLink).toHaveAttribute('title', labeledSectionText);
    const regionLayout = await regionMenu.evaluate((element) => ({
      left: element.getBoundingClientRect().left,
      right: element.getBoundingClientRect().right,
      viewport: document.documentElement.clientWidth,
    }));
    expect(regionLayout.left).toBeGreaterThanOrEqual(0);
    expect(regionLayout.right).toBeLessThanOrEqual(regionLayout.viewport + 1);
    await page.keyboard.press('Escape');
    await expect(regionNavigator).not.toHaveAttribute('open', '');
    await expect(regionSummary).toBeFocused();

    if (testInfo.project.name === 'desktop-1440') {
      const navigationTarget = liveHeader.locator('[data-yk-element-edit="header-navigation"]').first();
      await expect(navigationTarget).toBeVisible();
      const navigationId = await navigationTarget.getAttribute('data-yk-element-id');
      const navigationLabel = await navigationTarget.getAttribute('data-yk-element-label');
      expect(navigationId).toBeTruthy();
      expect(navigationLabel).toBeTruthy();
      await navigationTarget.hover();
      const editButton = page.locator('#yk-edit-btn');
      await expect(editButton).toHaveText(`✎ ${navigationLabel}`);
      await expect(editButton).toHaveAttribute('href', new RegExp(`focus_element=${encodeURIComponent(navigationId)}`));
      await editButton.hover();
      await page.waitForTimeout(350);
      await expect(editButton).toBeVisible();
      await expect(editButton).toHaveText(`✎ ${navigationLabel}`);
      const navigationHref = await editButton.getAttribute('href');
      const frontendLocation = new URL(page.url());
      const frontendReturnTo = frontendLocation.pathname + frontendLocation.search;

      const contactTargets = page.locator('[data-yk-element-edit="contact"]');
      await expect(contactTargets).toHaveCount(2);
      await expect(contactTargets.first()).toHaveAttribute('data-yk-element-id', /.+/);

      const searchTarget = liveHeader.locator('[data-yk-element-edit="site-search"]');
      const languageTarget = liveHeader.locator('[data-yk-element-edit="language-switcher"]');
      const footerNavigationTarget = liveFooter.locator('[data-yk-element-edit="footer-navigation"]');
      const copyrightTarget = liveFooter.locator('[data-yk-element-edit="site-copyright"]');
      await expect(searchTarget).toBeVisible();
      await expect(languageTarget).toBeVisible();
      await expect(footerNavigationTarget).toBeVisible();
      await expect(copyrightTarget).toBeVisible();
      const searchId = await searchTarget.getAttribute('data-yk-element-id');
      const languageId = await languageTarget.getAttribute('data-yk-element-id');
      const copyrightId = await copyrightTarget.getAttribute('data-yk-element-id');
      const headerEditorHref = await liveHeader.getAttribute('data-yk-edit');
      const footerEditorHref = await liveFooter.getAttribute('data-yk-edit');
      expect(searchId).toBeTruthy();
      expect(languageId).toBeTruthy();
      expect(copyrightId).toBeTruthy();
      expect(headerEditorHref).toBeTruthy();
      expect(footerEditorHref).toBeTruthy();

      await page.goto(navigationHref, { waitUntil: 'domcontentloaded' });
      const selectedNavigation = page.locator(`[data-sort-child-item][data-item-id="${navigationId}"]`).first();
      await expect(selectedNavigation).toHaveClass(/bg-blue-100/);
      await expect(page.getByTestId('blox-nav-content-source')).toBeVisible();
      await expect(page.getByTestId('blox-nav-content-manage')).toHaveAttribute('href', /\/admin\/nav_menu\.php/);
      await Promise.all([
        page.waitForURL((url) => url.pathname + url.search === frontendReturnTo),
        page.getByTestId('blox-back').click(),
      ]);
      const returnedNavigation = page.locator(`[data-yk-element-id="${navigationId}"]:visible`).first();
      await expect(returnedNavigation).toHaveClass(/yk-return-focus/);
      await expect(page.getByTestId('frontend-return-focus-status')).toContainText(navigationLabel);
      expect(page.url()).not.toContain('yk_focus_element');

      await page.goto(`${headerEditorHref}&focus_element=${encodeURIComponent(searchId)}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator(`[data-sort-child-item][data-item-id="${searchId}"]`).first()).toHaveClass(/bg-blue-100/);
      await expect(page.getByTestId('blox-search-content-source')).toBeVisible();

      await page.goto(`${headerEditorHref}&focus_element=${encodeURIComponent(languageId)}`, { waitUntil: 'domcontentloaded' });
      await expect(page.locator(`[data-sort-child-item][data-item-id="${languageId}"]`).first()).toHaveClass(/bg-blue-100/);
      await expect(page.getByTestId('blox-language-content-source')).toBeVisible();
      await expect(page.getByTestId('blox-language-content-manage')).toHaveAttribute('href', '/admin/setting_lang.php');

      await page.goto(`${footerEditorHref}&focus_element=${encodeURIComponent(copyrightId)}`, { waitUntil: 'domcontentloaded' });
      const selectedCopyright = page.locator(`[data-sort-el-item][data-item-id="${copyrightId}"]`).first();
      await expect(selectedCopyright.locator('[data-element-drag-handle]')).toHaveClass(/bg-blue-100/);
      await expect(page.getByTestId('blox-copyright-content-source')).toBeVisible();
      await expect(page.getByTestId('blox-copyright-content-manage')).toHaveAttribute('href', /tab=footer/);
      await expect(page.getByTestId('blox-filing-content-manage')).toHaveAttribute('href', /tab=basic/);

      await page.goto(headerEditorHref, { waitUntil: 'domcontentloaded' });
      await addTemporaryHeading(page);
      const headerDraftMarker = `Header draft ${Date.now()}`;
      const headerDraftInput = page.locator('[data-control-key="text"] input[type="text"]').first();
      await performPreviewUpdate(page, () => headerDraftInput.fill(headerDraftMarker));
      const saveDraftResponse = page.waitForResponse((response) => {
        const body = new URLSearchParams(response.request().postData() || '');
        return new URL(response.url()).pathname === '/admin/blox_template_api.php'
          && body.get('action') === 'save_draft';
      });
      await page.getByTestId('blox-save').click();
      expect((await (await saveDraftResponse).json()).code).toBe(0);

      await page.goto(fixtures.blox_page_url, { waitUntil: 'domcontentloaded' });
      const headerDraftItem = page.locator('[data-draft-kind="header"]');
      await page.getByTestId('admin-unpublished-changes').locator('summary').click();
      await expect(headerDraftItem).toBeVisible();
      const headerDraftPreviewHref = await headerDraftItem.getByText('预览草稿').getAttribute('href');
      const headerContinueHref = await headerDraftItem.getByText('继续编辑').getAttribute('href');
      expect(headerDraftPreviewHref).toBeTruthy();
      expect(headerContinueHref).toBeTruthy();

      const publishedHeader = await page.request.get(`${fixtures.blox_page_url}&preview=1`);
      expect(await publishedHeader.text()).not.toContain(headerDraftMarker);
      await page.goto(headerDraftPreviewHref, { waitUntil: 'domcontentloaded' });
      await expect(page.getByTestId('blox-draft-preview-bar')).toBeVisible();
      await expect(page.locator('.yk-blox-header')).toContainText(headerDraftMarker);

      await page.goto(headerContinueHref, { waitUntil: 'domcontentloaded' });
      page.on('dialog', async (dialog) => dialog.accept());
      const publishResponse = page.waitForResponse((response) => {
        const body = new URLSearchParams(response.request().postData() || '');
        return new URL(response.url()).pathname === '/admin/blox_template_api.php'
          && body.get('action') === 'publish';
      });
      await page.getByTestId('blox-publish-template').click();
      expect((await (await publishResponse).json()).code).toBe(0);
      await page.goto(fixtures.blox_page_url, { waitUntil: 'domcontentloaded' });
      await expect(page.locator('[data-draft-kind="header"]')).toHaveCount(0);
      await expect(page.locator('.yk-blox-header')).toContainText(headerDraftMarker);
    }
    expect(consoleEntries, 'published default areas must not log browser errors').toEqual([]);
  } finally {
    await unpublishAreas(page);
  }
});

test('default theme header keeps mobile navigation operable @ci', async ({ page }, testInfo) => {
  await unpublishAreas(page);
  await page.goto('/', { waitUntil: 'networkidle' });

  const header = page.locator('#siteHeader');
  const trigger = page.locator('#mobileMenuBtn');
  const menu = page.locator('#mobileMenu');
  await expect(header).toBeVisible();

  if (testInfo.project.name === 'desktop-1440') {
    await expect(trigger).toBeHidden();
    await expect(menu).toBeHidden();
    return;
  }

  await expect(trigger).toBeVisible();
  await expect(trigger).toHaveAttribute('aria-controls', 'mobileMenu');
  await expect(trigger).toHaveAttribute('aria-expanded', 'false');
  const triggerBox = await trigger.boundingBox();
  expect(triggerBox.width).toBeGreaterThanOrEqual(44);
  expect(triggerBox.height).toBeGreaterThanOrEqual(44);

  await trigger.click();
  await expect(menu).toBeVisible();
  await expect(trigger).toHaveAttribute('aria-expanded', 'true');
  const menuLayout = await menu.evaluate((element) => ({
    right: element.getBoundingClientRect().right,
    viewport: document.documentElement.clientWidth,
    minLinkHeight: Math.min(...Array.from(element.querySelectorAll('a')).map((link) => link.getBoundingClientRect().height)),
  }));
  expect(menuLayout.right).toBeLessThanOrEqual(menuLayout.viewport + 1);
  expect(menuLayout.minLinkHeight).toBeGreaterThanOrEqual(44);

  await page.keyboard.press('Escape');
  await expect(menu).toBeHidden();
  await expect(trigger).toBeFocused();
});

// v1.18.4 曾把画廊线框预览整体摘除：旧实现在 mobile-390 溢出、从未在 Linux CI 绿过。
// 重新引入的纯 Tailwind 线框必须在所有视口零水平溢出——这条断言就是当年缺的防线。
test('area preset gallery wireframes fit the viewport @ci', async ({ page }) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
  const consoleEntries = observeConsole(page);
  await page.goto('/admin/blox_templates.php?type=header', { waitUntil: 'domcontentloaded' });

  const presets = page.locator('[data-testid="blox-area-presets"]');
  await expect(presets).toBeVisible();
  const wires = presets.locator('[data-testid="blox-preset-wire"]');
  // 每个页头预设各一张示意图。数字随 BloxAreaTemplatePresets::catalog() 的 header 条目走——
  // 加预设时这里要同步改（v1.19.2：clean/full-width/centered/corporate/topbar/search 共 6 个）。
  await expect(wires).toHaveCount(6);
  await expect(wires.first()).toBeVisible();

  // 「当前网页头」卡片也带示意图（type=header 视图只显示 header 一张卡）
  await expect(
    page.locator('[data-testid="blox-current-areas"] [data-testid="blox-preset-wire"]'),
  ).toHaveCount(1);

  const overflow = await page.evaluate(
    () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
  );
  expect(overflow, 'templates gallery must not overflow horizontally').toBeLessThanOrEqual(0);
  expect(consoleEntries, 'templates gallery must not log browser errors').toEqual([]);
});

test('multilingual area manager exposes inheritance without horizontal overflow @ci', async ({ page }) => {
  test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
  const consoleEntries = observeConsole(page);
  try {
    await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
    for (const template of AREA_TEMPLATES) await installAndPublish(page, template);

    await page.goto('/admin/blox_templates.php?type=header&area_lang=en#blox-language-areas', { waitUntil: 'domcontentloaded' });
    const panel = page.getByTestId('blox-language-areas');
    await expect(panel).toBeVisible();
    await expect(page.getByTestId('blox-language-tab-zh-CN')).toBeVisible();
    await expect(page.getByTestId('blox-language-tab-en')).toHaveAttribute('aria-selected', 'true');
    await expect(page.getByTestId('blox-language-tab-ja')).toBeVisible();
    await expect(page.getByTestId('blox-language-area-header')).toBeVisible();
    await expect(page.getByTestId('blox-language-copy-default')).toBeVisible();

    const overflow = await page.evaluate(
      () => document.documentElement.scrollWidth - document.documentElement.clientWidth,
    );
    expect(overflow, 'multilingual area manager must fit the viewport').toBeLessThanOrEqual(0);
    expect(consoleEntries, 'multilingual area manager must not log browser errors').toEqual([]);
  } finally {
    await unpublishAreas(page);
  }
});
