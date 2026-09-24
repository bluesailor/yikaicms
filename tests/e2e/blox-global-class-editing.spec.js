// V2.0.0 全局类直接编辑：验收用例 1（两个标题共用一个类，画布与前台计算值一致、改一次两处变）
// 与用例 2 的分档继承、本地值冲突提示/清除、第二编辑器并发 409。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, frame, expectClean } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'global-class-fixture.php'), action], { cwd: root, encoding: 'utf8' });
const CLASS_NAME = 'e2e-title';
const props = ['font-size', 'color', 'margin-top', 'margin-bottom', 'padding-top'];

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'global class editing baseline');
  test.setTimeout(120000);
});

const classAction = (page, action) => page.waitForResponse(response => {
  const url = new URL(response.url());
  return url.pathname === '/admin/blox_class_api.php'
    && new URLSearchParams(response.request().postData() || '').get('action') === action;
});

async function computed(locator) {
  return locator.evaluate((el, names) => {
    const style = getComputedStyle(el);
    return Object.fromEntries(names.map(name => [name, style.getPropertyValue(name)]));
  }, props);
}

async function selectHeading(page, id) {
  const canvas = await frame(page);
  // 画布 iframe 用 CSS zoom 适配宽度，坐标点击会被误判遮挡（见 helpers.openSectionInsertAtEnd），直接派发 DOM click
  // 画布桥接脚本就绪前的点击会被忽略：未选中就重发
  await expect(async () => {
    await canvas.locator(`[data-yk-el-id="${id}"] :is(h2, h3)`).first().dispatchEvent('click');
    expect(await page.evaluate(() => {
      const app = window.Alpine.$data(document.body);
      return app.selEl ? app.selEl.id : '';
    })).toBe(id);
  }).toPass({ timeout: 10000 });
  await page.getByTestId('blox-style-tab').click();
  await expect(page.getByTestId('blox-style-target')).toBeVisible();
}

async function setClassField(page, key, value) {
  const preview = classAction(page, 'class_preview');
  const input = page.getByTestId(`blox-class-input-${key}`);
  await input.fill(String(value));
  await input.press('Tab');
  expect((await (await preview).json()).code).toBe(0);
}

async function saveClass(page) {
  const saved = classAction(page, 'class_update');
  await page.getByTestId('blox-class-save').click();
  return (await saved).json();
}

function heading(canvas, id) {
  return canvas.locator(`[data-yk-el-id="${id}"] h2`).first();
}

