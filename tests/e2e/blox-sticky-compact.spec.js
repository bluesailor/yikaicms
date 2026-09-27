const path = require('path');
const { pathToFileURL } = require('url');
const { test, expect } = require('@playwright/test');

const fixture = pathToFileURL(path.join(__dirname, 'fixtures', 'blox-sticky-compact.html')).href;

test('opt-in sticky header contracts on desktop without shifting page content', async ({ page }) => {
  await page.goto(fixture);
  const header = page.locator('#siteHeader');
  const section = header.locator('section');
  const initialHeight = await section.evaluate(el => el.getBoundingClientRect().height);
  const initialMainTop = await page.locator('main').evaluate(el => el.getBoundingClientRect().top + scrollY);

  await page.evaluate(() => window.scrollTo(0, 240));
  await expect(header).toHaveClass(/yk-stuck/);
  await expect.poll(() => header.evaluate(el => el.getBoundingClientRect().top)).toBe(0);

  const desktop = page.viewportSize().width >= 1024;
  if (desktop) {
    await expect.poll(() => section.evaluate(el => el.getBoundingClientRect().height)).toBeLessThan(initialHeight - 20);
  } else {
    await expect.poll(() => section.evaluate(el => el.getBoundingClientRect().height)).toBe(initialHeight);
  }
  await expect.poll(() => page.locator('main').evaluate(el => el.getBoundingClientRect().top + scrollY))
    .toBeGreaterThan(initialMainTop - 2);
  await expect.poll(() => page.locator('main').evaluate(el => el.getBoundingClientRect().top + scrollY))
    .toBeLessThan(initialMainTop + 2);

  await page.evaluate(() => window.scrollTo(0, 0));
  await expect(header).not.toHaveClass(/yk-stuck/);
  await expect.poll(() => section.evaluate(el => el.getBoundingClientRect().height))
    .toBeGreaterThan(initialHeight - 2);
});

test('compact mode disabled preserves the legacy sticky height', async ({ page }) => {
  await page.goto(fixture);
  await page.locator('#siteHeader').evaluate(el => el.setAttribute('data-yk-sticky-compact', '0'));
  const header = page.locator('#siteHeader');
  const section = header.locator('section');
  const initialHeight = await section.evaluate(el => el.getBoundingClientRect().height);
  await page.evaluate(() => window.scrollTo(0, 240));
  await expect(header).toHaveClass(/yk-stuck/);
  await expect.poll(() => header.evaluate(el => el.getBoundingClientRect().top)).toBe(0);
  await expect.poll(() => section.evaluate(el => el.getBoundingClientRect().height))
    .toBe(initialHeight);
});
