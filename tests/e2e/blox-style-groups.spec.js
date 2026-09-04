const { test, expect } = require('@playwright/test');
const { openEditor, addTemporaryHeading, observeConsole, restoreClean, frame, performPreviewUpdate } = require('./helpers');

// 样式页签分组（通用背景规划第 2 轮）：chip 切分通用控件循环、有值圆点、
// 切组不改文档。heading 的样式控件 = 常规（align/color）+ 动画组 → 恰好两组。
// 桌面交互基线（先例：blox-editor.spec.js 的 desktop interaction baseline）。
test.beforeEach(({}, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
});

test('style tab partitions controls into groups with has-value dots @ci', async ({ page }) => {
  const errors = observeConsole(page);
  await openEditor(page);
  await addTemporaryHeading(page);
  await page.getByTestId('blox-style-tab').click();

  // chip 条出现：常规 + 动画；背景 chip 对 heading 不出现（无 background 组控件）
  await expect(page.getByTestId('blox-style-groups')).toBeVisible();
  await expect(page.getByTestId('blox-style-group-general')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-style-group-animation')).toBeVisible();
  await expect(page.getByTestId('blox-style-group-background')).toBeHidden();

  // 常规组：文字颜色可见、动画控件不渲染
  await expect(page.locator('[data-control-key="color"]')).toBeVisible();
  await expect(page.locator('[data-control-key="animation"]')).toHaveCount(0);

  // 切到动画组：控件互换；切组本身不改文档
  const original = await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections));
  await page.getByTestId('blox-style-group-animation').click();
  await expect(page.locator('[data-control-key="animation"]')).toBeVisible();
  await expect(page.locator('[data-control-key="color"]')).toHaveCount(0);
  expect(await page.evaluate(() => JSON.stringify(window.Alpine.$data(document.body).sections))).toBe(original);

  // 圆点：设动画前后（页签点 + 组点）
  await expect(page.getByTestId('blox-style-tab-dot')).toBeHidden();
  await expect(page.getByTestId('blox-style-group-dot-animation')).toBeHidden();
  // 只有 option_icons 的选项按钮带 aria-pressed（设备切换按钮 x-show 隐藏但在 DOM）
  await page.locator('[data-control-key="animation"] button[aria-pressed]').nth(1).click();
  await expect(page.getByTestId('blox-style-group-dot-animation')).toBeVisible();
  await expect(page.getByTestId('blox-style-tab-dot')).toBeVisible();

  // 搜索时回落平铺：chip 条消失、分组过滤停用（'o' 同时命中 color 与 animation 的 key）
  const search = page.locator('[x-model="ctrlQuery"]');
  await search.fill('o');
  await expect(page.getByTestId('blox-style-groups')).toBeHidden();
  await expect(page.locator('[data-control-key="color"]')).toBeVisible();
  await expect(page.locator('[data-control-key="animation"]')).toBeVisible();
  await search.fill('');
  await expect(page.getByTestId('blox-style-groups')).toBeVisible();

  await restoreClean(page);
  expect(errors).toEqual([]);
});

// 第 3 轮：card 只有 背景+动画、无 常规组——effectiveStyleGroup 须落到第一组，
// 背景 chip 默认生效且 bg_color 控件可见（root 批次的编辑器回归锚点）。
test('elements without a general group default to their first group @ci', async ({ page }) => {
  const errors = observeConsole(page);
  await openEditor(page);
  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-card').press('Enter');
  await page.getByTestId('blox-style-tab').click();

  await expect(page.getByTestId('blox-style-groups')).toBeVisible();
  await expect(page.getByTestId('blox-style-group-general')).toBeHidden();
  await expect(page.getByTestId('blox-style-group-background')).toHaveAttribute('aria-pressed', 'true');
  await expect(page.locator('[data-control-key="bg_color"]')).toBeVisible();
  await expect(page.locator('[data-control-key="animation"]')).toHaveCount(0);

  await page.getByTestId('blox-style-group-animation').click();
  await expect(page.locator('[data-control-key="animation"]')).toBeVisible();
  await expect(page.locator('[data-control-key="bg_color"]')).toHaveCount(0);

  await restoreClean(page);
  expect(errors).toEqual([]);
});

