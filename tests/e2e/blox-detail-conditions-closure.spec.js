const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

// 第一轮编辑闭环：中途非法输入、撤销/恢复稿、失败重试与文章条件往返。
// 每一步都走真实点击；库内结果由 PHP 夹具独立读取，不依赖界面自述。
const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), action, String(id)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
const storedScope = (id) => JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template;
const editorState = (page) => page.evaluate(() => {
  const data = window.Alpine.$data(document.body);
  return {
    scope: JSON.parse(JSON.stringify(data.docSettings.detail_template === undefined ? null : data.docSettings.detail_template)),
    unsaved: data.hasUnsavedChanges(),
    submit: data.conditionSubmitState(),
  };
});

function watchSubmits(page) {
  const submits = [];
  page.on('request', (request) => {
    if (!request.url().includes('/admin/blox_template_api.php')) return;
    const params = new URLSearchParams(request.postData() || '');
    const action = params.get('action') || '';
    if (action === 'save_draft' || action === 'publish') submits.push({ action, params });
  });
  return submits;
}

async function saveAndWait(page) {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === 'save_draft');
  await page.getByTestId('blox-save').click();
  const res = await response;
  return { params: new URLSearchParams(res.request().postData() || ''), body: await res.json() };
}

async function createTemplate(page, kind, name) {
  await page.goto('/admin/site_design.php');
  await page.getByTestId(`site-design-${kind}s`).click();
  await page.getByTestId(`${kind}-design-name`).fill(name);
  await page.locator('select[name="language"]').selectOption('zh-CN');
  await page.getByTestId(`${kind}-design-create`).click();
  await expect(page).toHaveURL(/blox_editor.php\?template=/);
  await waitPreviewSettled(page);
  await page.getByTestId(`${kind}-template-settings`).locator('summary').click();
  return { id: new URL(page.url()).searchParams.get('template'), editor: page.url() };
}

async function discardRecovery(page) {
  if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
    await page.getByTestId('blox-recovery-discard').click();
  }
}

async function optionValues(page, testId) {
  return page.getByTestId(testId).locator('option').evaluateAll((list) => list.map((option) => option.value));
}

