const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

const fixture = (action, id = '') => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), action, String(id)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });

// 与 product-template.spec.js 同款的命令按钮助手：窄屏走移动面板
async function command(page, action, button) {
  if (!await page.getByTestId(button).isVisible()) {
    await page.getByTestId('blox-mobile-canvas-view').click();
    await page.getByTestId('blox-mobile-actions-open').click();
    button = button.replace('blox-', 'blox-mobile-');
  }
  const response = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === action);
  await page.getByTestId(button).click();
  expect((await (await response).json()).code).toBe(0);
}

async function createProductTemplate(page, name) {
  await page.goto('/admin/site_design.php');
  await page.getByTestId('site-design-products').click();
  await page.getByTestId('product-design-name').fill(name);
  await page.locator('select[name="language"]').selectOption('zh-CN');
  await page.getByTestId('product-design-create').click();
  await expect(page).toHaveURL(/blox_editor.php\?template=/);
  await waitPreviewSettled(page);
  await page.getByTestId('product-template-settings').locator('summary').click();
  return { id: new URL(page.url()).searchParams.get('template'), editor: page.url() };
}

// TASK-008：按指定内容的分类命中（不猜 ID，直接读选择器里的取值）
async function addCategoryRuleMatchingPreview(page) {
  await page.getByTestId('blox-cond-add-include-category').click();
  const options = await page.getByTestId('blox-cond-include-target-0')
    .locator('option').evaluateAll((list) => list.map((option) => option.value));
  expect(options.length).toBeGreaterThan(0);
  await page.getByTestId('blox-cond-include-target-0').selectOption(options[0]);
  return options[0];
}

test('diagnosis of a product content uses the real resolver and stays read-only', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop diagnosis baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  let id;
  try {
    const created = await createProductTemplate(page, `TB-R2 diag ${info.project.name}`);
    id = created.id;

    // 诊断对象＝"预览内容"当前选中的那条内容；规则就按它所属分类来写
    const contentId = await page.getByTestId('product-template-preview').inputValue();
    expect(Number(contentId)).toBeGreaterThan(0);
    const categoryId = await addCategoryRuleMatchingPreview(page);

    // 先诊断一次（未发布草稿也可以诊断）
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');
    await expect(page.getByTestId('blox-diagnose-reason')).toContainText('按分类/栏目命中');
    await expect(page.getByTestId('blox-diagnose-winner-id')).toHaveText(String(id));
    await expect(page.getByTestId('blox-diagnose-winner-none')).toBeHidden();
    await expect(page.getByTestId('blox-diagnose-stale')).toBeHidden();

    // 只读：诊断不改 dirty、不改库内文档
    const dirtyBefore = await page.evaluate(() => window.Alpine.$data(document.body).dirty);
    const storedBefore = JSON.parse(JSON.parse(fixture('read', id)).draft_data);
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty), '诊断不得改变 dirty').toBe(dirtyBefore);
    const storedAfter = JSON.parse(JSON.parse(fixture('read', id)).draft_data);
    expect(storedAfter, '诊断不得写库').toEqual(storedBefore);

    // 保存下来，再发布；随后用同一份规则诊断：自身旧发布版本必须被排除，不能自造并列
    const saved = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    await saved;
    await command(page, 'publish', 'blox-publish-template');
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-verdict'), '自身旧版本排除后仍是命中而不是并列').toContainText('会命中本模板');
    await expect(page.getByTestId('blox-diagnose-conflicts')).toHaveCount(0);

    // 排除规则生效：同一条内容被 exclude 命中后不再由本模板胜出
    await page.getByTestId('blox-cond-add-exclude-category').click();
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(categoryId);
    await page.getByTestId('blox-cond-exclude-children-0').check();
    // 条件一改，上一次结果立刻被标为过期（不自动重查）
    await expect(page.getByTestId('blox-diagnose-stale')).toBeVisible();
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-reason')).not.toContainText('按分类/栏目命中');
    await expect(page.getByTestId('blox-diagnose-stale')).toBeHidden();
    await expect(page.getByTestId('blox-diagnose-verdict')).not.toContainText('会命中本模板');
    await expect(page.getByTestId('blox-diagnose-winner-none')).toBeVisible();
  } finally {
    if (id) fixture('restore', id);
  }
});

