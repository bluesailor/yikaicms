/**
 * R7A 单页圆点导航：编辑器开启 → 发布 → 前台真实行为。
 *
 * 前台断言：真实锚点链接与稳定锚点、右/左位置、aria 与悬停标题、
 * IntersectionObserver 高亮、hash 直达初始高亮、reduced-motion 降级、
 * scroll-margin 偏移变量；手机默认隐藏由 CSS 断言（无 --mobile 类）。
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

const app = (page, fn, arg) => page.evaluate(([body, value]) => {
  const alpine = window.Alpine.$data(document.body);
  // eslint-disable-next-line no-new-func
  return new Function('app', 'arg', `return (${body})(app, arg);`)(alpine, value);
}, [fn.toString(), arg === undefined ? null : arg]);

test('dot navigation publishes real anchors and highlights on the front end @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);

  // 至少三个顶层区块；前两个显式加入导航（第 1 个自定义标题，第 3 个不勾选作对照）
  await app(page, (a) => {
    while (a.sections.length < 3) a.duplicateSection(0);
    a.sections[0].settings.dot_nav_on = true;
    a.sections[0].settings.dot_nav_title = '第一站';
    a.sections[1].settings.dot_nav_on = true;
    delete a.sections[2].settings.dot_nav_on;
  });
  await page.waitForTimeout(150);

  // 网页设置里开启（真实点击）：默认右侧 → 切左侧；手机保持默认隐藏
  await page.getByTestId('blox-page-frame-open').click();
  await expect(page.getByTestId('blox-page-frame-dotnav')).toBeVisible();
  await page.getByTestId('blox-dotnav-enabled').check();
  await page.getByTestId('blox-dotnav-left').click();
  await page.getByTestId('blox-page-frame-apply').click();
  await expect.poll(() => app(page, a => a.docSettings.dot_nav && a.docSettings.dot_nav.enabled)).toBe(true);

  // 保存 + 发布
  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|clean|published/, { timeout: 10000 });
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-publish-page').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/, { timeout: 15000 });

  // 前台（匿名请求）：导航存在、真实 href、稳定锚点、位置类、无手机类、资源已注册
  const frontUrl = await app(page, a => a.pageUrl);
  const anonymous = await page.request.get(frontUrl, { headers: { Cookie: '' } });
  expect(anonymous.ok()).toBeTruthy();
  const html = await anonymous.text();
  expect(html).toContain('data-yk-dotnav');
  expect(html).toContain('yk-dotnav--left');
  expect(html).not.toContain('yk-dotnav--mobile');
  const anchors = [...html.matchAll(/data-yk-dotnav-target="([^"]+)"/g)].map(m => m[1]);
  expect(anchors.length).toBe(2);
  for (const anchor of anchors) {
    expect(html).toContain(`href="#${anchor}"`);
    expect(html).toContain(`id="${anchor}"`);
  }
  expect(html).toContain('aria-label="第一站"');
  expect(html).toContain('blox-dot-nav.css');
  expect(html).toContain('blox-dot-nav.js');

  // 浏览器行为：点击第二个圆点 → hash 变化（真实锚点导航，不劫持历史）+ 高亮跟随
  await page.goto(frontUrl, { waitUntil: 'domcontentloaded' });
  const dots = page.locator('[data-yk-dotnav-target]');
  await expect(dots).toHaveCount(2);
  await expect(dots.first()).toHaveAttribute('title', '第一站');
  await dots.nth(1).click();
  await expect.poll(() => page.evaluate(() => window.location.hash)).toBe('#' + anchors[1]);
  await expect(dots.nth(1)).toHaveClass(/is-active/, { timeout: 5000 });
  await expect(dots.nth(1)).toHaveAttribute('aria-current', 'true');
  // 偏移变量已按实际吸顶元素测量写入（不写死 80px）
  const offset = await page.evaluate(() => document.documentElement.style.getPropertyValue('--yk-dotnav-offset'));
  expect(offset).toMatch(/^\d+px$/);
  // 平滑滚动在普通模式开启
  expect(await page.evaluate(() => document.documentElement.classList.contains('yk-dotnav-smooth'))).toBe(true);

  // hash 直达：第二个圆点初始高亮
  await page.goto(frontUrl.includes('#') ? frontUrl : frontUrl + '#' + anchors[1], { waitUntil: 'domcontentloaded' });
  await expect(page.locator(`[data-yk-dotnav-target="${anchors[1]}"]`)).toHaveAttribute('aria-current', 'true');

  // reduced-motion：立即定位（不加平滑滚动类），链接仍可用
  await page.emulateMedia({ reducedMotion: 'reduce' });
  await page.reload({ waitUntil: 'domcontentloaded' });
  expect(await page.evaluate(() => document.documentElement.classList.contains('yk-dotnav-smooth'))).toBe(false);
  await page.locator(`[data-yk-dotnav-target="${anchors[0]}"]`).click();
  await expect.poll(() => page.evaluate(() => window.location.hash)).toBe('#' + anchors[0]);
  await page.emulateMedia({ reducedMotion: 'no-preference' });
});