test('unfinished condition edits stay unsaved, survive undo and recovery, and never submit', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  const dialogs = [];
  page.on('dialog', (dialog) => { dialogs.push(dialog.type()); dialog.accept(); });
  const submits = watchSubmits(page);
  let id;
  try {
    const created = await createTemplate(page, 'product', `TB-R2 closure-a ${info.project.name}`);
    id = created.id;
    await page.getByTestId('blox-cond-add-include-item').click();
    const productIds = await optionValues(page, 'blox-cond-include-target-0');
    expect(productIds.length).toBeGreaterThan(1);
    await page.getByTestId('blox-cond-include-target-0').selectOption(productIds[0]);
    expect((await saveAndWait(page)).body.code).toBe(0);
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();

    // 清空优先级（已保存值为 0）：这是改到一半，不是"没改过"
    await page.getByTestId('blox-cond-priority').fill('');
    await expect(page.getByTestId('blox-cond-problems-priority')).toBeVisible();
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    let state = await editorState(page);
    expect(state.unsaved, '中途非法输入也要触发未保存与离开保护').toBe(true);
    expect(state.scope.priority, '文档保留原始输入，不被改写成 0').toBe('');
    expect(state.submit).toBe('invalid');

    // 保存与发布都必须在客户端拦下：不发请求，也不先弹"确认发布"
    submits.length = 0;
    dialogs.length = 0;
    await page.getByTestId('blox-save').click();
    await expect(page.getByTestId('blox-toast')).toBeVisible();
    await page.getByTestId('blox-publish-template').click();
    await page.waitForTimeout(800);
    expect(submits.map((item) => item.action), '非法条件不得发出保存或发布请求').toEqual([]);
    expect(dialogs, '非法条件不应先要求确认发布').toEqual([]);
    expect(storedScope(id).priority).toBe(0);

    // 撤销/重做：非法中间态要原样回来，不能变成看似合法的值
    await page.waitForTimeout(1000);   // 让"清空"这一步单独落进历史
    await page.getByTestId('blox-cond-priority').fill('4');
    await expect(page.getByTestId('blox-cond-problems-priority')).toBeHidden();
    await page.waitForTimeout(1000);
    await page.getByTestId('blox-undo').click();
    await expect(page.getByTestId('blox-cond-priority')).toHaveValue('');
    await expect(page.getByTestId('blox-cond-problems-priority')).toBeVisible();
    await page.getByTestId('blox-redo').click();
    await expect(page.getByTestId('blox-cond-priority')).toHaveValue('4');
    await expect(page.getByTestId('blox-cond-dirty'), '重做后与服务器仍不同').toBeVisible();

    // 恢复稿：再次留下空目标行，刷新后恢复，面板要重现问题且保持已保存基线
    await page.getByTestId('blox-cond-add-exclude-item').click();
    await expect(page.getByTestId('blox-cond-problems')).toBeVisible();
    const recoveryKey = await page.evaluate(() => document.body.getAttribute('data-blox-recovery-key'));
    await expect.poll(() => page.evaluate((key) => {
      const raw = localStorage.getItem(key);
      return raw ? JSON.parse(JSON.parse(raw).data).settings.detail_template.exclude.length : 0;
    }, recoveryKey), { timeout: 6000 }).toBe(1);
    await page.reload();
    await waitPreviewSettled(page);
    await expect(page.getByTestId('blox-recovery-dialog')).toBeVisible();
    await page.getByTestId('blox-recovery-restore').click();
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-problems')).toBeVisible();
    await expect(page.getByTestId('blox-cond-priority')).toHaveValue('4');
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    state = await editorState(page);
    expect(state.unsaved).toBe(true);
    expect(state.submit).toBe('invalid');

    // 修正后保存：客户端文档与服务器实际保存内容一致
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(productIds[1]);
    await page.getByTestId('blox-cond-priority').fill('2');
    const saved = await saveAndWait(page);
    expect(saved.body.code).toBe(0);
    expect(saved.params.get('ui_scope')).toBeNull();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    const afterSave = await editorState(page);
    expect(afterSave.unsaved).toBe(false);
    expect(afterSave.scope, '已保存快照＝库内内容').toEqual(storedScope(id));
    expect(afterSave.scope.exclude).toEqual([{ kind: 'item', ids: [Number(productIds[1])], include_children: false }]);

    // 撤销到"还没加排除"再保存：旧条件（排除规则）不能复活
    for (let i = 0; i < 6; i += 1) {
      const count = await page.evaluate(() => window.Alpine.$data(document.body).conditionRows.exclude.length);
      if (count === 0) break;
      await page.getByTestId('blox-undo').click();
    }
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveCount(0);
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    const undone = await saveAndWait(page);
    expect(undone.body.code).toBe(0);
    expect(JSON.parse(undone.params.get('conditions_json')).exclude).toEqual([]);
    expect(storedScope(id).exclude, '撤销后保存不得复活已撤销的排除规则').toEqual([]);
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    await expect(page.getByTestId('blox-cond-exclude-kind-0')).toHaveCount(0);
  } finally {
    if (id) fixture('restore', id);
  }
});

test('a failed condition save does not leave a stale baseline for a later legacy save', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const pair = JSON.parse(fixture('pair'));
  const id = pair.selected;
  let failNext = true;
  try {
    await page.route('**/admin/blox_template_api.php', async (route) => {
      const action = new URLSearchParams(route.request().postData() || '').get('action') || '';
      if (action === 'save_draft' && failNext) {
        failNext = false;
        // 接口返回失败（未落库）：与权限/校验失败走同一条客户端失败路径
        await route.fulfill({ status: 200, contentType: 'application/json', body: JSON.stringify({ code: 1, msg: 'simulated failure' }) });
        return;
      }
      await route.continue();
    });
    expect(JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template).toBeUndefined();
    await page.goto(`/admin/blox_editor.php?template=${id}`);
    await waitPreviewSettled(page);
    await discardRecovery(page);
    await page.getByTestId('product-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();

    // 明确改条件 → 这次提交会带完整条件，但服务器返回失败
    await page.getByTestId('blox-cond-priority').fill('5');
    const failed = await saveAndWait(page);
    expect(failed.body.code).toBe(1);
    expect(JSON.parse(failed.params.get('conditions_json')).priority).toBe(5);
    await expect(page.getByTestId('blox-cond-dirty'), '失败后编辑仍在').toBeVisible();

    // 改回原样 → 回到 v1 旧路径保存；失败那次的条件快照不能被当成已保存基线
    await page.getByTestId('blox-cond-priority').fill('0');
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    const legacy = await saveAndWait(page);
    expect(legacy.body.code).toBe(0);
    expect(legacy.params.get('ui_scope')).toBe('1');
    expect(legacy.params.get('conditions_json')).toBeNull();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    const state = await editorState(page);
    expect(state.submit, '仍是未迁移的 v1，而不是被残留快照标成 v2').toBe('unchanged');
    expect(state.unsaved).toBe(false);
    expect(JSON.parse(JSON.parse(fixture('read', id)).draft_data).settings.detail_template, 'v1 没有被悄悄迁移').toBeUndefined();
  } finally {
    fixture('restore', id);
    fixture('restore', pair.global);
  }
});