test('diagnosis reports a real tie and covers article templates', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop diagnosis baseline');
  test.setTimeout(120000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const ids = [];
  try {
    // 第一条产品模板：同一份规则，发布后作为"另一个已发布候选"
    const first = await createProductTemplate(page, `TB-R2 diag-a ${info.project.name}`);
    ids.push(first.id);
    const previewId = await page.getByTestId('product-template-preview').inputValue();
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-0').selectOption(previewId);
    const savedA = page.waitForRequest((request) => request.url().includes('/admin/blox_template_api.php')
      && new URLSearchParams(request.postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    await savedA;
    await command(page, 'publish', 'blox-publish-template');

    // 第二条产品模板：完全相同的规则 → 真实并列（同具体度同优先级）
    const second = await createProductTemplate(page, `TB-R2 diag-b ${info.project.name}`);
    ids.push(second.id);
    await page.getByTestId('product-template-preview').selectOption(previewId);
    await page.getByTestId('blox-cond-add-include-item').click();
    await page.getByTestId('blox-cond-include-target-0').selectOption(previewId);
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-verdict'), '同级同优先级真实并列').toContainText('并列');
    const conflicts = await page.getByTestId('blox-diagnose-conflicts').textContent();
    expect(conflicts.split(',').map((value) => value.trim()).sort()).toEqual(ids.map(String).sort());

    // 具体度先于优先级：把 B 改成"按分类"（更不具体）但优先级拉到 10，A 的"指定内容"仍应胜出
    const directCategory = await page.evaluate(() => {
      const categories = window.Alpine.$data(document.body).conditionDiagnosis.content.categories;
      return categories.length ? String(categories[0].id) : '';
    });
    expect(directCategory, '诊断结果里应带这条内容的直接分类').not.toBe('');
    await page.getByTestId('blox-cond-include-kind-0').selectOption('category');
    await page.getByTestId('blox-cond-include-target-0').selectOption(directCategory);
    await page.getByTestId('blox-cond-priority').fill('10');
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-verdict'), '更具体的 A 胜出，优先级高的 B 落选').toContainText('另一个模板命中');
    await expect(page.getByTestId('blox-diagnose-winner-id')).toHaveText(String(first.id));

    // 文章模板：同样能诊断。先用"全部文章"确认命中，再从诊断结果里取这条内容的直接栏目，
    // 改成栏目规则再诊断一次——不猜栏目 ID。
    await page.goto('/admin/site_design.php');
    await page.getByTestId('site-design-articles').click();
    await page.getByTestId('article-design-name').fill(`TB-R2 diag-art ${info.project.name}`);
    await page.locator('select[name="language"]').selectOption('zh-CN');
    await page.getByTestId('article-design-create').click();
    await expect(page).toHaveURL(/blox_editor.php\?template=/);
    const articleTemplateId = new URL(page.url()).searchParams.get('template');
    ids.push(articleTemplateId);
    await waitPreviewSettled(page);
    await page.getByTestId('article-template-settings').locator('summary').click();
    const articleContentId = await page.getByTestId('article-template-preview').inputValue();
    expect(Number(articleContentId)).toBeGreaterThan(0);

    await page.getByTestId('blox-cond-add-include-all').click();
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-reason')).toContainText('全部命中');
    await expect(page.getByTestId('blox-diagnose-winner-id')).toHaveText(String(articleTemplateId));

    const directChannel = await page.evaluate(() => {
      const categories = window.Alpine.$data(document.body).conditionDiagnosis.content.categories;
      return categories.length ? String(categories[0].id) : '';
    });
    expect(directChannel, '诊断结果里应带这条内容的直接栏目').not.toBe('');
    await page.getByTestId('blox-cond-include-kind-0').selectOption('category');
    await page.getByTestId('blox-cond-include-target-0').selectOption(directChannel);
    // 诊断是只读的：调用前后服务端草稿必须逐字节一致（文章模板本身没有保存过条件）
    const storedBefore = JSON.parse(fixture('read', articleTemplateId)).draft_data;
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-result')).toBeVisible();
    await expect(page.getByTestId('blox-diagnose-reason'), '按栏目命中').toContainText('分类/栏目');
    expect(JSON.parse(fixture('read', articleTemplateId)).draft_data, '诊断不得写库').toEqual(storedBefore);
  } finally {
    ids.forEach((templateId) => fixture('restore', templateId));
  }
});
