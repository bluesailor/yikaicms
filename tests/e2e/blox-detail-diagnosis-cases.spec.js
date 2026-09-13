const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

// 第二轮：诊断要把真实生效路径说全——排除、native、缺失引用、其它模板之间的并列、非默认语言与权限。
const run = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), ...args.map(String)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

async function createProductTemplate(page, name, language = 'zh-CN') {
  await page.goto('/admin/site_design.php');
  await page.getByTestId('site-design-products').click();
  await page.getByTestId('product-design-name').fill(name);
  await page.locator('select[name="language"]').selectOption(language);
  await page.getByTestId('product-design-create').click();
  await expect(page).toHaveURL(/blox_editor.php\?template=/);
  await waitPreviewSettled(page);
  await page.getByTestId('product-template-settings').locator('summary').click();
  return new URL(page.url()).searchParams.get('template');
}

async function reopen(page, id) {
  await page.goto(`/admin/blox_editor.php?template=${id}`);
  await waitPreviewSettled(page);
  if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
    await page.getByTestId('blox-recovery-discard').click();
  }
  await page.getByTestId('product-template-settings').locator('summary').click();
}

async function diagnose(page) {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === 'diagnose_conditions');
  await page.getByTestId('blox-diagnose-run').click();
  return (await response).json();
}

const scope = (overrides) => JSON.stringify({
  version: 2, content_type: 'product', lang: 'zh-CN', source: 'custom', priority: 0, include: [], exclude: [], ...overrides,
});