test('two headings share one class: canvas and published page compute the same values, one edit changes both @ci', async ({ page, browser, baseURL }, info) => {
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-a');

  // 新建类：查找框输入新类名 → 创建 → 自动挂到本元素并成为编辑目标
  await page.getByTestId('blox-style-target-add').click();
  await page.getByTestId('blox-class-find').fill(CLASS_NAME);
  const created = classAction(page, 'class_add');
  await page.getByTestId('blox-class-create').click();
  expect((await (await created).json()).code).toBe(0);
  await expect(page.getByTestId(`blox-style-target-class-${CLASS_NAME}`)).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-class-style-form')).toBeVisible();
  // 选中类时元素自身的样式控件让位
  await expect(page.locator('[data-testid="blox-property-scroll"] [data-control-key="color"]')).toHaveCount(0);

  await setClassField(page, 'font_size_px', 36);
  await setClassField(page, 'text_color', '#c2410c');
  await setClassField(page, 'margin_top_px', 24);
  await setClassField(page, 'margin_bottom_px', 32);
  await setClassField(page, 'padding_top_px', 8);
  const canvas = await frame(page);
  const expected = { 'font-size': '36px', color: 'rgb(194, 65, 12)', 'margin-top': '24px', 'margin-bottom': '32px', 'padding-top': '8px' };
  await expect.poll(() => computed(heading(canvas, 'gcf-heading-a'))).toEqual(expected);
  expect((await saveClass(page)).code).toBe(0);
  await expect(page.getByTestId('blox-class-save')).toBeDisabled();

  // 第二个标题只挂同一个类，不填任何值
  await selectHeading(page, 'gcf-heading-b');
  await page.getByTestId('blox-style-target-add').click();
  await page.getByTestId('blox-class-find').fill(CLASS_NAME);
  await page.getByTestId('blox-class-find').press('Enter');
  await expect(page.getByTestId(`blox-style-target-class-${CLASS_NAME}`)).toHaveAttribute('aria-pressed', 'true');
  await expect(page.getByTestId('blox-class-conflict')).toHaveCount(0);
  await expect.poll(async () => computed(heading(await frame(page), 'gcf-heading-b'))).toEqual(expected);
  await info.attach('editor-class-form', { body: await page.getByTestId('blox-property-scroll').screenshot(), contentType: 'image/png' });
  await info.attach('canvas-two-headings', { body: await page.getByTestId('blox-canvas').screenshot(), contentType: 'image/png' });

  const canvasA = await computed(heading(await frame(page), 'gcf-heading-a'));
  const canvasB = await computed(heading(await frame(page), 'gcf-heading-b'));

  // 保存并发布页面（挂类随页面保存）
  for (const [action, button] of [['save_draft', 'blox-save'], ['publish', 'blox-publish-page']]) {
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_page_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === action);
    if (action === 'publish') page.once('dialog', dialog => dialog.accept());
    await page.getByTestId(button).click();
    expect((await (await response).json()).code).toBe(0);
  }
  await expectClean(page);

  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const visitor = await context.newPage();
    expect((await visitor.goto(state.url)).status()).toBe(200);
    const frontA = visitor.locator('h2', { hasText: 'Class heading A' });
    const frontB = visitor.locator('h2', { hasText: 'Class heading B' });
    await expect(frontA).toHaveClass(new RegExp(`yk-c-${CLASS_NAME}`));
    await expect(frontB).toHaveClass(new RegExp(`yk-c-${CLASS_NAME}`));
    expect(await computed(frontA)).toEqual(canvasA);
    expect(await computed(frontB)).toEqual(canvasB);
    await expect(visitor.locator('link#yk-blox-classes')).toHaveAttribute('href', /\/uploads\/blox\/css\/classes\.css\?v=\d+/);
    await info.attach('front-before', { body: await visitor.screenshot({ fullPage: false }), contentType: 'image/png' });

    // 只改一次类：两个标题一起变，无需重新发布页面
    await selectHeading(page, 'gcf-heading-a');
    await page.getByTestId(`blox-style-target-class-${CLASS_NAME}`).click();
    await setClassField(page, 'font_size_px', 44);
    expect((await saveClass(page)).code).toBe(0);
    await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-b')))['font-size']).toBe('44px');
    await visitor.reload();
    expect((await computed(frontA))['font-size']).toBe('44px');
    expect((await computed(frontB))['font-size']).toBe('44px');
    await info.attach('front-after-one-edit', { body: await visitor.screenshot({ fullPage: false }), contentType: 'image/png' });
  } finally {
    await context.close();
  }
});

