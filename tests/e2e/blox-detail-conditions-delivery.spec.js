const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled, addTemporaryHeading, frame } = require('./helpers');

// 第五轮：交付闭环。条件 → 保存 → 发布 → 前台结果（含主题默认与不应用），产品、文章、非默认语言各走一遍；
// 另验缺失 ID 的回退显示、分类按语言过滤与窄屏布局。前台一律用无后台登录态的访客上下文访问。
const run = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), ...args.map(String)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

async function createTemplate(page, kind, name, language = 'zh-CN') {
  await page.goto('/admin/site_design.php');
  await page.getByTestId(`site-design-${kind}s`).click();
  await page.getByTestId(`${kind}-design-name`).fill(name);
  await page.locator('select[name="language"]').selectOption(language);
  await page.getByTestId(`${kind}-design-create`).click();
  await expect(page).toHaveURL(/blox_editor.php\?template=/);
  await waitPreviewSettled(page);
  await page.getByTestId(`${kind}-template-settings`).locator('summary').click();
  return new URL(page.url()).searchParams.get('template');
}

async function reopen(page, id, kind) {
  await page.goto(`/admin/blox_editor.php?template=${id}`);
  await waitPreviewSettled(page);
  if (await page.getByTestId('blox-recovery-dialog').isVisible().catch(() => false)) {
    await page.getByTestId('blox-recovery-discard').click();
  }
  await page.getByTestId(`${kind}-template-settings`).locator('summary').click();
}

async function api(page, action, trigger) {
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === action);
  await trigger();
  return (await response).json();
}

/** 从影响预览里拿到这条内容的真实前台链接（不猜 URL）。 */
async function frontLink(page, contentId) {
  const res = await api(page, 'preview_impact', () => page.getByTestId('blox-impact-run').click());
  expect(res.code).toBe(0);
  const all = Object.values(res.data.preview.samples).flat();
  const hit = all.find((item) => String(item.id) === String(contentId));
  expect(hit, `预览里应能找到内容 #${contentId}`).toBeTruthy();
  expect(hit.url).not.toBe('');
  return hit.url;
}

async function closure(page, visitor, { kind, language, name }) {
  const id = await createTemplate(page, kind, name, language);
  const contentId = await page.getByTestId(`${kind}-template-preview`).inputValue();
  expect(Number(contentId)).toBeGreaterThan(0);
  const marker = kind === 'product' ? '.yk-blox-product-detail' : '.yk-blox-article-detail';
  const seedSections = JSON.parse(JSON.parse(run('read', id)).draft_data).sections;
  // list-dynamic 已冻结（2026-09-20）：palette 无新增入口，两个空态动态列表
  // （message/hidden）由 fixture 以存量形态写进草稿，再重开编辑器加载
  run('inject-empty-lists', id, kind, `${kind} ${language}`);
  await reopen(page, id, kind);
  const editedText = `TB-R2 edited ${kind} ${language}`;
  await addTemporaryHeading(page);
  await page.getByTestId('blox-heading-text').fill(editedText);
  await expect((await frame(page)).getByText(editedText, { exact: true })).toBeVisible();
  await waitPreviewSettled(page);
  await expect((await frame(page)).getByText(`TB-R2 message ${kind} ${language}`, { exact: true })).toBeVisible();
  await expect((await frame(page)).getByText(`TB-R2 hidden ${kind} ${language}`, { exact: true })).toHaveCount(0);

  // 选择范围 → 诊断 → 保存 → 发布
  await page.getByTestId('blox-cond-add-include-item').click();
  await page.getByTestId('blox-cond-include-target-0').selectOption(contentId);
  await page.getByTestId('blox-cond-priority').fill('41');
  await page.getByTestId('blox-diagnose-run').click();
  await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');
  expect((await api(page, 'save_draft', () => page.getByTestId('blox-save').click())).code).toBe(0);
  const link = await frontLink(page, contentId);
  expect((await api(page, 'publish', () => page.getByTestId('blox-publish-template').click())).code).toBe(0);

  await visitor.goto(link);
  await expect(visitor.locator(`${marker}[data-template-id="${id}"]`), '发布后前台命中本模板').toHaveCount(1);
  await expect(visitor.getByText(editedText, { exact: true })).toBeVisible();
  await expect(visitor.getByText(`TB-R2 message ${kind} ${language}`, { exact: true })).toBeVisible();
  await expect(visitor.getByText(`TB-R2 hidden ${kind} ${language}`, { exact: true })).toHaveCount(0);

  // 主题默认：规则保留，前台回到主题自带详情页
  await page.goto(`/admin/${kind}_design.php`);
  await page.getByTestId(`${kind}-design-row-${id}`).getByTestId(`${kind}-design-source-select`).selectOption('native');
  await page.getByTestId(`${kind}-design-row-${id}`).getByTestId(`${kind}-design-source-apply`).click();
  await visitor.goto(link);
  await expect(visitor.locator(marker), '主题默认：不输出自定义模板').toHaveCount(0);
  await expect(visitor.getByText(editedText, { exact: true })).toHaveCount(0);
  const stored = JSON.parse(JSON.parse(run('read', id)).published_data).settings.detail_template;
  expect(stored.source).toBe('native');
  expect(stored.include, '切换主题默认不删除规则').toEqual([{ kind: 'item', ids: [Number(contentId)], include_children: false }]);

  // 不应用：清空纳入规则后发布，前台同样回到主题详情，但这与"主题默认"是两件事（source 仍保留）
  await page.goto(`/admin/${kind}_design.php`);
  await page.getByTestId(`${kind}-design-row-${id}`).getByTestId(`${kind}-design-source-select`).selectOption('custom');
  await page.getByTestId(`${kind}-design-row-${id}`).getByTestId(`${kind}-design-source-apply`).click();
  await visitor.goto(link);
  await expect(visitor.locator(`${marker}[data-template-id="${id}"]`), '切回自定义后恢复命中').toHaveCount(1);
  await expect(visitor.getByText(editedText, { exact: true })).toBeVisible();
  await reopen(page, id, kind);
  await page.getByTestId('blox-cond-remove-include-0').click();
  expect((await api(page, 'publish', () => page.getByTestId('blox-publish-template').click())).code).toBe(0);
  await visitor.goto(link);
  await expect(visitor.locator(marker), '不应用：没有自定义模板输出').toHaveCount(0);
  const unapplied = JSON.parse(JSON.parse(run('read', id)).published_data).settings.detail_template;
  expect(unapplied.include).toEqual([]);
  expect(unapplied.source).toBe('custom');
  const freshId = await createTemplate(page, kind, `TB-R2 untouched ${kind} ${language}`, language);
  try {
    const freshSections = JSON.parse(JSON.parse(run('read', freshId)).draft_data).sections;
    expect(freshSections, 'Editing a copy must not alter the built-in seed').toEqual(seedSections);
    expect(JSON.stringify(freshSections)).not.toContain(editedText);
  } finally {
    run('restore', freshId);
  }
  return id;
}

