const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), action, String(id)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 只跑桌面项目：条件面板在设置折叠区里，窄屏需要额外开合路径，另开一版再覆盖。
test('detail condition panel round-trips complete rules without the legacy ui_scope path', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const products = JSON.parse(fixture('products'));
  expect(products.length).toBeGreaterThan(0);
  let id;
  try {
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-products').click();
    await page.getByTestId('product-design-name').fill(`TB-R2 cond ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('product-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    id = new URL(page.url()).searchParams.get('template');
    const editor = page.url();
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-detail-conditions')).toBeVisible();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();

    // 纳入：指定分类 + 含子级
    await page.getByTestId('blox-cond-add-include-category').click();
    const categoryIds = await page.getByTestId('blox-cond-include-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    expect(categoryIds.length, 'demo site needs a product category').toBeGreaterThan(0);
    await page.getByTestId('blox-cond-include-target-0').selectOption(categoryIds[0]);
    await page.getByTestId('blox-cond-include-children-0').check();

    // 排除：指定产品（resolver 不允许 exclude 用 all，面板也不提供该按钮）
    await page.getByTestId('blox-cond-add-exclude-item').click();
    const productIds = await page.getByTestId('blox-cond-exclude-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    expect(productIds.length).toBeGreaterThan(0);
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(productIds[0]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    // 只改条件也要点亮全局"未保存"：保存载荷与离开保护都以文档为准（TASK-006-R01 保存状态项）
    expect(await page.evaluate(() => window.Alpine.$data(document.body).hasUnsavedChanges())).toBe(true);

    const expectedInclude = [{ kind: 'category', ids: [Number(categoryIds[0])], include_children: true }];
    const expectedExclude = [{ kind: 'item', ids: [Number(productIds[0])], include_children: false }];

    // 保存：请求必须带完整条件，且不得再走旧的 ui_scope 简化投影
    const saved = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    const body = new URLSearchParams((await saved).postData() || '');
    expect(body.get('ui_scope'), '完整条件提交不能与 ui_scope 并存').toBeNull();
    const posted = JSON.parse(body.get('conditions_json') || 'null');
    expect(posted.include).toEqual(expectedInclude);
    expect(posted.exclude).toEqual(expectedExclude);

    // 落库：由 PHP 侧独立读取，不依赖界面自述
    const stored = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(stored.version).toBe(2);
    expect(stored.content_type).toBe('product');
    expect(stored.include).toEqual(expectedInclude);
    expect(stored.exclude).toEqual(expectedExclude);

    // 重开：字段逐项一致，且不再显示"条件已修改"
    await page.goto(editor);
    await waitPreviewSettled(page);
    // 本地恢复稿会遮住设置区；本次要验的是服务器版本，所以明确放弃恢复稿
    if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
      await page.getByTestId('blox-recovery-discard').click();
    }
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-include-kind-0')).toHaveValue('category');
    await expect(page.getByTestId('blox-cond-include-children-0')).toBeChecked();
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues([categoryIds[0]]);
    await expect(page.getByTestId('blox-cond-exclude-target-0')).toHaveValues([productIds[0]]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
  } finally {
    if (id) fixture('restore', id);
  }
});

// TASK-006-R01 P1a：非法条件必须中止保存，不能退回旧路径把用户改动丢掉
test('invalid panel conditions abort the save instead of submitting anything', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const submits = [];
  page.on('request', (request) => {
    if (!request.url().includes('/admin/blox_template_api.php')) return;
    const action = new URLSearchParams(request.postData() || '').get('action') || '';
    if (action === 'save_draft' || action === 'publish') submits.push(action);
  });
  let id;
  try {
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-products').click();
    await page.getByTestId('product-design-name').fill(`TB-R2 cond-block ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('product-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    id = new URL(page.url()).searchParams.get('template');
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();

    // 先存一份合法条件（走完整通道）
    await page.getByTestId('blox-cond-add-include-item').click();
    const productIds = await page.getByTestId('blox-cond-include-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    await page.getByTestId('blox-cond-include-target-0').selectOption(productIds[0]);
    const firstSave = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    await firstSave;
    const baseline = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(baseline.include).toEqual([{ kind: 'item', ids: [Number(productIds[0])], include_children: false }]);

    // 再加一条没选目标的规则 → 面板报问题，保存必须整条中止
    await page.getByTestId('blox-cond-add-exclude-item').click();
    await expect(page.getByTestId('blox-cond-problems')).toBeVisible();
    submits.length = 0;
    await page.getByTestId('blox-save').click();
    await expect(page.getByTestId('blox-toast')).toBeVisible();
    await expect(page.getByTestId('blox-save')).toBeEnabled();
    await page.waitForTimeout(800);
    expect(submits, '非法条件不得发出任何保存请求').toEqual([]);
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveValue('item');
    const after = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(after, '库内条件应保持上一次合法值').toEqual(baseline);
  } finally {
    if (id) fixture('restore', id);
  }
});

// TASK-006-R01 P1b：v1 文档只读适配进面板，改条件后原范围必须保留
test('v1 product scope enters the panel read-only and survives a condition edit', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const id = JSON.parse(fixture('pair')).selected;
  try {
    // 打开前：这是 v1 文档——只有 product_template，没有 v2 契约
    const before = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings;
    expect(before.detail_template).toBeUndefined();
    const legacyIds = before.product_template.ids.map(String);
    expect(legacyIds.length).toBeGreaterThan(0);

    await page.goto(`/admin/blox_editor.php?template=${id}`);
    await waitPreviewSettled(page);
    if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
      await page.getByTestId('blox-recovery-discard').click();
    }
    await page.getByTestId('product-template-settings').locator('summary').click();

    // 只打开：v1 范围应作为初始模型出现，且不得写进文档
    await expect(page.getByTestId('blox-cond-include-kind-0')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues(legacyIds);
    expect(await page.evaluate(() => window.Alpine.$data(document.body).conditionDirty())).toBe(false);
    expect(JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template, '只打开面板不得写文档').toBeUndefined();

    // 明确改条件（加一条排除）后保存：原 include 必须原样保留，只新增 exclude
    await page.getByTestId('blox-cond-add-exclude-item').click();
    const productIds = await page.getByTestId('blox-cond-exclude-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(productIds[0]);
    const saved = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    const body = new URLSearchParams((await saved).postData() || '');
    const posted = JSON.parse(body.get('conditions_json') || 'null');
    expect(posted.include, 'v1 原范围保留').toEqual([{ kind: 'item', ids: legacyIds.map(Number), include_children: false }]);
    expect(posted.exclude).toEqual([{ kind: 'item', ids: [Number(productIds[0])], include_children: false }]);
    const stored = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(stored.include).toEqual(posted.include);
    expect(stored.exclude).toEqual(posted.exclude);
  } finally {
    fixture('restore', id);
  }
});

// TASK-006-R01 保存状态：保存期间的新编辑不能被保存回执重置掉
test('condition edits made while a save is in flight survive the response', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let release = () => {};
  const gate = new Promise((resolve) => { release = resolve; });
  let id;
  try {
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-products').click();
    await page.getByTestId('product-design-name').fill(`TB-R2 cond-inflight ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('product-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    id = new URL(page.url()).searchParams.get('template');
    await waitPreviewSettled(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    await page.getByTestId('blox-cond-add-include-item').click();
    const productIds = await page.getByTestId('blox-cond-include-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    await page.getByTestId('blox-cond-include-target-0').selectOption(productIds[0]);

    // 把保存响应卡住，好在"保存中"这段时间里继续编辑条件
    await page.route('**/admin/blox_template_api.php', async (route) => {
      const action = new URLSearchParams(route.request().postData() || '').get('action') || '';
      if (action !== 'save_draft') { await route.continue(); return; }
      await gate;
      await route.continue();
    });
    const saved = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    await saved;

    // 保存尚未返回时再加一条排除规则
    await page.getByTestId('blox-cond-add-exclude-item').click();
    const excludeIds = await page.getByTestId('blox-cond-exclude-target-0')
      .locator('option').evaluateAll((options) => options.map((option) => option.value));
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(excludeIds[0]);
    release();

    // 回执到达后：当前文档必须是 B（不能被旧提交覆盖），全局仍 dirty，恢复稿保留
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    const afterAccept = await page.evaluate(() => {
      const data = window.Alpine.$data(document.body);
      const key = document.body.getAttribute('data-blox-recovery-key');
      return {
        documentScope: JSON.parse(JSON.stringify(data.docSettings.detail_template)),
        rowsExclude: JSON.parse(JSON.stringify(data.conditionRows.exclude)),
        dirty: data.dirty,
        unsavedGlobal: data.hasUnsavedChanges(),
        recoveryKey: key,
      };
    });
    expect(afterAccept.documentScope.exclude, '当前文档必须保留保存期间的新编辑').toEqual([{ kind: 'item', ids: [Number(excludeIds[0])], include_children: false }]);
    expect(afterAccept.documentScope.include).toEqual([{ kind: 'item', ids: [Number(productIds[0])], include_children: false }]);
    expect(afterAccept.dirty, '当前文档与已保存快照不同 → 必须仍是未保存').toBe(true);
    expect(afterAccept.unsavedGlobal).toBe(true);
    // 恢复稿是延迟写入的（默认 1200ms），等它落盘；未保存时绝不能被清掉
    expect(afterAccept.recoveryKey).toBeTruthy();
    await expect.poll(
      () => page.evaluate((key) => !!localStorage.getItem(key), afterAccept.recoveryKey),
      { message: '未保存就不能清掉恢复稿', timeout: 5000 },
    ).toBe(true);
    const stored = JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
    expect(stored.include).toEqual([{ kind: 'item', ids: [Number(productIds[0])], include_children: false }]);
    expect(stored.exclude, '保存期间新增的排除规则尚未提交').toEqual([]);

    // 下一次保存必须发送 B（期间的新编辑），且发送后回到干净状态
    const second = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    const secondBody = new URLSearchParams((await second).postData() || '');
    const secondScope = JSON.parse(secondBody.get('conditions_json') || 'null');
    expect(secondScope.exclude, '第二次保存要带上保存期间的新编辑').toEqual([{ kind: 'item', ids: [Number(excludeIds[0])], include_children: false }]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    expect(await page.evaluate(() => window.Alpine.$data(document.body).hasUnsavedChanges())).toBe(false);
  } finally {
    release();
    if (id) fixture('restore', id);
  }
});