// 第 4 轮：容器不出 chips（专用样式块管布局），但共享背景组经通用循环到达——
// 色/图控件可见，遮罩因 visible_when（未设图）隐藏。
test('container gets the shared background group without chips @ci', async ({ page }) => {
  const errors = observeConsole(page);
  await openEditor(page);
  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-container').press('Enter');
  await page.getByTestId('blox-style-tab').click();

  await expect(page.getByTestId('blox-style-groups')).toBeHidden();
  await expect(page.locator('[data-control-key="bg_color"]')).toBeVisible();
  await expect(page.locator('[data-control-key="bg_image"]')).toBeVisible();
  await expect(page.locator('[data-control-key="bg_overlay"]')).toHaveCount(0);

  // 第 5 轮：设背景视频 → 遮罩控件出现（or 关系），画布出现媒体层且不截点击
  await performPreviewUpdate(page, () =>
    page.locator('[data-control-key="bg_video"] input').fill('/uploads/e2e-bg.mp4'));
  await expect(page.locator('[data-control-key="bg_overlay"]')).toBeVisible();
  await expect(page.locator('[data-control-key="bg_video_mobile_mode"]')).toBeVisible();
  await expect(page.getByTestId('blox-element-background-image-help')).toBeVisible();
  await performPreviewUpdate(page, () =>
    page.locator('[data-control-key="bg_image"] input').fill('/assets/images/demo/about-office.jpg'));
  const media = (await frame(page)).locator('.blox-bg-media').first();
  await expect(media).toBeAttached();
  await expect(media.locator('video')).toHaveAttribute('data-blox-mobile-video', 'poster');
  await expect(media.locator('video')).toHaveAttribute('poster', '/assets/images/demo/about-office.jpg');
  expect(await media.evaluate((el) => getComputedStyle(el).pointerEvents)).toBe('none');

  await restoreClean(page);
  // 视频 URL 是本用例故意填的占位（仓库无 mp4 fixture），其 404 属预期；其余控制台错误仍须为零
  expect(errors.filter((e) => !/404/.test(e))).toEqual([]);
});

test('section background video is visible at the section layer @ci', async ({ page }) => {
  const errors = observeConsole(page);
  await openEditor(page);
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-style-tab').click();

  const videoInput = page.getByTestId('blox-section-bg-video');
  await expect(videoInput).toBeVisible();
  await performPreviewUpdate(page, () => videoInput.fill('/uploads/e2e-section-bg.mp4'));
  await expect(page.getByTestId('blox-section-background-image-help')).toBeVisible();
  await performPreviewUpdate(page, () => page.getByTestId('blox-section-bg-image').fill('/assets/images/demo/about-office.jpg'));
  await expect(page.getByTestId('blox-section-overlay-opacity')).toBeVisible();
  const video = (await frame(page)).locator('section .blox-bg-media video').first();
  await expect(video).toHaveAttribute('src', '/uploads/e2e-section-bg.mp4');
  await expect(video).toHaveAttribute('poster', '/assets/images/demo/about-office.jpg');
  await expect(video).toHaveAttribute('data-blox-mobile-video', 'poster');

  await performPreviewUpdate(page, () => page.getByTestId('blox-section-bg-video-mobile').selectOption('video'));
  await expect((await frame(page)).locator('section .blox-bg-media video').first())
    .toHaveAttribute('data-blox-mobile-video', 'video');

  await restoreClean(page);
  expect(errors.filter((e) => !/404/.test(e))).toEqual([]);
});

test('section video warns about child backgrounds and clears them as one undoable action @ci', async ({ page }) => {
  const errors = observeConsole(page);
  await openEditor(page);
  await page.getByTestId('blox-tree-section').first().click();
  await page.getByTestId('blox-style-tab').click();
  await performPreviewUpdate(page, () => page.getByTestId('blox-section-bg-video').fill('/uploads/e2e-section-bg.mp4'));

  const state = await page.evaluate(async () => {
    const app = window.Alpine.$data(document.body);
    app.sel.settings.container_bg = '#ffffff';
    const child = app.sel.columns.flatMap((column) => column.elements || [])[0];
    child.data.bg_image = '/assets/images/demo/about-office.jpg';
    await window.Alpine.nextTick();
    return { sectionId: app.sel.id, childType: child.type };
  });
  expect(state.sectionId).toBeTruthy();
  expect(state.childType).toBeTruthy();

  const warning = page.getByTestId('blox-bg-video-obstruction');
  await expect(warning).toBeVisible();
  await expect(warning).toContainText('2');
  await page.getByTestId('blox-clear-bg-video-obstructions').click();
  await expect(warning).toBeHidden();
  expect(await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const child = app.sel.columns.flatMap((column) => column.elements || [])[0];
    return {
      video: app.sel.settings.bg_video,
      container: app.sel.settings.container_bg,
      child: child.data.bg_image,
    };
  })).toEqual({ video: '/uploads/e2e-section-bg.mp4', container: '', child: '' });

  await restoreClean(page);
  expect(errors.filter((e) => !/404/.test(e))).toEqual([]);
});
