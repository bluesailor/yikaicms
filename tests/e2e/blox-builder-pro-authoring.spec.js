// V2.0.0 建站人员：元素「高级」面板（ID / 类 / 属性 / 自定义 CSS）、页面 CSS、类的自定义 CSS、页面切换器。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, frame, expectClean } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'global-class-fixture.php'), action], { cwd: root, encoding: 'utf8' });

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'builder authoring baseline');
  test.setTimeout(120000);
});

const pagePreview = page => page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_page_api.php'
  && new URLSearchParams(r.request().postData() || '').get('action') === 'preview');

async function selectHeading(page, id) {
  const canvas = await frame(page);
  // 画布 iframe 用 CSS zoom 适配宽度：直接派发 DOM click；桥接脚本就绪前的点击会被忽略，未选中就重发
  await expect(async () => {
    await canvas.locator(`[data-yk-el-id="${id}"] :is(h2, h3)`).first().dispatchEvent('click');
    expect(await page.evaluate(() => {
      const app = window.Alpine.$data(document.body);
      return app.selEl ? app.selEl.id : '';
    })).toBe(id);
  }).toPass({ timeout: 10000 });
  await page.getByTestId('blox-style-tab').click();
}

async function fillAndCommit(page, locator, value) {
  const preview = pagePreview(page);
  await locator.fill(value);
  await locator.press('Tab');
  await preview;
}

test('element advanced panel writes ID, classes, attributes and scoped CSS; page CSS applies; both publish @ci', async ({ page, browser, baseURL }, info) => {
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-a');
  const panel = page.getByTestId('blox-element-advanced');
  await panel.locator('summary').click();

  await fillAndCommit(page, page.getByTestId('blox-advanced-id'), 'e2e-anchor');
  await fillAndCommit(page, page.getByTestId('blox-advanced-classes'), 'e2e-card  md:flex yk-c-fake');
  await page.getByTestId('blox-advanced-attribute-add').click();
  const attribute = page.getByTestId('blox-advanced-attribute').first();
  await attribute.locator('input').nth(0).fill('data-track');
  await attribute.locator('input').nth(0).press('Tab');
  await fillAndCommit(page, attribute.locator('input').nth(1), 'hero');

  // 非法 CSS：即时提示原因，不写入文档
  const css = page.getByTestId('blox-advanced-css');
  await css.fill('%root% { background: url(https://evil.test/x) }');
  await expect(page.getByTestId('blox-advanced-css-error')).toBeVisible();
  await css.press('Tab');
  expect(await page.evaluate(() => window.Alpine.$data(document.body).selEl.data._custom_css)).toBeUndefined();

  await fillAndCommit(page, css, '%root% { letter-spacing: 3px }');
  await expect(page.getByTestId('blox-advanced-css-error')).toBeHidden();
  const canvas = await frame(page);
  const heading = canvas.locator('#e2e-anchor');
  await expect(heading).toHaveCount(1);
  await expect(heading).toHaveAttribute('data-track', 'hero');
  await expect(heading).toHaveClass(/\be2e-card\b/);
  await expect(heading).toHaveClass(/md:flex/);
  await expect(heading).not.toHaveClass(/yk-c-fake/);
  await expect.poll(() => heading.evaluate(el => getComputedStyle(el).letterSpacing)).toBe('3px');
  await info.attach('advanced-panel', { body: await page.getByTestId('blox-property-scroll').screenshot(), contentType: 'image/png' });

  // 页面 CSS：对话框里写，应用后进文档设置，画布即时生效
  await page.getByTestId('blox-page-code-open').click();
  await expect(page.getByTestId('blox-page-code-dialog')).toBeVisible();
  await page.getByTestId('blox-page-code-css').fill('.e2e-card { outline: 2px solid rgb(255, 0, 0) }');
  await info.attach('page-code-dialog', { body: await page.getByTestId('blox-page-code-dialog').locator('div.relative').first().screenshot(), contentType: 'image/png' });
  const preview = pagePreview(page);
  await page.getByTestId('blox-page-code-apply').click();
  await preview;
  await expect.poll(async () => (await frame(page)).locator('#e2e-anchor').evaluate(el => getComputedStyle(el).outlineColor)).toBe('rgb(255, 0, 0)');

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
    const front = await context.newPage();
    expect((await front.goto(state.url)).status()).toBe(200);
    const published = front.locator('#e2e-anchor');
    await expect(published).toHaveAttribute('data-track', 'hero');
    const styles = await published.evaluate(el => ({ spacing: getComputedStyle(el).letterSpacing, outline: getComputedStyle(el).outlineColor }));
    expect(styles).toEqual({ spacing: '3px', outline: 'rgb(255, 0, 0)' });
    await expect(front.locator('style[data-yk-custom-css]')).toHaveCount(1);
    await info.attach('front-custom-css', { body: await published.screenshot(), contentType: 'image/png' });
  } finally {
    await context.close();
  }
});

