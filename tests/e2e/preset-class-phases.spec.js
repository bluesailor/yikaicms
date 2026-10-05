// 样式预设转全局类的视觉一致（2.0.5 §5.3a）：一张引用「尚未转换的预设」的存量页面，四个阶段前台计算样式必须逐项相同：
//   1 升级前（预设内联渲染）
//   2 升级后、编辑器没打开过——由设计系统页触发转换，文档不动，渲染期改挂类
//   3 打开编辑器但不保存——编辑器里的文档改写成类引用，前台仍是已发布文档
//   4 打开编辑器并发布——落盘后文档只剩类引用，不再有 _global_style
// 另核对编辑器画布里的样式与前台一致。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { openPageEditor, frame } = require('./helpers');

const root = path.resolve(__dirname, '../..');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'preset-class-phases-fixture.php'), action], { cwd: root, encoding: 'utf8' }).trim().split('\n').pop();
const IDS = ['pp-text', 'pp-box', 'pp-btn'];
const PROPS = ['color', 'background-color', 'border-top-color', 'border-top-left-radius'];

async function sample(target) {
  return target.evaluate(({ ids, props }) => Object.fromEntries(ids.map((id) => {
    const el = document.getElementById(id);
    if (!el) return [id, null];
    // 按钮的样式在里面的链接上；文本的颜色看段落
    const node = el.matches('a, button') ? el : (el.querySelector(':scope > a, :scope > button, :scope > p') && id !== 'pp-box' ? el.querySelector(':scope > a, :scope > button, :scope > p') : el);
    const style = getComputedStyle(node);
    return [id, Object.fromEntries(props.map(name => [name, style.getPropertyValue(name).trim()]))];
  })), { ids: IDS, props: PROPS });
}

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed'));
});
test.afterAll(() => fixture('restore'));

test('preset pages look the same before and after presets become classes @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough for computed colours');
  test.setTimeout(120000);
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const front = await visitor.newPage();
  const frontSample = async () => {
    await front.goto(state.url + '?phase=' + Date.now(), { waitUntil: 'load' });
    return sample(front);
  };
  try {
    // 1 升级前：预设内联
    expect(JSON.parse(fixture('doc')).class_id).toBe('');
    const before = await frontSample();
    for (const id of IDS) expect(before[id], id).not.toBeNull();
    expect(before['pp-box']['background-color']).toBe('rgb(245, 239, 230)');   // 预设确实生效了

    // 2 升级后、编辑器没打开：设计系统页触发转换，文档还是预设引用
    await page.goto('/admin/blox_design.php', { waitUntil: 'domcontentloaded' });
    const converted = JSON.parse(fixture('doc'));
    expect(converted.class_id).not.toBe('');
    expect(converted['pp-text']._global_style).toBe('brand_card');
    expect(await frontSample()).toEqual(before);

    // 3 打开编辑器、不保存：画布与前台都不变
    await openPageEditor(page, state.page);
    expect(await sample(await frame(page))).toEqual(before);
    expect(JSON.parse(fixture('doc'))['pp-text']._global_style).toBe('brand_card');
    expect(await frontSample()).toEqual(before);

    // 4 打开编辑器并发布：落盘为类引用
    const published = page.waitForResponse(r => r.request().method() === 'POST'
      && new URL(r.url()).pathname === '/admin/blox_page_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === 'publish');
    page.once('dialog', dialog => dialog.accept());   // 发布确认
    await expect(page.getByTestId('blox-publish-page')).toBeEnabled();   // 编辑器里已改写成类引用，与已发布文档不同
    await page.getByTestId('blox-publish-page').click();
    expect((await (await published).json()).code).toBe(0);
    const saved = JSON.parse(fixture('doc'));
    for (const id of IDS) {
      expect(saved[id]._global_style, id).toBeUndefined();
      expect(saved[id]._classes, id).toContain(saved.class_id);
    }
    expect(await frontSample()).toEqual(before);
  } finally {
    await visitor.close();
  }
});
