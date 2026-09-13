const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

// 第三轮：发布冲突保护。所有结论由服务端对真实内容做发布前/后对比；这里验证真实点击下的行为与库内结果。
// 每个用例恰好出现一次被阻止的发布（HTTP 409），由站点诊断夹具按预期消费，其余浏览器错误仍会让用例失败。
const run = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), ...args.map(String)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
const read = (id) => JSON.parse(run('read', id));
const scope = (overrides) => JSON.stringify({
  version: 2, content_type: 'product', lang: 'zh-CN', source: 'custom', priority: 0,
  include: [{ kind: 'all', ids: [], include_children: false }], exclude: [], ...overrides,
});

test.use({
  expectedHttpErrors: async ({ baseURL }, use) => {
    await use([{ status: 409, method: 'POST', action: 'publish', url: new URL('/admin/blox_template_api.php', baseURL).href }]);
  },
});

async function createTemplate(page, kind, name) {
  await page.goto('/admin/site_design.php');
  await page.getByTestId(`site-design-${kind}s`).click();
  await page.getByTestId(`${kind}-design-name`).fill(name);
  await page.locator('select[name="language"]').selectOption('zh-CN');
  await page.getByTestId(`${kind}-design-create`).click();
  await expect(page).toHaveURL(/blox_editor.php\?template=/);
  await waitPreviewSettled(page);
  await page.getByTestId(`${kind}-template-settings`).locator('summary').click();
  return new URL(page.url()).searchParams.get('template');
}

async function openEditor(page, id, kind = 'product') {
  await page.goto(`/admin/blox_editor.php?template=${id}`);
  await waitPreviewSettled(page);
  if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
    await page.getByTestId('blox-recovery-discard').click();
  }
  await page.getByTestId(`${kind}-template-settings`).locator('summary').click();
}

function watchApi(page) {
  const log = [];
  page.on('response', async (response) => {
    if (new URL(response.url()).pathname !== '/admin/blox_template_api.php') return;
    const action = new URLSearchParams(response.request().postData() || '').get('action') || '';
    if (!['publish', 'check_publish_conflicts', 'save_draft'].includes(action)) return;
    let body = null;
    try { body = await response.json(); } catch (error) { body = null; }
    log.push({ action, status: response.status(), body });
  });
  return log;
}

async function clickPublish(page, log) {
  const before = log.length;
  await page.getByTestId('blox-publish-template').click();
  await expect.poll(() => log.slice(before).some((entry) => entry.action === 'publish')).toBe(true);
  return log.slice(before).find((entry) => entry.action === 'publish');
}