test('a global class carries its own custom CSS scoped with %root% @ci', async ({ page }) => {
  await openPageEditor(page, state.page);
  await selectHeading(page, 'gcf-heading-b');
  await page.getByTestId('blox-style-target-add').click();
  await page.getByTestId('blox-class-find').fill('e2e-code');
  await page.getByTestId('blox-class-create').click();
  await expect(page.getByTestId('blox-style-target-class-e2e-code')).toHaveAttribute('aria-pressed', 'true');

  const field = page.getByTestId('blox-class-input-custom_css');
  const preview = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_class_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === 'class_preview');
  await field.fill('%root% { text-decoration: underline }');
  await field.press('Tab');
  await preview;
  const canvas = await frame(page);
  await expect.poll(() => canvas.locator('[data-yk-el-id="gcf-heading-b"] h2').evaluate(el => getComputedStyle(el).textDecorationLine)).toBe('underline');
  const saved = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_class_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === 'class_update');
  await page.getByTestId('blox-class-save').click();
  expect((await (await saved).json()).code).toBe(0);
  await page.getByTestId('blox-style-target-element').click();
});

test('the header page switcher searches pages and guards unsaved changes @ci', async ({ page }, info) => {
  await openPageEditor(page, state.page);
  const toggle = page.getByTestId('blox-page-switcher-toggle');
  await toggle.click();
  const menu = page.getByTestId('blox-page-switcher-menu');
  await expect(menu).toBeVisible();
  await expect(menu.getByTestId('blox-page-switcher-item').filter({ hasText: 'Global class fixture' })).toHaveAttribute('aria-current', 'page');
  await page.getByTestId('blox-page-switcher-search').fill('global class');
  await expect(menu.getByTestId('blox-page-switcher-item')).toHaveCount(1);
  await page.getByTestId('blox-page-switcher-search').fill('');
  await info.attach('page-switcher', { body: await menu.screenshot(), contentType: 'image/png' });

  // 有未保存的改动：离开前确认，取消就留在原页
  await page.keyboard.press('Escape');
  await selectHeading(page, 'gcf-heading-a');
  // 已有高级配置的元素，面板默认展开
  await expect(page.getByTestId('blox-element-advanced')).toHaveJSProperty('open', true);
  await fillAndCommit(page, page.getByTestId('blox-advanced-classes'), 'e2e-card unsaved-change');
  await toggle.click();
  page.once('dialog', dialog => dialog.dismiss());
  await menu.getByTestId('blox-page-switcher-item').first().click();
  await expect(page).toHaveURL(new RegExp(`id=${state.page}`));

  // 取消离开后菜单仍开着；确认离开：跳到首页编辑器
  await expect(menu).toBeVisible();
  page.once('dialog', dialog => dialog.accept());
  await Promise.all([
    page.waitForURL(/blox_editor\.php\?home=1/),
    menu.getByTestId('blox-page-switcher-item').first().click(),
  ]);
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
});
