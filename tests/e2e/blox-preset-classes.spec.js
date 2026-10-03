// 2.0.4 样式预设收编为全局类（RFC-1 第 4 点）：首次进设计系统页即一次性转换，
// 前台外观逐值不变（含预设压过本地颜色的优先级），编辑器打开文档时预设引用改写为类引用且不算未保存改动。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, expectClean } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'preset-class-fixture.php'), action], { cwd: root, encoding: 'utf8' });
const props = ['color', 'background-color', 'border-top-color', 'border-top-width', 'border-top-style', 'border-top-left-radius'];

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'preset conversion baseline');
  test.setTimeout(120000);
});

async function frontStyles(browser, baseURL) {
  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const visitor = await context.newPage();
    expect((await visitor.goto(state.url)).status()).toBe(200);
    const heading = visitor.locator('h2', { hasText: 'Preset heading A' });
    await expect(heading).toBeVisible();
    return {
      className: await heading.getAttribute('class'),
      presetAttr: await heading.getAttribute('data-yk-global-style'),
      values: await heading.evaluate((el, names) => {
        const style = getComputedStyle(el);
        return Object.fromEntries(names.map(name => [name, style.getPropertyValue(name)]));
      }, props),
    };
  } finally {
    await context.close();
  }
}

test('presets convert to equivalent classes and pages keep their look @ci', async ({ page, browser, baseURL }) => {
  const before = await frontStyles(browser, baseURL);
  expect(before.presetAttr).toBe('s_e2e_card');
  expect(before.values.color).toBe('rgb(154, 52, 18)');

  await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
  await page.getByTestId('blox-design-page-tab-styles').click();
  await expect(page.getByTestId('blox-design-presets-converted')).toBeVisible();
  await expect(page.getByTestId('blox-design-page-add-style')).toHaveCount(0);
  await expect(page.getByTestId('blox-design-style-class').first()).toHaveText('→ .yk-c-preset-e2e-card');

  const after = await frontStyles(browser, baseURL);
  expect(after.presetAttr).toBeNull();
  expect(after.className).toMatch(/\byk-c-preset-e2e-card\b/);
  expect(after.values, '转换前后前台计算值逐项一致（预设仍压过本地颜色）').toEqual(before.values);
});

test('opening the editor rewrites the preset reference to the class without dirtying the document @ci', async ({ page }) => {
  await openPageEditor(page, state.page);
  const data = await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    return app.sections[0].columns[0].elements[0].data;
  });
  expect(data._global_style).toBeUndefined();
  expect(data._global_style_snapshot).toBeUndefined();
  expect(data._classes).toHaveLength(1);
  expect(data._classes[0]).toMatch(/^gc_[a-f0-9]{12}$/);
  await expectClean(page);
});
