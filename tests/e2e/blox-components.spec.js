// v2.1 组件（RFC-2）验收：组件库插入、实例属性覆盖 / 重置、母版发布后实例跟随（覆盖保持）、脱离、母版里导出属性。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, frame, waitPreviewSettled } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'components-fixture.php'), ...args], { cwd: root, encoding: 'utf8' });

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'component authoring baseline');
  test.setTimeout(120000);
});

const app = (page, fn, arg) => page.evaluate(([source, value]) => {
  // eslint-disable-next-line no-new-func
  return new Function('a', 'v', `return (${source})(a, v);`)(window.Alpine.$data(document.body), value);
}, [fn.toString(), arg]);

async function selectNode(page, id, selector) {
  const canvas = await frame(page);
  await expect(async () => {
    await canvas.locator(`[data-yk-el-id="${id}"] ${selector}`).first().dispatchEvent('click');
    expect(await app(page, a => (a.selEl ? a.selEl.id : ''))).toBe(id);
  }).toPass({ timeout: 10000 });
}

async function publishPage(page) {
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-publish-page').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/, { timeout: 15000 });
}

test('instance props override, master updates follow, reset and detach @ci', async ({ page }) => {
  await openPageEditor(page, state.page);
  const canvas = await frame(page);
  await expect(canvas.locator(`[data-yk-component="${state.uuid}"]`)).toContainText('Fixture product');

  // 组件库：已发布的母版出现在「组件」标签里，插入后画布多一个实例
  await app(page, a => a.deselectAll());
  await page.getByTestId('blox-lib-tab-components').click();
  const card = page.getByTestId(`blox-insert-component-${state.uuid}`);
  await expect(card).toBeVisible();
  await app(page, a => a.selectSection(0, false));
  await app(page, a => { a.libOpen = true; });
  await card.click();
  await expect.poll(() => app(page, (a, uuid) => a.sections[0].columns[0].elements
    .filter(el => el.type === 'component' && el.data.component === uuid).length, state.uuid)).toBe(2);
  await waitPreviewSettled(page, 10000);
  await expect(canvas.locator(`[data-yk-component="${state.uuid}"]`)).toHaveCount(2);
  // 新插的实例不留下：撤销，回到只有夹具实例
  await app(page, a => a.undo());
  await expect.poll(() => app(page, (a, uuid) => a.sections[0].columns[0].elements
    .filter(el => el.type === 'component' && el.data.component === uuid).length, state.uuid)).toBe(1);

  // 实例属性：覆盖按钮文字，面板出现「重置」
  await selectNode(page, 'cfp-instance', 'h3');
  await expect(page.getByTestId('blox-component-instance')).toBeVisible();
  await expect(page.getByTestId('blox-component-prop-title')).toBeVisible();
  const cta = page.getByTestId('blox-component-input-cta');
  await expect(cta).toHaveValue('Learn more');
  await cta.fill('Ask now');
  await cta.press('Tab');
  await expect(page.getByTestId('blox-component-reset-cta')).toBeVisible();
  expect(await app(page, a => a.selEl.data.props)).toEqual({ cta: 'Ask now' });
  await waitPreviewSettled(page, 10000);
  await expect(canvas.locator(`[data-yk-component="${state.uuid}"]`)).toContainText('Ask now');

  await publishPage(page);
  await page.goto(state.url);
  await expect(page.locator(`[data-yk-component="${state.uuid}"]`)).toContainText('Ask now');

  // 母版改默认标题并发布：没覆盖的标题跟着变，覆盖过的按钮文字保持
  fixture('republish', 'Updated product');
  await page.goto(state.url);
  const front = page.locator(`[data-yk-component="${state.uuid}"]`);
  await expect(front).toContainText('Updated product');
  await expect(front).toContainText('Ask now');

  // 重置：删掉覆盖值，回到母版默认
  await openPageEditor(page, state.page);
  await selectNode(page, 'cfp-instance', 'h3');
  await page.getByTestId('blox-component-reset-cta').click();
  expect(await app(page, a => a.selEl.data.props)).toEqual({});
  await expect(page.getByTestId('blox-component-input-cta')).toHaveValue('Learn more');

  // 脱离：实例原地变成普通容器，内容保留，不再是组件
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-component-detach').click();
  await expect.poll(() => app(page, a => a.selEl && a.selEl.type)).toBe('container');
  const detached = await app(page, a => a.selEl.data.children.map(child => child.data.text));
  expect(detached).toEqual(['Updated product', 'Learn more']);
  await expect(page.getByTestId('blox-component-instance')).toHaveCount(0);
});

test('master editor exposes and removes a prop from a control @ci', async ({ page }) => {
  await page.goto(`/admin/blox_editor.php?template=${state.template}`, { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.getByTestId('blox-component-props')).toBeVisible();
  // 母版里不能再放组件：没有「组件」标签
  await expect(page.getByTestId('blox-lib-tab-components')).toBeHidden();
  expect(await app(page, a => a.masterProps().map(p => p.key))).toEqual(['title', 'cta']);

  // 标题的文字已导出为 title 属性：图标按下；链接字段还没导出
  await selectNode(page, 'cf-title', 'h3');
  await expect(page.getByTestId('blox-component-expose-text')).toHaveAttribute('aria-pressed', 'true');
  const expose = page.getByTestId('blox-component-expose-url');
  await expect(expose).toHaveAttribute('aria-pressed', 'false');
  await expose.click();
  await expect(expose).toHaveAttribute('aria-pressed', 'true');
  const added = await app(page, a => a.masterProps()[2]);
  expect(added).toMatchObject({ key: 'url', type: 'url', targets: [{ node: 'cf-title', field: 'url' }] });
  expect(await app(page, a => a.dirty)).toBe(true);

  await expose.click();
  expect(await app(page, a => a.masterProps().map(p => p.key))).toEqual(['title', 'cta']);
});