test('product, article and a non-default language each close the loop from conditions to the live page', async ({ page, browser }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop delivery baseline');
  test.setTimeout(240000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const guest = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  const visitor = await guest.newPage();
  const cleanup = [];
  try {
    cleanup.push(await closure(page, visitor, { kind: 'product', language: 'zh-CN', name: `TB-R2 delivery-product ${info.project.name}` }));
    cleanup.push(await closure(page, visitor, { kind: 'article', language: 'zh-CN', name: `TB-R2 delivery-article ${info.project.name}` }));
    cleanup.push(await closure(page, visitor, { kind: 'product', language: 'en', name: `TB-R2 delivery-en ${info.project.name}` }));
  } finally {
    await guest.close();
    cleanup.forEach((templateId) => run('restore', templateId));
  }
});

test('missing targets stay visible and removable, and product categories follow the template language', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop delivery baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let id;
  try {
    id = await createTemplate(page, 'product', `TB-R2 delivery-missing ${info.project.name}`, 'en');
    const categoryNames = await page.evaluate(() => window.Alpine.$data(document.body).conditionCategories.map((row) => row.name));
    expect(categoryNames.length).toBeGreaterThan(0);
    expect(categoryNames.some((name) => /[一-鿿]/.test(name)), '英文模板不列中文分类').toBe(false);

    const previewId = await page.getByTestId('product-template-preview').inputValue();
    run('scope', id, JSON.stringify({
      version: 2, content_type: 'product', lang: 'en', source: 'custom', priority: 0,
      include: [{ kind: 'item', ids: [Number(previewId), 987654321], include_children: false }], exclude: [],
    }));
    await reopen(page, id, 'product');
    await expect(page.getByTestId('blox-cond-include-target-0')).toHaveValues([previewId]);
    const chip = page.getByTestId('blox-cond-include-missing-0-987654321');
    await expect(chip, '缺失的目标不被悄悄隐藏').toBeVisible();
    await expect(page.getByTestId('blox-cond-dirty')).toBeHidden();
    await page.getByTestId('blox-cond-include-missing-remove-0-987654321').click();
    await expect(chip).toHaveCount(0);
    await expect(page.getByTestId('blox-cond-dirty')).toBeVisible();
    const scope = await page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).docSettings.detail_template)));
    expect(scope.include).toEqual([{ kind: 'item', ids: [Number(previewId)], include_children: false }]);
  } finally {
    if (id) run('restore', id);
  }
});

test('the condition panel is usable on a narrow screen without horizontal overflow', async ({ page }, info) => {
  test.skip(info.project.name !== 'mobile-390', 'narrow layout check');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let id;
  try {
    id = await createTemplate(page, 'product', `TB-R2 delivery-narrow ${info.project.name}`);
    await expect(page.getByTestId('blox-cond-flow')).toBeVisible();
    await page.getByTestId('blox-cond-add-include-category').click();
    await page.getByTestId('blox-cond-add-exclude-item').click();
    const target = page.getByTestId('blox-cond-include-target-0');
    await target.scrollIntoViewIfNeeded();
    const box = await target.boundingBox();
    expect(box.x).toBeGreaterThanOrEqual(0);
    expect(box.x + box.width, '多选框不超出屏幕').toBeLessThanOrEqual(390);
    const overflow = await page.evaluate(() => {
      const panel = document.querySelector('[data-testid="blox-detail-conditions"]');
      return { page: document.documentElement.scrollWidth - document.documentElement.clientWidth, panel: panel.scrollWidth - panel.clientWidth };
    });
    expect(overflow.page, '页面没有横向滚动').toBeLessThanOrEqual(0);
    expect(overflow.panel, '条件面板没有横向溢出').toBeLessThanOrEqual(0);
    for (const testId of ['blox-diagnose-run', 'blox-impact-run', 'blox-publish-check-run']) {
      const button = page.getByTestId(testId);
      await button.scrollIntoViewIfNeeded();
      await expect(button).toBeVisible();
    }
    await page.getByTestId('blox-detail-conditions').screenshot({ path: info.outputPath('condition-panel-390.png') });
  } finally {
    if (id) run('restore', id);
  }
});