test('tiers inherit and clear, local values are flagged and clearable, a second editor gets 409 @ci', async ({ page, baseURL, expectedHttpErrors }, info) => {
  // 本页第二次保存必须被 409 挡下：这是预期的 HTTP 错误，只消费一次
  expectedHttpErrors.push({ status: 409, method: 'POST', action: 'class_update', url: new URL('/admin/blox_class_api.php', baseURL).href });
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-a');
  await page.getByTestId(`blox-style-target-class-${CLASS_NAME}`).click();
  await expect(page.getByTestId('blox-class-style-form')).toBeVisible();

  // 分档：手机档未设时显示继承自桌面；设了手机值只影响手机，清除后回到继承
  await page.getByTestId('blox-device-mobile').click();
  const size = page.getByTestId('blox-class-input-font_size_px');
  await expect(size).toHaveValue('');
  await expect(size).toHaveAttribute('placeholder', /44px/);
  await setClassField(page, 'font_size_px', 28);
  await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-a')))['font-size']).toBe('28px');
  await page.getByTestId('blox-device-desktop').click();
  await expect(size).toHaveValue('44');
  await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-a')))['font-size']).toBe('44px');
  await page.getByTestId('blox-device-mobile').click();
  const cleared = classAction(page, 'class_preview');
  await page.locator('[data-class-field="font_size_px"] button').click();
  await cleared;
  await expect(size).toHaveValue('');
  await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-a')))['font-size']).toBe('44px');
  await page.getByTestId('blox-class-discard').click();
  await page.getByTestId('blox-device-desktop').click();

  // 本地值挡住类：提示来源，可一键清除本地值（页面编辑，可撤销）
  await page.evaluate(() => { window.Alpine.$data(document.body).selEl.data.color = '#111111'; });
  const conflict = page.getByTestId('blox-class-conflict');
  await expect(conflict).toHaveAttribute('data-conflict-key', 'text_color');
  await expect(conflict).toHaveAttribute('data-conflict-by', 'element');
  await conflict.scrollIntoViewIfNeeded();
  await info.attach('class-conflict-hint', { body: await page.getByTestId('blox-property-scroll').screenshot(), contentType: 'image/png' });
  await page.getByTestId('blox-class-clear-local').click();
  await expect(conflict).toHaveCount(0);
  await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-a'))).color).toBe('rgb(194, 65, 12)');

  // 第二编辑器：本页读取后，另一处用同一 revision 先保存；本页再保存必须 409，且不覆盖对方
  await setClassField(page, 'font_size_px', 50);
  const other = await page.evaluate(async (name) => {
    const app = window.Alpine.$data(document.body);
    const row = app.globalClasses.find(item => item.name === name);
    const body = new URLSearchParams({
      action: 'class_update', id: row.class_id, revision: String(row.revision), _token: app.csrf,
      settings: JSON.stringify(Object.assign({}, row.settings, { font_size_px: 60 })),
    });
    const response = await fetch('/admin/blox_class_api.php', { method: 'POST', body });
    return response.json();
  }, CLASS_NAME);
  expect(other.code).toBe(0);
  const conflictSave = await saveClass(page);
  expect(conflictSave.code).toBe(409);
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await expect(size).toHaveValue('60');
  await expect(page.getByTestId('blox-class-save')).toBeDisabled();
  await expect.poll(async () => (await computed(heading(await frame(page), 'gcf-heading-a')))['font-size']).toBe('60px');
  await size.scrollIntoViewIfNeeded();
  await info.attach('class-409-reloaded', { body: await page.screenshot(), contentType: 'image/png' });
});

