const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
  undo,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

const state = {
  schema: 1,
  revision: 4,
  tokens: [
    { id: 'primary', name: 'Primary', category: 'brand', value: '#3b82f6', status: 'active', locked: true, system: true, version: 1 },
    { id: 'secondary', name: 'Secondary', category: 'brand', value: '#1d4ed8', status: 'active', locked: true, system: true, version: 1 },
    { id: 'c_accent', name: 'Accent', category: 'brand', value: '#16a34a', status: 'active', locked: false, system: false, version: 2 },
    { id: 'c_old', name: 'Old', category: 'brand', value: '#991b1b', status: 'archived', locked: false, system: false, version: 3 },
  ],
  styles: [
    {
      id: 's_card', name: 'Card', category: 'component', color: 'var(--yk-color-c_accent)',
      background: 'var(--yk-color-primary)', border_color: 'var(--yk-color-secondary)', radius: 'md',
      status: 'active', locked: false, version: 1,
    },
  ],
};

test.beforeEach(async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop design-system interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await page.route('**/admin/blox_design_api.php', async (route) => {
    const body = new URLSearchParams(route.request().postData() || '');
    const action = body.get('action');
    expect(['snapshot', 'usage']).toContain(action);
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: '',
        data: action === 'usage' ? {
          tokens: { c_accent: { count: 2, sources: [{ type: 'template', id: 9, label: 'Offer', state: 'published' }] } },
          styles: {},
        } : state,
      }),
    });
  });
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  // TASK-002 第 1 项：状态位现在始终有文案，"脏"改看 data-state 而非可见性
  const leakedDirtyState = (await page.getByTestId('blox-dirty').getAttribute('data-state').catch(() => null)) === 'dirty';
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'design-system E2E must not save or publish').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('token catalog and named preset apply through stable references @ci', async ({ page }) => {
  await page.getByTestId('blox-design-open').click();
  await expect(page.getByTestId('blox-design-token-row')).toHaveCount(3);
  await expect(page.getByTestId('blox-design-usage').filter({ hasText: '2' })).toBeVisible();
  await page.getByTestId('blox-design-tab-styles').click();
  await expect(page.getByTestId('blox-design-style-row')).toHaveCount(1);
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('blox-design-tab-colors')).toBeHidden();

  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-icon').press('Enter');
  await page.getByTestId('blox-style-tab').click();
  // 全局样式选择在默认折叠的专业功能区内（由 yikai-builder 作者端模块提供）。
  await page.getByTestId('blox-professional-features').locator('summary').click();

  await performPreviewUpdate(page, async () => {
    await page.getByTestId('blox-color-picker-trigger').click();
    await expect(page.getByTestId('blox-editor-color-picker')).toBeVisible();
    await page.getByTestId('blox-editor-color-token-c_accent').click();
  });
  const canvas = await frame(page);
  await expect(canvas.locator('[data-yk-el-type="icon"] i')).toHaveAttribute('style', /color:var\(--yk-color-c_accent\)/);
  await page.keyboard.press('Escape');
  await expect(page.getByTestId('blox-editor-color-picker')).toBeHidden();

  await performPreviewUpdate(page, () => page.getByTestId('blox-global-style-select').selectOption('s_card'));
  const styled = canvas.locator('[data-yk-global-style="s_card"]');
  await expect(styled).toHaveCount(1);
  await expect(styled).toHaveAttribute('style', /background-color:var\(--yk-color-primary\)!important/);

  await expect(page.getByTestId('blox-style-binding-remove')).toBeVisible();
  await performPreviewUpdate(page, () => page.getByTestId('blox-style-binding-remove').click());
  await expect(page.getByTestId('blox-global-style-select')).toHaveValue('');
  await expect(page.getByTestId('blox-style-binding-remove')).toBeHidden();
  await expect(canvas.locator('[data-yk-global-style="s_card"]')).toHaveCount(0);
  await expect(canvas.locator('[data-yk-el-type="icon"] i')).toHaveAttribute('style', /color:var\(--yk-color-c_accent\)/);

  await undo(page);
  await expect(page.getByTestId('blox-global-style-select')).toHaveValue('s_card');
  await expect(canvas.locator('[data-yk-global-style="s_card"]')).toHaveCount(1);

  await restoreClean(page);
});

