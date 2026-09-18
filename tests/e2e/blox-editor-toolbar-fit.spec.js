/**
 * 编辑器顶栏在常见桌面宽度下放得下：左侧返回链接不被设备切换盖住、三组内容互不溢出。
 *
 * 2026-09-17 事故：宽屏断点加入第 4 个设备按钮后，1440 宽度下网页头编辑器顶栏需要约 1900px，
 * 左侧品牌区被挤到设备切换下面，「返回首页编辑」点不到。1920px 以下次要按钮改为只显示图标。
 */
const fs = require('fs');
const path = require('path');
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

test('editor toolbar keeps the back link reachable from 1280 to 2200 px @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'sets its own desktop widths');
  test.setTimeout(90000);
  const consoleEntries = observeConsole(page);
  const fixtures = JSON.parse(fs.readFileSync(path.resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  const editors = {
    home: '/admin/blox_editor.php?home=1',
    header: `/admin/blox_editor.php?template=${fixtures.blox_header_template}&back=home`,
    footer: `/admin/blox_editor.php?template=${fixtures.blox_footer_template}&back=home`,
  };

  for (const width of [1280, 1440, 1919, 1920, 2200]) {
    await page.setViewportSize({ width, height: 900 });
    for (const [name, url] of Object.entries(editors)) {
      await page.goto(url, { waitUntil: 'domcontentloaded' });
      // 等编辑器初始化完成（画布载入）再测量，也避免初始化中途跳页产生的 Alpine 报错
      await expect(page.getByTestId('blox-canvas')).toBeVisible();
      await page.waitForFunction(() => {
        const frame = document.querySelector('[data-testid="blox-canvas"]');
        return Boolean(frame && frame.contentDocument && frame.contentDocument.readyState === 'complete');
      });
      const header = page.locator('.blox-editor-header');
      await expect(header).toBeVisible();
      await expect(page.getByTestId('blox-back')).toBeVisible();
      const layout = await header.evaluate((node) => {
        const groups = Array.from(node.children).filter((child) => child.getBoundingClientRect().width > 0);
        const back = node.querySelector('[data-testid="blox-back"]').getBoundingClientRect();
        const hit = document.elementFromPoint(back.left + back.width / 2, back.top + back.height / 2);
        const boxes = groups.map((child) => child.getBoundingClientRect());
        return {
          backReachable: Boolean(hit && hit.closest('[data-testid="blox-back"]')),
          headerOverflow: node.scrollWidth - node.clientWidth,
          // 左侧品牌组、设备切换、右侧操作组彼此不重叠
          overlaps: boxes.slice(1).filter((box, index) => box.left < boxes[index].right - 1).length,
          actionsRight: Math.round(boxes[boxes.length - 1].right),
        };
      });
      const label = `${name} @ ${width}px`;
      expect(layout.backReachable, `${label}: back link is covered`).toBe(true);
      expect(layout.headerOverflow, `${label}: toolbar overflows`).toBeLessThanOrEqual(1);
      expect(layout.overlaps, `${label}: toolbar groups overlap`).toBe(0);
      expect(layout.actionsRight, `${label}: actions run off screen`).toBeLessThanOrEqual(width);
    }
  }
  await page.setViewportSize({ width: 1440, height: 900 });
  expect(consoleEntries).toEqual([]);
});
