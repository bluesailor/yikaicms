// 图片轮播（2.1.0）：缩略图导航点了滚到那张、当前缩略图高亮；容器「滚动时堆叠」的子元素吸顶并逐个错开。
const { test, expect } = require('@playwright/test');

test('image carousel thumbnails navigate and stacked cards stick @ci', async ({ page }, info) => {
  const errors = [];
  page.on('pageerror', (error) => errors.push(error.message));
  const response = await page.goto('/tests/e2e/image-carousel-page.php');
  expect(response.status()).toBe(200);

  const carousel = page.getByTestId('carousel');
  const thumbs = carousel.locator('[data-yk-carousel-thumb]');
  await expect(thumbs).toHaveCount(5);
  await expect(thumbs.nth(0)).toHaveAttribute('aria-current', 'true');
  await thumbs.nth(2).click();
  await expect(thumbs.nth(2)).toHaveAttribute('aria-current', 'true');
  await expect(thumbs.nth(0)).toHaveAttribute('aria-current', 'false');
  const track = carousel.locator('[data-yk-carousel-track]');
  // 平滑滚动结束后正好停在第 3 张（不露出上一张）
  await expect.poll(() => track.evaluate((node) => {
    const slides = node.children;
    return Math.abs(node.scrollLeft - (slides[2].offsetLeft - slides[0].offsetLeft));
  })).toBeLessThanOrEqual(3);   // 轨道左右各有 2px 内边距

  const cards = page.getByTestId('stack').locator('[data-stack-card]');
  const tops = await cards.evaluateAll((nodes) => nodes.map((node) => [getComputedStyle(node).position, parseFloat(getComputedStyle(node).top)]));
  expect(tops.map(([position]) => position)).toEqual(['sticky', 'sticky', 'sticky']);
  expect(tops[1][1]).toBeGreaterThan(tops[0][1]);
  expect(tops[2][1]).toBeGreaterThan(tops[1][1]);

  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth + 1)).toBe(true);
  expect(errors).toEqual([]);
  await carousel.screenshot({ path: info.outputPath('image-carousel.png') });
});