test('stored style sources reset only the local value and undo restores it @ci', async ({ page }, info) => {
  await page.getByTestId('blox-design-open').click();
  await expect(page.getByTestId('blox-design-token-row')).toHaveCount(3);
  await page.keyboard.press('Escape');
  await addTemporaryHeading(page);
  await page.getByTestId('blox-style-tab').click();
  await page.getByTestId('blox-professional-features').locator('summary').click();
  const source = page.getByTestId('blox-style-source-color');
  await expect(source).toHaveAttribute('data-origin', '');   // 没有任何一层给颜色赋值：不显示来源行
  await performPreviewUpdate(page, async () => {
    await page.locator('[data-control-key="color"]').getByTestId('blox-color-picker-trigger').click();
    await page.getByTestId('blox-editor-color-token-primary').click();
  });
  await page.keyboard.press('Escape');
  await expect(source).toHaveAttribute('data-origin', 'local');
  await expect(source).toBeVisible();
  const heading = (await frame(page)).locator('[data-yk-el-type="heading"] h2').last();
  const localColor = await heading.evaluate(el => getComputedStyle(el).color);

  // 恢复继承：只删本元素的值，可撤销
  await performPreviewUpdate(page, () => page.getByTestId('blox-style-source-reset-color').click());
  await expect(source).toHaveAttribute('data-origin', '');
  await undo(page);
  await expect(source).toHaveAttribute('data-origin', 'local');

  // 样式预设（内联 !important）压过本元素的值：来源行如实说明，并列出被覆盖的本元素值
  await performPreviewUpdate(page, () => page.getByTestId('blox-global-style-select').selectOption('s_card'));
  await expect(source).toHaveAttribute('data-origin', 'preset');
  await expect(source).toHaveAttribute('data-overridden', 'local');
  await expect(source).toContainText('Card');
  await expect(page.getByTestId('blox-style-source-reset-color')).toBeHidden();
  await page.evaluate(() => { window.Alpine.$data(document.body).designSystem.styles[0].status = 'archived'; });
  await expect(source).toHaveAttribute('data-origin', 'preset');   // 归档后仍按快照渲染
  await info.attach('style-source-preset', { body: await source.screenshot(), contentType: 'image/png' });
  await performPreviewUpdate(page, () => page.getByTestId('blox-style-binding-remove').click());
  await expect(source).toHaveAttribute('data-origin', 'local');
  await expect(heading).toHaveCSS('color', localColor);
  await restoreClean(page);
});

test('style origin names the class and theme layers, flags overrides and restores inheritance @ci', async ({ page }, info) => {
  await addTemporaryHeading(page);
  await page.getByTestId('blox-style-tab').click();
  const source = page.getByTestId('blox-style-source-type_font_size');
  const app = fn => page.evaluate(fn);
  // 主题：设计系统里 H2 的字号
  await app(() => { const a = window.Alpine.$data(document.body); a.designSystem.theme = { typography: { h2: { size: { d: 30 } } }, buttons: {}, layout: {} }; });
  await expect(source).toHaveAttribute('data-origin', 'theme');
  await expect(source).toContainText('H2');
  await expect(source).toContainText('30');
  // 全局类：压过主题
  await app(() => {
    const a = window.Alpine.$data(document.body);
    a.designSystem.classes = Object.assign({}, a.designSystem.classes, { gc_e2e0origin01: { name: 'e2e-origin', settings: { font_size_px: 22 } } });
    a.selEl.data._classes = ['gc_e2e0origin01'];
  });
  await expect(source).toHaveAttribute('data-origin', 'class');
  await expect(source).toHaveAttribute('data-overridden', 'theme');
  await expect(source).toContainText('.e2e-origin');
  await expect(source).toContainText('22px');
  // 本元素的值：压过类和主题，标出「已覆盖」，可以恢复
  await app(() => { const a = window.Alpine.$data(document.body); a.selEl.data.type_font_size = { d: '26' }; });
  await expect(source).toHaveAttribute('data-origin', 'local');
  await expect(source).toHaveAttribute('data-overridden', 'class theme');
  await expect(page.getByTestId('blox-style-overrides-type_font_size')).toContainText('.e2e-origin');
  await info.attach('style-origin-overrides', { body: await source.screenshot(), contentType: 'image/png' });
  // 平板沿用桌面
  await page.getByTestId('blox-control-type_font_size-device-tablet').click();
  await expect(source).toHaveAttribute('data-origin', 'inherit');
  await page.getByTestId('blox-control-type_font_size-device-desktop').click();
  await page.getByTestId('blox-style-source-reset-type_font_size').click();
  await expect(source).toHaveAttribute('data-origin', 'class');
  await undo(page);
  await expect(source).toHaveAttribute('data-origin', 'local');
  await app(() => { const a = window.Alpine.$data(document.body); a.selEl.data._classes = []; delete a.selEl.data.type_font_size; });
  await restoreClean(page);
});

