const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const installMarketThemes = require('./theme-market-fixture');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });
let cleanup = () => {};

test.use({ storageState: { cookies: [], origins: [] }, reducedMotion: 'reduce' });
test.beforeAll(() => {
  cleanup = installMarketThemes(root, ['business', 'minimal']);
  fixture('catalog-baseline-fixture.php', 'pretty');
  fixture('theme-site-baseline-fixture.php', 'prepare');
});
test.afterAll(() => {
  fixture('theme-site-baseline-fixture.php', 'restore');
  fixture('catalog-baseline-fixture.php', 'restore');
  cleanup();
});

for (const theme of ['default', 'business', 'minimal']) {
  for (const language of ['zh-CN', 'en', 'ja']) {
    test(`${theme} ${language} real site pages stay readable and navigable @ci`, async ({ page, baseURL }, info) => {
      test.setTimeout(180000);
      fixture('catalog-baseline-fixture.php', theme === 'default' ? 'pretty' : theme);
      const routes = JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', language));
      expect(routes).toHaveLength(9);
      const evidence = [], failures = [];
      page.on('requestfailed', request => failures.push(`${request.method()} ${request.url()} ${request.failure()?.errorText}`));
      for (const route of routes) {
        await test.step(route.kind, async () => {
          const response = await page.goto(route.url);
          expect(response.status(), route.url).toBe(200);
          await expect(page.locator('html')).toHaveAttribute('lang', language);
          await expect(page.locator('#ik-adminbar, [data-yk-area="header"], [data-yk-area="footer"]')).toHaveCount(0);
          await expect(page.locator('#siteHeader')).toBeVisible();
          await expect(page.locator('main')).toBeVisible();
          if (theme === 'minimal') await expect(page.locator('body')).toHaveClass(/minimal-theme/);
          else if (theme === 'default') await expect(page.locator('body')).toHaveClass(/yk-site-body/);
          else await expect(page.locator('body')).not.toHaveClass(/minimal-theme|yk-site-body/);
          if (route.title) await expect(page.locator('main').getByRole('heading', { name: route.title, exact: true }).first()).toBeVisible();
          else await expect(page.locator('main h1:visible, main h2:visible').first()).toBeVisible();
          const canonical = new URL(await page.locator('link[rel="canonical"]').getAttribute('href'), baseURL);
          expect(canonical.origin).toBe(new URL(baseURL).origin);
          expect(canonical.pathname).toBe(new URL(route.url, baseURL).pathname);
          expect(canonical.search).toBe('');
          await expect(page.locator('body')).not.toContainText('[object Object]');
          // Exercise scroll reveal rather than taking an unvisited full-page capture.
          for (const block of await page.locator('main [data-animate], main [data-stagger]').all()) {
            await block.scrollIntoViewIfNeeded();
            await expect(block).toHaveClass(/animated/);
          }
          // Scroll actual content to load lazy images and reveal intersection-based animation.
          for (const image of await page.locator('main img').all()) {
            if (!await image.isVisible()) continue;
            if (await image.evaluate(node => !!node.closest('.swiper-slide:not(.swiper-slide-active)'))) continue;
            await image.scrollIntoViewIfNeeded();
            await expect.poll(() => image.evaluate(node => node.complete && node.naturalWidth > 0)).toBe(true);
          }
          const footer = page.locator('footer').last();
          await footer.scrollIntoViewIfNeeded();
          await expect(footer).toBeVisible();
          const metrics = await page.evaluate(() => {
            const visible = node => node.checkVisibility({ checkOpacity: true, checkVisibilityCSS: true })
              && !node.closest('.swiper-slide:not(.swiper-slide-active)');
            const offenders = [...document.querySelectorAll('main h1, main h2, main h3, main p, main td, footer p')]
              .filter(visible).map(node => {
                const box = node.getBoundingClientRect();
                return { tag: node.tagName, text: node.textContent.trim().slice(0, 90), left: box.left, right: box.right, width: box.width };
              }).filter(box => box.left < -1 || box.right > innerWidth + 1);
            return { viewport: innerWidth, overflow: document.documentElement.scrollWidth - document.documentElement.clientWidth,
              height: document.documentElement.scrollHeight, offenders,
              images: [...document.querySelectorAll('main img')].filter(visible).map(node => ({ src: node.currentSrc, width: node.naturalWidth, height: node.naturalHeight })),
              resources: performance.getEntriesByType('resource').filter(entry => /\.(?:css|js)(?:\?|$)/.test(entry.name)).map(entry => ({ url: entry.name, bytes: entry.transferSize })) };
          });
          await info.attach(`${route.kind}-metrics`, { body: JSON.stringify(metrics, null, 2), contentType: 'application/json' });
          await page.screenshot({ path: info.outputPath(`${theme}-${language}-${route.kind}.png`), fullPage: true });
          expect(metrics.overflow, route.url).toBeLessThanOrEqual(1);
          expect(metrics.offenders, route.url).toEqual([]);
          expect(metrics.height).toBeGreaterThan(300);
          evidence.push({ ...route, ...metrics });

          if (route.kind === 'product-detail') {
            const productRoute = routes.find(item => item.kind === 'product');
            const crumb = page.locator('main').getByRole('link', { name: productRoute.title, exact: true }).first();
            await expect(crumb).toBeVisible();
            const size = await crumb.evaluate(node => ({ height: node.getBoundingClientRect().height,
              lineHeight: parseFloat(getComputedStyle(node).lineHeight) }));
            expect(size.height, 'Breadcrumb label must not be squeezed into vertical text').toBeLessThanOrEqual(size.lineHeight + 1);
          }

          if (route.kind === 'home') {
            await page.evaluate(() => scrollTo(0, 0));
            const productRoute = routes.find(item => item.kind === 'product');
            const menu = page.locator('#mobileMenuBtn');
            if (info.project.name !== 'desktop-1440') {
              await expect(menu).toBeVisible();
              await expect(menu).toHaveAttribute('aria-expanded', 'false');
              await menu.click();
              await expect(menu).toHaveAttribute('aria-expanded', 'true');
              await expect(page.locator('#mobileMenu')).toBeVisible();
            }
            const navigation = page.locator(info.project.name === 'desktop-1440' ? '#siteHeader nav:visible' : '#mobileMenu');
            const productLink = navigation.getByRole('link', { name: productRoute.title, exact: true });
            await expect(productLink).toHaveCount(1);
            await expect(productLink).toHaveAttribute('href', productRoute.url);
            await productLink.click();
            await expect(page).toHaveURL(new URL(productRoute.url, baseURL).href);
            await expect(page.locator('main').getByRole('heading', { name: productRoute.title, exact: true }).first()).toBeVisible();
          }
          if (route.kind === 'product' || route.kind === 'list') {
            const detail = routes.find(item => item.kind === (route.kind === 'product' ? 'product-detail' : 'article-detail'));
            const link = page.locator(`main a[href="${detail.url}"]`).first();
            await expect(link).toBeVisible();
            await link.click();
            await expect(page).toHaveURL(new URL(detail.url, baseURL).href);
            await expect(page.locator('main').getByRole('heading', { name: detail.title, exact: true }).first()).toBeVisible();
          }
        });
      }
      expect(failures).toEqual([]);
      await info.attach('theme-site-baseline', { body: JSON.stringify({ theme, language, evidence }, null, 2), contentType: 'application/json' });
    });
  }
}