test('diagnosis explains exclusion, native output, missing references and ties among other templates', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop diagnosis baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const cleanup = [];
  try {
    const id = await createProductTemplate(page, `TB-R2 diag-cases ${info.project.name}`);
    cleanup.push(id);
    const previewId = await page.getByTestId('product-template-preview').inputValue();
    expect(Number(previewId)).toBeGreaterThan(0);

    // 命中：说明"本模板的条件包含这条内容"，并标明诊断的是哪一条
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-0').selectOption(previewId);
    let res = await diagnose(page);
    expect(res.code).toBe(0);
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');
    await expect(page.getByTestId('blox-diagnose-match')).toContainText('条件包含这条内容');
    await expect(page.getByTestId('blox-diagnose-content')).toHaveText(`#${previewId} · zh-CN`);
    await expect(page.getByTestId('blox-diagnose-unpublished')).toBeHidden();
    expect(JSON.stringify(res.data.diagnosis), '诊断结果不回显标题正文').not.toContain('"title"');

    // 排除：不只是"没命中"，要说清是被排除规则排除
    await page.getByTestId('blox-cond-add-exclude-item').click();
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(previewId);
    res = await diagnose(page);
    expect(res.data.diagnosis.draft.match).toBe('excluded');
    await expect(page.getByTestId('blox-diagnose-match')).toContainText('被排除规则排除');
    await expect(page.getByTestId('blox-diagnose-verdict')).not.toContainText('会命中本模板');

    // native：本模板命中但声明输出主题默认——不能报成"没有模板命中"
    run('scope', id, scope({ source: 'native', include: [{ kind: 'item', ids: [Number(previewId)], include_children: false }] }));
    await reopen(page, id);
    res = await diagnose(page);
    expect(res.data.diagnosis.verdict).toBe('native');
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('输出主题默认');
    await expect(page.getByTestId('blox-diagnose-winner-id')).toHaveText(String(id));
    await expect(page.getByTestId('blox-diagnose-winner-none')).toBeHidden();

    // 缺失引用：规则里残留已不存在的内容/分类 ID，诊断要指出来
    run('scope', id, scope({
      include: [{ kind: 'item', ids: [Number(previewId), 987654321], include_children: false }],
      exclude: [{ kind: 'category', ids: [876543219], include_children: false }],
    }));
    await reopen(page, id);
    res = await diagnose(page);
    expect(res.data.diagnosis.draft.missing_references).toEqual({ items: [987654321], categories: [876543219] });
    await expect(page.getByTestId('blox-diagnose-missing')).toContainText('987654321');
    await expect(page.getByTestId('blox-diagnose-missing')).toContainText('876543219');
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');

    // 其它模板之间并列：本模板不命中，但要提醒这条内容实际靠模板 ID 兜底
    const all = scope({ include: [{ kind: 'all', ids: [], include_children: false }] });
    const tieA = JSON.parse(run('publish-scope', 0, all, 'tie-a')).id;
    cleanup.push(tieA);
    const tieB = JSON.parse(run('publish-scope', 0, all, 'tie-b')).id;
    cleanup.push(tieB);
    const otherId = await page.getByTestId('blox-cond-include-target-0').locator('option')
      .evaluateAll((options, current) => options.map((o) => o.value).find((value) => value !== current), previewId);
    run('scope', id, scope({ include: [{ kind: 'item', ids: [Number(otherId)], include_children: false }] }));
    await reopen(page, id);
    res = await diagnose(page);
    expect(res.data.diagnosis.verdict).toBe('lost');
    expect(res.data.diagnosis.draft.match).toBe('not_included');
    expect(res.data.diagnosis.others_conflicted).toBe(true);
    expect(res.data.diagnosis.conflicts.map((row) => row.template_id)).toEqual(expect.arrayContaining([tieA, tieB]));
    await expect(page.getByTestId('blox-diagnose-others-conflicted')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-match')).toContainText('纳入条件不包含这条内容');

    // 同样的并列里本模板也入场（同为"全部"、同优先级）：本模板是并列成员，不管 ID 兜底选中谁
    run('scope', id, all);
    await reopen(page, id);
    res = await diagnose(page);
    expect(res.data.diagnosis.verdict).toBe('conflicted');
    expect(res.data.diagnosis.others_conflicted).toBe(false);
    await expect(page.getByTestId('blox-diagnose-others-conflicted')).toBeHidden();
  } finally {
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('diagnosis works for a non-default language and keeps results scoped to that content', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop diagnosis baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let id;
  try {
    id = await createProductTemplate(page, `TB-R2 diag-en ${info.project.name}`, 'en');
    const previewId = await page.getByTestId('product-template-preview').inputValue();
    expect(Number(previewId)).toBeGreaterThan(0);
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-0').selectOption(previewId);
    const res = await diagnose(page);
    expect(res.code).toBe(0);
    expect(res.data.diagnosis.content.lang).toBe('en');
    expect(res.data.diagnosis.draft.lang).toBe('en');
    await expect(page.getByTestId('blox-diagnose-content')).toHaveText(`#${previewId} · en`);
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');
    await expect(page.getByTestId('blox-diagnose-result')).toContainText('只代表这一条内容');

    // 换一条预览内容后旧结果立即标为过期，不冒充新内容的结果
    const other = await page.getByTestId('product-template-preview').locator('option')
      .evaluateAll((options, current) => options.map((o) => o.value).find((value) => value !== '0' && value !== current), previewId);
    if (other) {
      await page.getByTestId('product-template-preview').selectOption(other);
      await expect(page.getByTestId('blox-diagnose-stale')).toBeVisible();
    }
  } finally {
    if (id) run('restore', id);
  }
});

test('diagnosis refuses content the account cannot edit with the same answer as missing content', async ({ page, browser }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop diagnosis baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let id;
  const account = JSON.parse(run('limited-user'));
  const limited = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    id = await createProductTemplate(page, `TB-R2 diag-perm ${info.project.name}`);
    const previewId = await page.getByTestId('product-template-preview').inputValue();

    // 不存在的内容：超管也只得到"不存在或无权查看"
    const token = await page.evaluate(() => window.Alpine.$data(document.body).csrf);
    const missing = await page.request.post('/admin/blox_template_api.php', {
      headers: { 'X-Requested-With': 'XMLHttpRequest' },
      form: { action: 'diagnose_conditions', id, content_id: '987654321', _token: token,
        conditions_json: scope({ include: [{ kind: 'all', ids: [], include_children: false }] }) },
    });
    const missingBody = await missing.json();
    expect(missingBody.code).not.toBe(0);

    // 只有 Blox 全站设计权限、没有产品编辑权限：真实点击诊断，得到同一条反馈，不泄露结果
    const other = await limited.newPage();
    await other.goto('/admin/login.php');
    await other.locator('input[name="username"]').fill(account.username);
    await other.locator('input[name="password"]').fill(account.password);
    await Promise.all([
      other.waitForURL((url) => !url.pathname.endsWith('/admin/login.php'), { waitUntil: 'domcontentloaded' }),
      other.locator('button[type="submit"]').click(),
    ]);
    await other.goto(`/admin/blox_editor.php?template=${id}`);
    await waitPreviewSettled(other);
    if (await other.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
      await other.getByTestId('blox-recovery-discard').click();
    }
    await other.getByTestId('product-template-settings').locator('summary').click();
    await other.getByTestId('product-template-preview').selectOption(previewId);
    await other.getByTestId('blox-cond-add-include-all').click();
    const denied = other.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === 'diagnose_conditions');
    await other.getByTestId('blox-diagnose-run').click();
    const deniedBody = await (await denied).json();
    expect(deniedBody.code).not.toBe(0);
    expect(deniedBody.msg, '无权与不存在是同一条反馈').toBe(missingBody.msg);
    expect(JSON.stringify(deniedBody)).not.toContain('diagnosis');
    await expect(other.getByTestId('blox-diagnose-error')).toHaveText(missingBody.msg);
    await expect(other.getByTestId('blox-diagnose-result')).toHaveCount(0);
  } finally {
    await limited.close();
    run('limited-user', 'remove');
    if (id) run('restore', id);
  }
});