test('article conditions round-trip with order, children flag, source and language intact', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop condition-panel baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const submits = watchSubmits(page);
  let id;
  try {
    const created = await createTemplate(page, 'article', `TB-R2 closure-art ${info.project.name}`);
    id = created.id;
    const initial = storedScope(id);
    const articleId = await page.getByTestId('article-template-preview').inputValue();
    expect(Number(articleId)).toBeGreaterThan(0);

    await page.getByTestId('blox-cond-add-include-category').click();
    const channels = await optionValues(page, 'blox-cond-include-target-0');
    expect(channels.length).toBeGreaterThan(0);
    await page.getByTestId('blox-cond-include-target-0').selectOption(channels[0]);
    await page.getByTestId('blox-cond-include-children-0').check();
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-1').selectOption(articleId);
    await page.getByTestId('blox-cond-add-exclude-category').click();
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(channels[channels.length - 1]);
    await page.getByTestId('blox-cond-exclude-children-0').uncheck();
    await page.getByTestId('blox-cond-priority').fill('6');

    const expectedInclude = [
      { kind: 'category', ids: [Number(channels[0])], include_children: true },
      { kind: 'item', ids: [Number(articleId)], include_children: false },
    ];
    const expectedExclude = [{ kind: 'category', ids: [Number(channels[channels.length - 1])], include_children: false }];

    const first = await saveAndWait(page);
    expect(first.body.code).toBe(0);
    expect(first.params.get('ui_scope')).toBeNull();
    let stored = storedScope(id);
    expect(stored.version).toBe(2);
    expect(stored.content_type).toBe('article');
    expect(stored.lang, '语言不漂移').toBe(initial.lang);
    expect(stored.source, 'source 不漂移').toBe(initial.source || 'custom');
    expect(stored.priority).toBe(6);
    expect(stored.include, '规则顺序与子级标志逐条保留').toEqual(expectedInclude);
    expect(stored.exclude).toEqual(expectedExclude);
    expect((await editorState(page)).scope, '已保存快照＝库内内容').toEqual(stored);

    // 只改优先级：规则不动
    await page.getByTestId('blox-cond-priority').fill('1');
    expect((await saveAndWait(page)).body.code).toBe(0);
    stored = storedScope(id);
    expect(stored.priority).toBe(1);
    expect(stored.include).toEqual(expectedInclude);
    expect(stored.exclude).toEqual(expectedExclude);
    expect(stored.lang).toBe(initial.lang);

    // 重开：逐项一致且不显示已修改
    await page.goto(created.editor);
    await waitPreviewSettled(page);
    await discardRecovery(page);
    await page.getByTestId('article-template-settings').locator('summary').click();
    await expect(page.getByTestId('blox-cond-include-kind-0')).toHaveValue('category');
    await expect(page.getByTestId('blox-cond-include-children-0')).toBeChecked();
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues([channels[0]]);
    await expect(page.getByTestId('blox-cond-include-kind-1')).toHaveValue('item');
    await expect(page.getByTestId('blox-cond-include-target-1')).toHaveValues([articleId]);
    await expect(page.getByTestId('blox-cond-exclude-children-0')).not.toBeChecked();
    await expect(page.getByTestId('blox-cond-priority')).toHaveValue('1');
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();

    // 无效输入：新增空目标行后保存不发请求，库内保持上一次合法值
    await page.getByTestId('blox-cond-add-include-category').click();
    await expect(page.getByTestId('blox-cond-problems')).toBeVisible();
    submits.length = 0;
    await page.getByTestId('blox-save').click();
    await page.waitForTimeout(800);
    expect(submits).toEqual([]);
    expect(storedScope(id)).toEqual(stored);
  } finally {
    if (id) fixture('restore', id);
  }
});
