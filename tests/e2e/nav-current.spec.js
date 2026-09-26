const { test, expect } = require('@playwright/test');

// 一级导航「当前位置」门禁（整站模板制作规范 04-B / 上架 SOP 同一口径）：
// 点击每个一级栏目落地后，该链接必须 aria-current="page"、全组唯一、且有可见差异；
// 父栏目下的子页，父项为 aria-current="true"。默认主题页头与 Blox 页头两条渲染路径都要过。

const HEADER_TEMPLATES = ['corporate-site-header', 'centered-site-header'];
const CLEANUP_SLUGS = ['clean-site-header', ...HEADER_TEMPLATES];

const THEME_DESKTOP = '#siteHeader nav[aria-label] > a[href], #siteHeader nav[aria-label] > .nav-dropdown > a[href]';
const BLOX_DESKTOP = '[data-yk-nav-overflow] > li:not([data-yk-nav-more]) > a[href]';

async function submit(page, form) {
  const needsConfirmation = await form.evaluate((node) => (
    node.hasAttribute('onsubmit') || node.hasAttribute('data-conflict-message')
  ));
  if (needsConfirmation) page.once('dialog', (dialog) => dialog.accept());
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

async function unpublishHeaders(page) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  for (const slug of CLEANUP_SLUGS) {
    const rows = page.locator(`tbody tr[data-template-source="builtin"][data-template-source-ref="${slug}"]`);
    const form = rows.locator('form:has(input[name="action"][value="unpublish"])').first();
    if (await form.count()) await submit(page, form);
  }
}

async function publishHeader(page, slug) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  const installForm = page.locator('form').filter({ has: page.locator(`input[name="slug"][value="${slug}"]`) });
  await expect(installForm).toHaveCount(1);
  await submit(page, installForm);
  const id = new URL(page.url()).searchParams.get('imported');
  expect(id, `installing ${slug} should redirect with its template id`).toMatch(/^\d+$/);
  const forms = page.locator('form').filter({ has: page.locator(`input[name="id"][value="${id}"]`) });
  if (await forms.locator('input[name="action"][value="unpublish"]').count()) return;
  await submit(page, forms.filter({ has: page.locator('input[name="action"][value="publish"]') }).first());
}

async function primaryLinks(page, selector) {
  await page.goto('/', { waitUntil: 'domcontentloaded' });
  return page.locator(selector).evaluateAll((links) => links
    .filter((link) => link.getBoundingClientRect().width > 0 && link.target !== '_blank'
      && new URL(link.href).origin === location.origin)
    .map((link) => ({ href: link.href, label: link.textContent.trim() })));
}

async function currentState(page, selector, href) {
  return page.evaluate(({ selector, href }) => {
    const links = [...document.querySelectorAll(selector)].filter((link) => link.getBoundingClientRect().width > 0);
    const target = links.find((link) => link.href === href);
    if (!target) return { found: false };
    const look = (el) => {
      const style = getComputedStyle(el);
      return [style.color, style.backgroundColor, style.fontWeight, style.boxShadow, style.textDecorationLine].join('|');
    };
    const peers = links.filter((link) => link !== target && !link.hasAttribute('aria-current'));
    return {
      found: true,
      ariaCurrent: target.getAttribute('aria-current'),
      pageCount: links.filter((link) => link.getAttribute('aria-current') === 'page').length,
      distinct: peers.length > 0 && peers.every((peer) => look(peer) !== look(target)),
    };
  }, { selector, href });
}

async function expectEveryPrimaryLinkMarksItself(page, selector) {
  const links = await primaryLinks(page, selector);
  expect(links.length, `primary links for ${selector}`).toBeGreaterThan(1);
  for (const link of links) {
    const response = await page.goto(link.href, { waitUntil: 'domcontentloaded' });
    expect(response?.status(), link.label).toBe(200);
    if (page.url() !== link.href) continue; // 栏目跳转（redirect）落点不同，不属于本门禁
    expect(await currentState(page, selector, link.href), link.label)
      .toEqual({ found: true, ariaCurrent: 'page', pageCount: 1, distinct: true });
  }
}

test.describe('primary navigation current state', () => {
  test.beforeEach(async ({ page }) => {
    await unpublishHeaders(page);
  });

  test.afterEach(async ({ page }) => {
    await unpublishHeaders(page); // 不把已发布的页头留给后续用例
  });

  test('default theme header marks the current page and its section @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'desktop theme header');
    await expectEveryPrimaryLinkMarksItself(page, THEME_DESKTOP);

    // 子栏目页：父项是所在区域（true），子项是当前页（page）
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    const pair = await page.locator('#siteHeader nav[aria-label] > .nav-dropdown').first().evaluate((dropdown) => ({
      parent: dropdown.querySelector(':scope > a').href,
      child: dropdown.querySelector('.nav-dropdown-menu a[href]').href,
    }));
    await page.goto(pair.child, { waitUntil: 'domcontentloaded' });
    await expect(page.locator(`#siteHeader nav[aria-label] > .nav-dropdown > a[href="${new URL(pair.parent).pathname}"]`))
      .toHaveAttribute('aria-current', 'true');
    await expect(page.locator('#siteHeader .nav-dropdown-menu a[aria-current="page"]')).toHaveCount(1);
  });

  test('default theme mobile menu marks the current page @ci', async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'mobile-390', 'mobile theme menu');
    await page.goto('/', { waitUntil: 'domcontentloaded' });
    await page.locator('#mobileMenuBtn').click();
    const hrefs = await page.locator('#mobileMenu > div > div > a[href]').evaluateAll((items) => items
      .filter((item) => item.target !== '_blank').map((item) => item.href));
    expect(hrefs.length).toBeGreaterThan(1);
    const target = hrefs[hrefs.length - 1];
    await page.goto(target, { waitUntil: 'domcontentloaded' });
    await page.locator('#mobileMenuBtn').click();
    const current = page.locator('#mobileMenu > div > div > a[aria-current="page"]');
    await expect(current).toHaveCount(1);
    expect(await current.evaluate((item) => item.href)).toBe(target);
  });

  for (const slug of HEADER_TEMPLATES) {
    test(`Blox header ${slug} marks the current page @ci`, async ({ page }, testInfo) => {
      test.skip(process.env.SMOKE_BLOX_ADVANCED === '0', 'area template management is an advanced feature');
      test.skip(!['desktop-1440', 'mobile-390'].includes(testInfo.project.name), 'desktop and mobile widths');
      await publishHeader(page, slug);
      if (testInfo.project.name === 'desktop-1440') {
        await expectEveryPrimaryLinkMarksItself(page, BLOX_DESKTOP);
        return;
      }
      // 手机抽屉：落地页的菜单项同样唯一 aria-current="page"
      await page.goto('/', { waitUntil: 'domcontentloaded' });
      const hrefs = await page.locator('[data-yk-drawer-panel] > ul > li > a[href]').evaluateAll((items) => items
        .filter((item) => item.target !== '_blank').map((item) => item.href));
      expect(hrefs.length).toBeGreaterThan(1);
      const target = hrefs[hrefs.length - 1];
      await page.goto(target, { waitUntil: 'domcontentloaded' });
      await page.locator('[data-yk-drawer-open]').first().click();
      const current = page.locator('[data-yk-drawer-panel] > ul a[aria-current="page"]');
      await expect(current).toHaveCount(1);
      await expect(current).toBeVisible();
      expect(await current.evaluate((item) => item.href)).toBe(target);
    });
  }
});
