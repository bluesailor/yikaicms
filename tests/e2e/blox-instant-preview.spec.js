// 画布即时预览（2.1.1，路线图 §5.8 第 2 项）：边打字画布边更新，不再等停手 400 ms 才刷新；
// 停手后画布与面板一致。只在编辑器里操作，不保存。
const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('the canvas follows typing in the settings panel @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop editor layout');
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page, 15000);
  await page.waitForTimeout(1200);   // 首次整页加载后的一秒内仍按慢节奏，避开它
  await page.evaluate(() => window.Alpine.$data(document.body).selectElement(0, 0, 0));
  const field = page.getByTestId('blox-heading-text');
  await expect(field).toBeVisible();

  await page.evaluate(() => {
    const heading = () => document.querySelector('[data-testid="blox-canvas"]').contentDocument.querySelector('[data-yk-el-type="heading"]');
    let last = heading().outerHTML;
    window.__canvasChanges = 0;
    window.__canvasWatch = setInterval(() => {
      const now = heading() ? heading().outerHTML : last;
      if (now !== last) { window.__canvasChanges++; last = now; }
    }, 16);
  });
  // 每键 250 ms：以前 400 ms 防抖，打字期间画布一次都不变
  await field.pressSequentially('Live!!', { delay: 250 });
  const whileTyping = await page.evaluate(() => window.__canvasChanges);
  await waitPreviewSettled(page, 15000);
  await page.evaluate(() => clearInterval(window.__canvasWatch));

  expect(whileTyping, 'canvas updates while the author is still typing').toBeGreaterThanOrEqual(2);
  const canvasHeading = (await page.frameLocator('[data-testid="blox-canvas"]').locator('[data-yk-el-type="heading"]').first().innerText()).trim();
  expect(canvasHeading.endsWith('Live!!'), `canvas shows the final text (${canvasHeading})`).toBe(true);
});