test('front end: the mobile tier applies on phones, links carry controlled attributes and take keyboard focus @ci', async ({ page, browser, baseURL }, info) => {
  // 手机档只改手机：桌面沿用上一用例留下的 60px（第二编辑器保存的值）
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-a');
  await page.getByTestId(`blox-style-target-class-${CLASS_NAME}`).click();
  await page.getByTestId('blox-device-mobile').click();
  await setClassField(page, 'font_size_px', 30);
  expect((await saveClass(page)).code).toBe(0);
  await page.getByTestId('blox-device-desktop').click();

  const desktop = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 900 } });
  const phone = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: { width: 375, height: 812 }, isMobile: true, hasTouch: true });
  try {
    const front = await desktop.newPage();
    expect((await front.goto(state.url)).status()).toBe(200);
    expect((await computed(front.locator('h2', { hasText: 'Class heading A' })))['font-size']).toBe('60px');

    const headingLink = front.locator('[data-yk-el-id="gcf-link-heading"] a, h3 a.yk-heading-link').first();
    await expect(headingLink).toHaveAttribute('href', '/contact.html');
    await expect(headingLink).toHaveAttribute('target', '_blank');
    await expect(headingLink).toHaveAttribute('rel', 'nofollow noopener');
    await expect(headingLink).toHaveAttribute('title', 'Contact page');
    await expect(headingLink).toHaveAttribute('aria-label', 'Open the contact page');
    const external = front.getByRole('link', { name: 'External button' });
    await expect(external).toHaveAttribute('rel', 'sponsored noopener noreferrer');
    const sameTab = front.getByRole('link', { name: 'Same tab button' });
    await expect(sameTab).not.toHaveAttribute('target', /.*/);
    await expect(sameTab).not.toHaveAttribute('rel', /.*/);
    const imageLink = front.getByRole('link', { name: 'Contact us' });
    await expect(imageLink).toHaveAttribute('href', '/contact.html');
    await expect(front.getByRole('link', { name: 'Unsafe button' })).toHaveAttribute('href', '#');

    // 键盘：Tab 能依次到达三类链接，焦点可见（未被样式去掉 outline）
    const reached = new Set();
    for (let i = 0; i < 80 && reached.size < 3; i += 1) {
      await front.keyboard.press('Tab');
      const focused = await front.evaluate(() => {
        const el = document.activeElement;
        if (!el || el.tagName !== 'A') return null;
        const style = getComputedStyle(el);
        return {
          name: el.getAttribute('aria-label') || el.textContent.trim(),
          visible: el.matches(':focus-visible'), outline: style.outlineStyle, width: style.outlineWidth, color: style.outlineColor,
        };
      });
      if (focused && ['Open the contact page', 'External button', 'Contact us'].includes(focused.name)) {
        expect(focused, `${focused.name} focus ring`).toMatchObject({ visible: true, outline: 'solid', width: '2px' });
        reached.add(focused.name);
        if (focused.name === 'External button') {
          await info.attach('front-link-focus', { body: await front.screenshot({ clip: await external.evaluate(el => { const r = el.getBoundingClientRect(); return { x: Math.max(0, r.x - 40), y: Math.max(0, r.y - 120), width: r.width + 260, height: r.height + 200 }; }) }), contentType: 'image/png' });
          info.annotations.push({ type: 'focus', description: JSON.stringify(focused) });
        }
      }
    }
    expect([...reached].sort()).toEqual(['Contact us', 'External button', 'Open the contact page']);

    const mobile = await phone.newPage();
    expect((await mobile.goto(state.url)).status()).toBe(200);
    const mobileA = mobile.locator('h2', { hasText: 'Class heading A' });
    const mobileB = mobile.locator('h2', { hasText: 'Class heading B' });
    expect((await computed(mobileA))['font-size']).toBe('30px');
    expect((await computed(mobileB))['font-size']).toBe('30px');
    await mobileA.scrollIntoViewIfNeeded();
    await info.attach('front-mobile-tier', { body: await mobile.screenshot(), contentType: 'image/png' });
  } finally {
    await desktop.close();
    await phone.close();
  }
});

