const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');

test('homepage loads the banner runtime once after its video policy @ci', async ({ page }) => {
  const errors = observeConsole(page);
  const writes = observeUnsafeWrites(page);

  await page.goto('/', { waitUntil: 'domcontentloaded' });

  const scripts = await page.locator('script[src]').evaluateAll(nodes => nodes.map(node => (
    new URL(node.src, document.baseURI).pathname
  )));
  const swiperIndex = scripts.indexOf('/assets/swiper/swiper-bundle.min.js');
  const policyIndex = scripts.indexOf('/assets/js/blox-video-policy.js');
  const bannerIndex = scripts.indexOf('/assets/js/blox-banner.js');

  expect(swiperIndex).toBeGreaterThanOrEqual(0);
  expect(policyIndex).toBeGreaterThan(swiperIndex);
  expect(bannerIndex).toBeGreaterThan(policyIndex);
  expect(scripts.filter(path => path === '/assets/js/blox-video-policy.js')).toHaveLength(1);
  expect(scripts.filter(path => path === '/assets/js/blox-banner.js')).toHaveLength(1);
  await expect.poll(() => page.evaluate(() => ({
    banner: typeof window.BloxBanner,
    policy: typeof window.BloxVideoPolicy,
  }))).toEqual({ banner: 'object', policy: 'object' });

  expect(writes).toEqual([]);
  expect(errors).toEqual([]);
});