test('container background and radius keep their own source and reset independently @ci', async ({ page }, info) => {
  await addTemporaryHeading(page);
  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-container').press('Enter');
  await page.getByTestId('blox-style-tab').click();
  const radiusSource = page.getByTestId('blox-style-source-radius');
  const backgroundSource = page.getByTestId('blox-style-source-bg_color');
  await expect(radiusSource).toHaveAttribute('data-origin', 'default');
  await expect(backgroundSource).toHaveAttribute('data-origin', '');
  await performPreviewUpdate(page, () => page.getByTestId('blox-container-radius-xl').click());
  await expect(radiusSource).toHaveAttribute('data-origin', 'local');
  await performPreviewUpdate(page, async () => {
    await page.locator('[data-control-key="bg_color"]').getByTestId('blox-color-picker-trigger').click();
    await page.getByTestId('blox-editor-color-token-primary').click();
  });
  await page.keyboard.press('Escape');
  await expect(backgroundSource).toHaveAttribute('data-origin', 'local');
  await performPreviewUpdate(page, () => page.getByTestId('blox-style-source-reset-bg_color').click());
  await expect(backgroundSource).toHaveAttribute('data-origin', '');
  await expect(radiusSource).toHaveAttribute('data-origin', 'local');
  await undo(page);
  await expect(backgroundSource).toHaveAttribute('data-origin', 'local');
  await info.attach('container-style-sources', { body: await page.getByTestId('blox-property-scroll').screenshot(), contentType: 'image/png' });
  await restoreClean(page);
});

test('archiving a token keeps its frontend CSS tombstone @local', async ({ page }) => {
  await page.unroute('**/admin/blox_design_api.php');
  const boot = await page.evaluate(() => {
    const data = window.Alpine.$data(document.body);
    return { csrf: data.csrf, revision: data.designSystem.revision };
  });
  const marker = `E2E ${Date.now()}`;
  const addedResponse = await page.request.post('/admin/blox_design_api.php', {
    form: {
      action: 'token_add', revision: String(boot.revision), _token: boot.csrf,
      name: marker, category: 'test', value: '#123abc',
    },
  });
  const added = await addedResponse.json();
  expect(added.code).toBe(0);
  const token = added.data.tokens.find((item) => item.name === marker);
  expect(token && token.id).toMatch(/^c_[a-f0-9]{12}$/);

  const archivedResponse = await page.request.post('/admin/blox_design_api.php', {
    form: {
      action: 'token_archive', revision: String(added.data.revision), _token: boot.csrf, id: token.id,
    },
  });
  const archived = await archivedResponse.json();
  expect(archived.code).toBe(0);
  expect(archived.data.tokens.find((item) => item.id === token.id).status).toBe('archived');

  const frontend = await page.request.get(`/?preview=1&v=${Date.now()}`);
  expect(frontend.ok()).toBe(true);
  expect(await frontend.text()).toContain(`--yk-color-${token.id}:#123abc;`);
});
