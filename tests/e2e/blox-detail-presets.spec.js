/**
 * 起步模板：模板库出卡片 → 一键安装成草稿 → 编辑器可打开且区块齐全。
 * 安装只生成草稿（不发布、不影响前台），与网页头/尾预设同一条 Importer 链。
 * 整页起步（page 类型）另验：装进库后能在页面编辑器里「整页替换」到目标页面。
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

const SLUGS = ['classic-article-detail', 'showcase-case-detail', 'classic-product-detail'];
const PAGE_SLUGS = ['product-center-page', 'news-center-page', 'case-gallery-page'];

test('detail starters install as drafts and open in the editor @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await page.goto('/admin/blox_templates.php', { waitUntil: 'domcontentloaded' });

  // 三张详情起步卡都在预设区，各带线框预览
  const presets = page.getByTestId('blox-area-presets');
  for (const slug of [...SLUGS, ...PAGE_SLUGS]) {
    await expect(presets.locator(`input[name="slug"][value="${slug}"]`)).toHaveCount(1);
  }
  expect(await presets.getByTestId('blox-preset-wire').count()).toBeGreaterThanOrEqual(18);

  // 类型筛选到文章详情时，预设区只剩两张文章详情卡
  await page.goto('/admin/blox_templates.php?type=article-detail', { waitUntil: 'domcontentloaded' });
  const filtered = page.getByTestId('blox-area-presets');
  await expect(filtered.locator('input[name="slug"][value="classic-article-detail"]')).toHaveCount(1);
  await expect(filtered.locator('input[name="slug"][value="showcase-case-detail"]')).toHaveCount(1);
  await expect(filtered.locator('input[name="slug"][value="classic-product-detail"]')).toHaveCount(0);

  // 一键安装文章详情起步 → 回到列表并带 imported=ID
  await filtered
    .locator('form:has(input[value="classic-article-detail"])')
    .getByTestId('blox-area-preset-install')
    .click();
  await page.waitForURL('**/admin/blox_templates.php?imported=*');
  const installedId = Number(new URL(page.url()).searchParams.get('imported'));
  expect(installedId).toBeGreaterThan(0);

  // 打开编辑器：3 个命名区块（文章头区/正文/翻页与推荐）原样进画布
  await page.goto(`/admin/blox_editor.php?template=${installedId}`, { waitUntil: 'domcontentloaded' });
  await page.waitForSelector('[data-testid="blox-save"]', { timeout: 15000 });
  const sections = await page.evaluate(() => {
    const alpine = window.Alpine.$data(document.body);
    return alpine.sections.map(section => ({
      name: section.name || '',
      elements: section.columns.flatMap(column => column.elements.map(el => el.type)),
    }));
  });
  expect(sections.length).toBe(3);
  expect(sections[0].elements).toEqual(['article-title', 'article-meta', 'article-summary']);
  expect(sections[1].elements).toEqual(['article-cover', 'article-content']);
  expect(sections[2].elements).toEqual(['article-prev-next', 'article-related']);

  // 重复安装＝更新草稿（幂等），不新建第二份
  await page.goto('/admin/blox_templates.php?type=article-detail', { waitUntil: 'domcontentloaded' });
  await page
    .getByTestId('blox-area-presets')
    .locator('form:has(input[value="classic-article-detail"])')
    .getByTestId('blox-area-preset-install')
    .click();
  await page.waitForURL('**/admin/blox_templates.php?imported=*');
  expect(Number(new URL(page.url()).searchParams.get('imported'))).toBe(installedId);
});

test('full-page starter installs to the library and applies to a real page @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');

  // 按整页类型筛选：三张整页起步卡在，详情卡不在
  await page.goto('/admin/blox_templates.php?type=page', { waitUntil: 'domcontentloaded' });
  const presets = page.getByTestId('blox-area-presets');
  for (const slug of PAGE_SLUGS) {
    await expect(presets.locator(`input[name="slug"][value="${slug}"]`)).toHaveCount(1);
  }
  await expect(presets.locator('input[name="slug"][value="classic-article-detail"]')).toHaveCount(0);

  // 装进模板库（仍是草稿，不碰任何页面）
  await presets
    .locator('form:has(input[value="product-center-page"])')
    .getByTestId('blox-area-preset-install')
    .click();
  await page.waitForURL('**/admin/blox_templates.php?imported=*');
  const installedId = Number(new URL(page.url()).searchParams.get('imported'));
  expect(installedId).toBeGreaterThan(0);

  // 编辑器模板库只收已发布模板：整页起步装完还要发布一次（page 类型非条件类型，
  // 发布只是「放进模板库」，不会改动任何前台页面）。卡片提示已写明这一步。
  await expect(page.getByTestId('blox-area-presets').locator('text=/发布|publish|公開/i').first()).toBeVisible();
  await page.evaluate(async (id) => {
    const body = new FormData();
    body.append('action', 'publish');
    body.append('id', String(id));
    const response = await fetch('/admin/blox_templates.php', { method: 'POST', body });
    if (!response.ok) throw new Error('publish failed: ' + response.status);
  }, installedId);

  // 到真实页面的编辑器里「套用整页」：模板库 → 找到该模板 → 整页替换
  await openPageEditor(page, fixtures.blox_page);
  const before = await page.evaluate(() => window.Alpine.$data(document.body).sections.length);
  expect(before).toBeGreaterThan(0);

  await page.evaluate(() => window.Alpine.$data(document.body).openTemplates());
  await expect(page.getByTestId('blox-template-dialog')).toBeVisible();
  await expect
    .poll(() => page.evaluate(() => window.Alpine.$data(document.body).templateItems.length), { timeout: 10000 })
    .toBeGreaterThan(0);

  // 整页替换要过确认（不能默默丢掉现有内容）
  page.once('dialog', dialog => dialog.accept());
  await page.evaluate((id) => {
    const alpine = window.Alpine.$data(document.body);
    const item = alpine.templateItems.find(entry => String(entry.key).includes(String(id)));
    if (!item) throw new Error('installed page template not listed: ' + id);
    alpine.replaceWithTemplate(item);
  }, installedId);

  // 画布换成整页起步的三个区块，元素与模板一致
  await expect
    .poll(() => page.evaluate(() => window.Alpine.$data(document.body).sections.length), { timeout: 15000 })
    .toBe(3);
  const applied = await page.evaluate(() => window.Alpine.$data(document.body).sections
    .map(section => section.columns.flatMap(column => column.elements.map(el => el.type))));
  expect(applied).toEqual([['page-title'], ['product-catalog'], ['cta']]);

  // 只改草稿画布：未保存前页面仍是脏态，前台不受影响
  expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(true);
});