test('a new product tie blocks publishing without touching live versions; the draft still saves and a fix publishes', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop publish-guard baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchApi(page);
  const cleanup = [];
  try {
    const rival = JSON.parse(run('publish-scope', 0, scope(), 'guard-rival')).id;
    cleanup.push(rival);
    const rivalBefore = read(rival);

    const id = await createTemplate(page, 'product', `TB-R2 guard-new ${info.project.name}`);
    cleanup.push(id);
    await page.getByTestId('blox-cond-add-include-all').click();

    const blocked = await clickPublish(page, log);
    expect(blocked.status).toBe(409);
    expect(blocked.body.data.detail_publish.status).toBe('conflict');
    const conflict = blocked.body.data.detail_publish.conflicts[0];
    expect(conflict.kind).toBe('member');
    expect(conflict.template_ids).toEqual(expect.arrayContaining([Number(id), rival]));
    await expect(page.getByTestId('blox-publish-check-result')).toHaveAttribute('data-status', 'conflict');
    const row = page.getByTestId(`blox-publish-conflict-${conflict.content_id}`);
    await expect(row).toBeVisible();
    await expect(row.getByTestId(`blox-publish-conflict-template-${rival}`)).toHaveAttribute('href', `/admin/blox_editor.php?template=${rival}`);

    // 冲突失败不改任何已发布版本
    const stored = read(id);
    expect(Number(stored.status), '本模板没有被发布').toBe(0);
    expect(stored.published_data).toBeNull();
    expect(read(rival).published_data, '对方的已发布版本不变').toBe(rivalBefore.published_data);

    // 冲突不妨碍保存草稿
    const save = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    expect((await (await save).json()).code).toBe(0);
    expect(JSON.parse(read(id).draft_data).settings.detail_template.include).toEqual([{ kind: 'all', ids: [], include_children: false }]);

    // 调整入口：诊断冲突内容，结论与发布检查一致
    await row.getByTestId(`blox-publish-conflict-diagnose-${conflict.content_id}`).click();
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('并列');
    await expect(page.getByTestId('blox-diagnose-content')).toContainText(`#${conflict.content_id}`);

    // 修正优先级：旧结论标为过期；再发布成功
    await page.getByTestId('blox-cond-priority').fill('1');
    await expect(page.getByTestId('blox-publish-check-stale')).toBeVisible();
    const published = await clickPublish(page, log);
    expect(published.body.code).toBe(0);
    expect(Number(read(id).status)).toBe(1);
    await expect(page.getByTestId('blox-publish-check-result')).toHaveCount(0);
  } finally {
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('article templates are protected by the same publish check', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop publish-guard baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchApi(page);
  const cleanup = [];
  try {
    const rival = JSON.parse(run('publish-scope', 0, scope({ content_type: 'article' }), 'guard-art-rival')).id;
    cleanup.push(rival);
    const id = await createTemplate(page, 'article', `TB-R2 guard-art ${info.project.name}`);
    cleanup.push(id);
    await page.getByTestId('blox-cond-add-include-all').click();
    const blocked = await clickPublish(page, log);
    expect(blocked.status).toBe(409);
    expect(blocked.body.data.detail_publish.status).toBe('conflict');
    expect(blocked.body.data.detail_publish.conflicts[0].template_ids).toEqual(expect.arrayContaining([Number(id), rival]));
    expect(read(id).published_data).toBeNull();
    await expect(page.getByTestId('blox-publish-check-result')).toHaveAttribute('data-status', 'conflict');
  } finally {
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('an unchanged historical tie can be republished, but editing conditions inside the tie is blocked', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop publish-guard baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchApi(page);
  const cleanup = [];
  try {
    const first = JSON.parse(run('publish-scope', 0, scope(), 'hist-1')).id;
    cleanup.push(first);
    const second = JSON.parse(run('publish-scope', 0, scope(), 'hist-2')).id;
    cleanup.push(second);

    await openEditor(page, first);
    await expect(page.getByTestId('product-template-language'), '纯 v2 产品模板也能正常打开').toHaveValue('zh-CN');
    const republished = await clickPublish(page, log);
    expect(republished.body.code, '条件未变的历史并列保持兼容').toBe(0);

    const productId = await page.getByTestId('product-template-preview').inputValue();
    await page.getByTestId('blox-cond-add-exclude-item').click();
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(productId);
    const blocked = await clickPublish(page, log);
    expect(blocked.status, '改了条件仍在并列里：不能沿用历史豁免').toBe(409);
    expect(blocked.body.data.detail_publish.conflicts.every((row) => row.kind === 'member')).toBe(true);
    expect(JSON.parse(read(first).published_data).settings.detail_template.exclude, '线上版本保持原条件').toEqual([]);
  } finally {
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('a large scope must finish a paged check before publishing, and no page before the last reports a pass', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop publish-guard baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchApi(page);
  const cleanup = [];
  run('setting', 0, 'blox_detail_publish_sync_rows', '1');
  run('setting', 0, 'blox_detail_publish_page_rows', '1');
  try {
    const id = await createTemplate(page, 'product', `TB-R2 guard-paged ${info.project.name}`);
    cleanup.push(id);
    await page.getByTestId('blox-cond-add-include-all').click();
    await page.getByTestId('blox-cond-priority').fill('37');

    // 同步检查只允许 1 行 → 服务端要求完成分页检查；客户端逐页检查完且无新并列后才再次发布
    const start = log.length;
    await page.getByTestId('blox-publish-template').click();
    await expect.poll(() => log.slice(start).filter((e) => e.action === 'publish').length, { timeout: 30000 }).toBe(2);
    const entries = log.slice(start);
    const publishes = entries.filter((e) => e.action === 'publish');
    expect(publishes[0].status).toBe(409);
    expect(publishes[0].body.data.detail_publish.status).toBe('incomplete');
    const checks = entries.filter((e) => e.action === 'check_publish_conflicts');
    expect(checks.length, '需要多页').toBeGreaterThan(1);
    expect(checks.slice(0, -1).every((e) => e.body.data.check.status === 'incomplete'), '未扫完的页一律是未完成').toBe(true);
    expect(checks[checks.length - 1].body.data.check.status).toBe('clear');
    expect(entries.indexOf(publishes[1]), '检查完成后才再次发布').toBeGreaterThan(entries.indexOf(checks[checks.length - 1]));
    expect(publishes[1].body.code).toBe(0);
    expect(Number(read(id).status)).toBe(1);
  } finally {
    run('setting', 0, 'blox_detail_publish_sync_rows', '');
    run('setting', 0, 'blox_detail_publish_page_rows', '');
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('a stopped check shows as stopped, and a passed check is not trusted once another template goes live', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop publish-guard baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchApi(page);
  const cleanup = [];
  try {
    const id = await createTemplate(page, 'product', `TB-R2 guard-stale ${info.project.name}`);
    cleanup.push(id);
    await page.getByTestId('blox-cond-add-include-all').click();
    await page.getByTestId('blox-cond-priority').fill('37');
    expect((await clickPublish(page, log)).body.code, '默认上限内同步检查通过直接发布').toBe(0);

    run('setting', 0, 'blox_detail_publish_sync_rows', '1');
    run('setting', 0, 'blox_detail_publish_page_rows', '1');

    // 停止检查：显示已停止（未完成的检查不能用于发布）
    let release = () => {};
    const gate = new Promise((resolve) => { release = resolve; });
    await page.route('**/admin/blox_template_api.php', async (route) => {
      const action = new URLSearchParams(route.request().postData() || '').get('action') || '';
      if (action === 'check_publish_conflicts') await gate;
      await route.continue();
    });
    await page.getByTestId('blox-cond-priority').fill('38');
    await page.getByTestId('blox-publish-check-run').click();
    await expect(page.getByTestId('blox-publish-check-cancel')).toBeVisible();
    await page.getByTestId('blox-publish-check-cancel').click();
    release();
    await expect(page.getByTestId('blox-publish-check-result')).toHaveAttribute('data-status', 'cancelled');
    await page.unroute('**/admin/blox_template_api.php');

    // 完整检查通过后，另一个同级模板上线：发布必须重新校验，而不是信任先前的"通过"
    await page.getByTestId('blox-publish-check-run').click();
    await expect(page.getByTestId('blox-publish-check-result')).toHaveAttribute('data-status', 'clear', { timeout: 30000 });
    const late = JSON.parse(run('publish-scope', 0, scope({ priority: 38 }), 'guard-late')).id;
    cleanup.push(late);
    const recheckStart = log.length;
    await page.getByTestId('blox-publish-template').click();
    await expect(page.getByTestId('blox-publish-check-result')).toHaveAttribute('data-status', 'conflict', { timeout: 30000 });
    const recheck = log.slice(recheckStart);
    expect(recheck.filter((e) => e.action === 'publish').map((e) => e.status), '候选变化后没有任何一次发布成功').toEqual([409]);
    expect(JSON.parse(read(id).published_data).settings.detail_template.priority, '线上仍是上一次发布的条件').toBe(37);
  } finally {
    run('setting', 0, 'blox_detail_publish_sync_rows', '');
    run('setting', 0, 'blox_detail_publish_page_rows', '');
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});
