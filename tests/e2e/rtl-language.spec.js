// 阿拉伯语前台（从右到左）：页面声明 dir=rtl 与 lang=ar，界面文字来自阿语包，版面不横向溢出。截图附件供人工对照。
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('child_process');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'rtl-language-fixture.php'), action], { cwd: root, encoding: 'utf8' });

let urls;
test.describe.configure({ mode: 'serial' });
test.use({ storageState: { cookies: [], origins: [] } });
test.beforeAll(() => {
  fixture('restore');
  urls = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));

for (const [name, viewport] of Object.entries({ desktop: { width: 1440, height: 900 }, mobile: { width: 390, height: 844 } })) {
  test(`Arabic pages render right-to-left without overflow (${name}) @ci`, async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'owns its viewports');
    await page.setViewportSize(viewport);
    for (const key of ['home', 'products']) {
      const response = await page.goto(urls[key]);
      expect(response.status(), key).toBeLessThan(400);
      await expect(page.locator('html')).toHaveAttribute('dir', 'rtl');
      await expect(page.locator('html')).toHaveAttribute('lang', /^ar/);
      expect(await page.evaluate(() => /[؀-ۿ]/.test(document.body.innerText)), `${key}: 界面里应有阿拉伯文`).toBe(true);
      const overflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
      expect(overflow, `${key}: 横向溢出 ${overflow}px`).toBeLessThanOrEqual(1);
      const shot = await page.screenshot({ fullPage: false });
      await info.attach(`ar-${key}-${name}`, { body: shot, contentType: 'image/png' });
      // 人工对照：RTL_SHOT_DIR=目录 时把截图另存一份
      if (process.env.RTL_SHOT_DIR) require('fs').writeFileSync(path.join(process.env.RTL_SHOT_DIR, `ar-${key}-${name}.png`), shot);
      if (process.env.RTL_SHOT_DIR) require('fs').writeFileSync(path.join(process.env.RTL_SHOT_DIR, `ar-${key}.html`), await page.content());
    }
  });
}
