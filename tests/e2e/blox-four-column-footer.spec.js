/**
 * 四列页脚（浅色 / 深色）：安装并发布后，前台页脚上方四列等高并排、下方是极简页脚的版权备案条；
 * 平板与手机逐列堆叠，不出现横向滚动。
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

const FOOTERS = [
  { slug: 'four-column-light-site-footer', tone: 'light' },
  { slug: 'four-column-dark-site-footer', tone: 'dark' },
];

async function submit(page, form) {
  await Promise.all([
    page.waitForResponse((response) => response.request().method() === 'POST'),
    form.locator('button[type="submit"]').click(),
  ]);
  await page.waitForLoadState('domcontentloaded');
}

async function installAndPublish(page, slug) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  const installForm = page.locator('form').filter({ has: page.locator(`input[name="slug"][value="${slug}"]`) });
  await expect(installForm).toHaveCount(1);
  await submit(page, installForm);
  const importedId = new URL(page.url()).searchParams.get('imported');
  expect(importedId, `installing ${slug} should redirect with its template id`).toMatch(/^\d+$/);
  const templateForms = page.locator('form').filter({ has: page.locator(`input[name="id"][value="${importedId}"]`) });
  if (await templateForms.locator('input[name="action"][value="unpublish"]').count()) return;
  const publishForm = templateForms.filter({ has: page.locator('input[name="action"][value="publish"]') }).first();
  await expect(publishForm).toHaveCount(1);
  await submit(page, publishForm);
}

async function unpublish(page, slug) {
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });
  const rows = page.locator(`tbody tr[data-template-source="builtin"][data-template-source-ref="${slug}"]`);
  const form = rows.locator('form:has(input[name="action"][value="unpublish"])').first();
  if (await form.count()) await submit(page, form);
}

for (const footerPreset of FOOTERS) {
  test(`${footerPreset.slug} renders four columns above the minimal footer bar @ci`, async ({ page }, testInfo) => {
    test.skip(testInfo.project.name !== 'desktop-1440', 'installs once and checks desktop, tablet and phone widths itself');
    test.setTimeout(90000);
    const consoleEntries = observeConsole(page);
    const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
    try {
      await installAndPublish(page, footerPreset.slug);
      await page.goto(`${fixtures.blox_page_url}&preview=1`, { waitUntil: 'networkidle' });

      const footer = page.locator('.yk-blox-footer');
      await expect(footer).toBeVisible();
      const sections = footer.locator(':scope section');
      await expect(sections).toHaveCount(2);
      const [top, bar] = [sections.nth(0), sections.nth(1)];

      // 上方：logo、导航、联系方式（电话可拨）、站内搜索
      await expect(top.locator('a[href^="tel:"]')).toBeVisible();
      await expect(top.locator('form[role="search"]')).toBeVisible();
      // 下方：极简页脚内容——当年版权，且不再混入导航或联系方式
      await expect(bar).toContainText(String(new Date().getFullYear()));
      await expect(bar.locator('a[href^="tel:"]')).toHaveCount(0);
      await expect(bar.locator('form[role="search"]')).toHaveCount(0);

      const tone = await top.evaluate((node) => {
        const [r, g, b] = getComputedStyle(node).backgroundColor.match(/\d+/g).map(Number);
        return (r * 299 + g * 587 + b * 114) / 1000 < 128 ? 'dark' : 'light';
      });
      expect(tone).toBe(footerPreset.tone);

      const columnBoxes = () => top.evaluate((node) => {
        const grid = Array.from(node.querySelectorAll('*')).find((el) => el.children.length === 4
          && Array.from(el.children).every((child) => child.getBoundingClientRect().height > 0));
        return grid ? Array.from(grid.children).map((child) => {
          const r = child.getBoundingClientRect();
          return { top: Math.round(r.top), left: Math.round(r.left), width: Math.round(r.width) };
        }) : [];
      });

      const desktop = await columnBoxes();
      expect(desktop).toHaveLength(4);
      expect(new Set(desktop.map((box) => box.top)).size, 'desktop columns share one row').toBe(1);
      await footer.screenshot({ path: testInfo.outputPath(`${footerPreset.slug}-desktop.png`) });

      for (const width of [768, 390]) {
        await page.setViewportSize({ width, height: 900 });
        await page.waitForTimeout(150);
        const boxes = await columnBoxes();
        expect(boxes).toHaveLength(4);
        expect(new Set(boxes.map((box) => box.top)).size, `${width}px stacks the columns`).toBe(4);
        const overflow = await page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
        expect(overflow, `${width}px has no horizontal scroll`).toBeLessThanOrEqual(1);
        await footer.screenshot({ path: testInfo.outputPath(`${footerPreset.slug}-${width}.png`) });
      }
      expect(consoleEntries).toEqual([]);
    } finally {
      await page.setViewportSize({ width: 1440, height: 900 });
      await unpublish(page, footerPreset.slug);
    }
  });
}