test('hover and keyboard-focus states: forced in the canvas while editing, live on the published page @ci', async ({ page, browser, baseURL }, info) => {
  const color = async (locator) => (await computed(locator)).color;
  const background = (locator) => locator.evaluate(el => getComputedStyle(el).backgroundColor);
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-a');
  await page.getByTestId(`blox-style-target-class-${CLASS_NAME}`).click();
  await expect(page.getByTestId('blox-class-state-base')).toHaveAttribute('aria-pressed', 'true');

  // 悬停页签：只列状态字段（没有外边距），填的值在画布里对两个标题强制显示
  const forcedPreview = classAction(page, 'class_preview');
  await page.getByTestId('blox-class-state-hover').click();
  expect(new URLSearchParams((await forcedPreview).request().postData()).get('force_states')).toContain('"hover"');
  await expect(page.getByTestId('blox-class-input-margin_top_px')).toHaveCount(0);
  await expect(page.getByTestId('blox-class-input-text_color')).toHaveValue('');
  await setClassField(page, 'text_color', '#1d4ed8');
  await setClassField(page, 'transition_ms', 150);
  const canvas = await frame(page);
  await expect.poll(() => color(heading(canvas, 'gcf-heading-a'))).toBe('rgb(29, 78, 216)');
  await expect.poll(() => color(heading(canvas, 'gcf-heading-b'))).toBe('rgb(29, 78, 216)');
  await info.attach('editor-hover-state', { body: await page.getByTestId('blox-property-scroll').screenshot(), contentType: 'image/png' });

  // 聚焦页签：换成强制聚焦，悬停颜色随之撤掉
  await page.getByTestId('blox-class-state-focus').click();
  await setClassField(page, 'bg_color', '#fef3c7');
  await expect.poll(() => background(heading(canvas, 'gcf-heading-a'))).toBe('rgb(254, 243, 199)');
  await expect.poll(() => color(heading(canvas, 'gcf-heading-a'))).toBe('rgb(194, 65, 12)');
  expect((await saveClass(page)).code).toBe(0);

  // 回到基础：画布不再强制任何状态
  await page.getByTestId('blox-class-state-base').click();
  await expect.poll(() => background(heading(canvas, 'gcf-heading-a'))).toBe('rgba(0, 0, 0, 0)');
  await expect(page.getByTestId('blox-class-input-margin_top_px')).toBeVisible();

  // 把同一个类挂到带链接的标题上，保存并发布，验证前台的键盘聚焦（焦点在标题里面的链接上）
  await selectHeading(page, 'gcf-link-heading');
  await page.getByTestId('blox-style-target-add').click();
  await page.getByTestId('blox-class-find').fill(CLASS_NAME);
  await page.getByTestId('blox-class-find').press('Enter');
  await expect(page.getByTestId(`blox-style-target-class-${CLASS_NAME}`)).toHaveAttribute('aria-pressed', 'true');
  for (const [action, button] of [['save_draft', 'blox-save'], ['publish', 'blox-publish-page']]) {
    const response = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_page_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === action);
    if (action === 'publish') page.once('dialog', dialog => dialog.accept());
    await page.getByTestId(button).click();
    expect((await (await response).json()).code).toBe(0);
  }

  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] }, viewport: { width: 1440, height: 900 } });
  try {
    const front = await context.newPage();
    expect((await front.goto(state.url)).status()).toBe(200);
    const frontA = front.locator('h2', { hasText: 'Class heading A' });
    expect(await color(frontA)).toBe('rgb(194, 65, 12)');
    await frontA.hover();
    await expect.poll(() => color(frontA)).toBe('rgb(29, 78, 216)');
    await info.attach('front-hover', { body: await frontA.screenshot(), contentType: 'image/png' });

    const linked = front.locator('h3', { hasText: 'Linked heading' });
    await front.mouse.move(0, 0);
    expect(await background(linked)).toBe('rgba(0, 0, 0, 0)');
    let focused = false;
    for (let i = 0; i < 80 && !focused; i += 1) {
      await front.keyboard.press('Tab');
      focused = await front.evaluate(() => document.activeElement && document.activeElement.getAttribute('aria-label') === 'Open the contact page');
    }
    expect(focused).toBe(true);
    await expect.poll(() => background(linked)).toBe('rgb(254, 243, 199)');
    await info.attach('front-keyboard-focus', { body: await linked.screenshot(), contentType: 'image/png' });

    // 焦点离开后状态撤掉
    await front.keyboard.press('Shift+Tab');
    await expect.poll(() => background(linked)).toBe('rgba(0, 0, 0, 0)');
  } finally {
    await context.close();
  }
});
