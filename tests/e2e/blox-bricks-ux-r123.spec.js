/**
 * R1-R3 浏览器验收（Bricks UX 任务书 A 轨）。
 *
 * 覆盖：历史「载入到画布」的草稿语义（不动线上）、数值预览宽度的真实 viewport、
 * 同类元素样式复制/粘贴与撤销、网页设置弹窗的信息与入口，以及
 * 添加元素-改内容-换设备-撤销-保存草稿-发布 的完整链路。
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { frame, openPageEditor } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

const app = (page, fn, arg) => page.evaluate(([body, value]) => {
  const alpine = window.Alpine.$data(document.body);
  // eslint-disable-next-line no-new-func
  return new Function('app', 'arg', `return (${body})(app, arg);`)(alpine, value);
}, [fn.toString(), arg === undefined ? null : arg]);

async function addHeading(page, text) {
  await page.evaluate((headingText) => {
    const alpine = window.Alpine.$data(document.body);
    alpine.selectSection(alpine.sections.length - 1, false);
    alpine.addElement(alpine.elementLib.find(element => element.type === 'heading'));
    const section = alpine.sections[alpine.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    const element = column.elements[column.elements.length - 1];
    element.data.text = headingText;
  }, text);
  await page.waitForTimeout(150);
}

async function saveDraft(page) {
  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|published|clean/, { timeout: 10000 });
}

async function publishPage(page) {
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-publish-page').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /published|clean|saved/, { timeout: 15000 });
}

test('full flow: element edit, device switch, preview width, style paste, undo, save, publish @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);

  // 1) 添加两个标题并改文字（真实文档编辑）
  await addHeading(page, '验收标题甲');
  await addHeading(page, '验收标题乙');
  await expect.poll(() => app(page, a => a.dirty)).toBe(true);

  // 2) 样式复制/粘贴：甲设置颜色与字号档 → 复制 → 粘贴到乙；文字互不影响
  await app(page, (a) => {
    const section = a.sections[a.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    const elements = column.elements.filter(el => el.type === 'heading');
    const source = elements[elements.length - 2];
    source.data.color = '#ff0066';
    source.data.visual_size = { d: '3xl', t: '3xl', m: 'xl' };
    a.selectElement(a.sections.length - 1, section.columns.length - 1, column.elements.indexOf(source), false);
  });
  await page.getByTestId('blox-style-copy').click();
  await app(page, (a) => {
    const section = a.sections[a.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    a.selectElement(a.sections.length - 1, section.columns.length - 1, column.elements.length - 1, false);
  });
  const pasteButton = page.getByTestId('blox-style-paste');
  await expect(pasteButton).toBeEnabled();
  await pasteButton.click();
  const pasted = await app(page, (a) => {
    const section = a.sections[a.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    const target = column.elements[column.elements.length - 1];
    return { color: target.data.color, size: target.data.visual_size, text: target.data.text };
  });
  expect(pasted.color).toBe('#ff0066');
  expect(pasted.size).toEqual({ d: '3xl', t: '3xl', m: 'xl' });
  expect(pasted.text).toBe('验收标题乙');

  // 撤销一次即回到粘贴前
  await page.getByTestId('blox-undo').click();
  await expect.poll(() => app(page, (a) => {
    const section = a.sections[a.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    return column.elements[column.elements.length - 1].data.color || '';
  })).toBe('');

  // 3) 设备切换（编辑档位）+ 数值预览宽度：iframe 真实 viewport 必须等于所标数值
  const historyBefore = await app(page, a => a.historyStore().entries.length);
  const dirtyBefore = await app(page, a => a.dirty);
  for (const width of [1024, 768, 390, 1440]) {
    await page.getByTestId('blox-preview-width-input').fill(String(width));
    await page.getByTestId('blox-preview-width-input').press('Enter');
    await expect.poll(async () => (await frame(page)).evaluate(() => window.innerWidth)).toBe(width);
    await expect(page.getByTestId('blox-preview-width-chip')).toBeVisible();
  }
  // 越界钳制 + 档位不被宽度切换
  await page.getByTestId('blox-preview-width-input').fill('99999');
  await page.getByTestId('blox-preview-width-input').press('Enter');
  await expect.poll(() => app(page, a => a.previewCustomWidth)).toBe(2560);
  await app(page, a => { a.previewDevice = 'tablet'; });
  await expect.poll(() => app(page, a => a.previewDevice)).toBe('tablet');
  await expect.poll(() => app(page, a => a.previewCustomWidth)).toBe(2560);
  await page.getByTestId('blox-preview-width-clear').click();
  await expect(page.getByTestId('blox-preview-width-chip')).toBeHidden();
  await app(page, a => { a.previewDevice = 'desktop'; });
  // 预览宽度不产生历史、不改变未保存状态
  expect(await app(page, a => a.historyStore().entries.length)).toBe(historyBefore);
  expect(await app(page, a => a.dirty)).toBe(dirtyBefore);

  // 4) 网页设置：信息、URL、页头页尾、标题区与 SEO 入口
  await page.getByTestId('blox-page-frame-open').click();
  await expect(page.getByTestId('blox-page-frame-info')).toBeVisible();
  await expect(page.getByTestId('blox-page-url-current')).toBeVisible();
  await expect(page.getByTestId('blox-page-frame-header')).toBeVisible();
  await expect(page.getByTestId('blox-page-frame-footer')).toBeVisible();
  await expect(page.getByTestId('blox-page-frame-seo')).toHaveAttribute('href', new RegExp(`page_edit\\.php\\?id=${fixtures.blox_page}$`));
  await expect(page.getByTestId('blox-page-frame-seo')).toHaveAttribute('target', '_blank');
  await page.getByTestId('blox-page-frame-apply').click();

  // 5) 保存草稿 → 发布（完整链路收口）
  await saveDraft(page);
  await publishPage(page);
});

test('revision preview stays read-only and load-into-canvas keeps live content untouched @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);

  // 版本一：发布 V1；版本二：发布 V2（发布会把被覆盖状态存为历史）
  await addHeading(page, '历史一号标题');
  await saveDraft(page);
  await publishPage(page);
  await app(page, (a) => {
    const section = a.sections[a.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    column.elements[column.elements.length - 1].data.text = '历史二号标题';
  });
  await saveDraft(page);
  await publishPage(page);

  // 打开历史：默认只预览
  await page.getByTestId('blox-revisions-open').click();
  const loadButton = page.getByTestId('blox-revision-load');
  await expect(loadButton).toBeVisible({ timeout: 10000 });
  const baseRevisionBefore = await app(page, a => a.baseRevision);

  // 关闭再打开（取消预览不改变编辑状态）
  await page.keyboard.press('Escape');
  expect(await app(page, a => a.dirty)).toBe(false);
  await page.getByTestId('blox-revisions-open').click();
  await expect(loadButton).toBeVisible();

  // 载入历史版本：画布回到 V1，dirty=true，base_revision 不变，线上不动
  await loadButton.click();
  await expect.poll(() => app(page, a => JSON.stringify(a.sections).includes('历史一号标题'))).toBe(true);
  expect(await app(page, a => a.dirty)).toBe(true);
  expect(await app(page, a => a.baseRevision)).toBe(baseRevisionBefore);

  // 线上仍是 V2：匿名请求前台页面
  const frontUrl = await app(page, a => a.pageUrl);
  const anonymous = await page.request.get(frontUrl, { headers: { Cookie: '' } });
  expect(anonymous.ok()).toBeTruthy();
  expect(await anonymous.text()).toContain('历史二号标题');

  // 一次撤销回到载入前（V2 画布）
  await page.getByTestId('blox-undo').click();
  await expect.poll(() => app(page, a => JSON.stringify(a.sections).includes('历史二号标题'))).toBe(true);
});
