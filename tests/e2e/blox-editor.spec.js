const { test, expect } = require('@playwright/test');
const {
  addTemporaryHeading,
  canvasScrollTop,
  countCanvasSections,
  countDynamicHomeBlocks,
  countSections,
  dragElement,
  frame,
  observeConsole,
  observeUnsafeWrites,
  openEditor,
  performPreviewUpdate,
  restoreClean,
  scrollCanvasToBottom,
  undo,
} = require('./helpers');

let consoleEntries;
let unsafeWrites;

test.beforeEach(async ({ page }, testInfo) => {
  consoleEntries = null;
  unsafeWrites = null;
  test.skip(testInfo.project.name !== 'desktop-1440' && testInfo.title !== 'viewport contract @ci', 'desktop interaction baseline');
  consoleEntries = observeConsole(page);
  unsafeWrites = observeUnsafeWrites(page);
  await openEditor(page);
});

test.afterEach(async ({ page }) => {
  if (!consoleEntries || !unsafeWrites) return;
  const leakedDirtyState = await page.getByTestId('blox-dirty').isVisible().catch(() => false);
  if (leakedDirtyState) await restoreClean(page);
  expect(leakedDirtyState, 'test left the editor dirty').toBe(false);
  expect(unsafeWrites, 'save/publish/rollback request was sent').toEqual([]);
  expect(consoleEntries, 'browser console must stay clean').toEqual([]);
});

test('viewport contract @ci', async ({ page }, testInfo) => {
  const pageOverflow = await page.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(pageOverflow).toBe(0);

  const contentFrame = await frame(page);
  const frameOverflow = await contentFrame.evaluate(() => document.documentElement.scrollWidth - document.documentElement.clientWidth);
  expect(frameOverflow).toBe(0);

  const treeCount = await countSections(page);
  const canvasCount = await countCanvasSections(page);
  const dynamicCount = await countDynamicHomeBlocks(page);
  expect(dynamicCount).toBeGreaterThan(0);
  expect(canvasCount).toBe(treeCount);
  await expect(page.getByTestId('blox-save')).toBeEnabled();
  await expect(page.getByTestId('blox-publish')).toBeEnabled();
  await expect(page.getByTestId('blox-rollback')).toBeDisabled();

  if (testInfo.project.name === 'mobile-390') {
    await expect(page.getByTestId('blox-desktop-actions')).toBeHidden();
    await expect(page.getByTestId('blox-mobile-actions')).toBeVisible();
    await page.getByTestId('blox-mobile-actions-open').click();
    const menu = page.locator('.blox-mobile-actions-menu');
    await expect(menu).toBeVisible();
    await expect(menu.getByRole('button', { name: /撤销/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /重做/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /模板/ })).toBeVisible();
    await expect(menu.getByRole('link', { name: /前台/ })).toBeVisible();
    await expect(menu.getByRole('button', { name: /保存/ })).toBeVisible();
  } else {
    await expect(page.getByTestId('blox-desktop-actions')).toBeVisible();
    await expect(page.getByTestId('blox-mobile-actions')).toBeHidden();
  }
});

test('inline edit patches preview and preserves scroll @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const { sectionIndex } = await addTemporaryHeading(page);
  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"] h1, [data-yk-el="${sectionIndex}.0.0"] h2, [data-yk-el="${sectionIndex}.0.0"] h3, [data-yk-el="${sectionIndex}.0.0"] h4`).first();
  const originalText = await heading.innerText();

  await scrollCanvasToBottom(page);
  const scrollBefore = await canvasScrollTop(page);
  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('dblclick', {
    bubbles: true,
    cancelable: true,
  })));
  await expect(heading).toHaveAttribute('contenteditable', /true|plaintext-only/);
  await heading.evaluate((element) => {
    element.textContent = 'E2E 局部预览标题';
    element.dispatchEvent(new element.ownerDocument.defaultView.InputEvent('input', { bubbles: true }));
  });
  await performPreviewUpdate(page, () => heading.evaluate((element) => element.blur()));
  await expect(heading).not.toHaveAttribute('contenteditable', /.+/);
  await expect(page.getByTestId('blox-tree-section').last().getByTestId('blox-tree-element')).toContainText('E2E 局部预览标题');
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText('E2E 局部预览标题');
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  expect(page.url()).toBe(originalURL);

  await undo(page);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`)).toContainText(originalText);
  await expect.poll(() => canvasScrollTop(page)).toBeCloseTo(scrollBefore, 1);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('unchanged inline blur does not create history @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const contentFrame = await frame(page);
  const field = contentFrame.locator('[data-yk-home-field]').filter({ visible: true }).first();
  await expect(field).toBeVisible();
  const value = await field.innerText();
  await field.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('dblclick', { bubbles: true, cancelable: true })));
  await field.evaluate((element) => element.blur());
  await expect(field).not.toHaveAttribute('contenteditable', /.+/);
  await expect(field).toHaveText(value);
  await expect(page.getByTestId('blox-undo')).toBeDisabled();
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('clipboard copy paste and undo @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page);
  const column = section.getByTestId('blox-tree-column').first();
  const contentFrame = await frame(page);
  const heading = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.0"]`).first();
  const before = await column.getByTestId('blox-tree-element').count();

  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 24, clientY: 24 })));
  await page.getByTestId('blox-context-copy').click();
  await expect(page.getByTestId('blox-toast')).toBeVisible();
  await heading.evaluate((element) => element.dispatchEvent(new element.ownerDocument.defaultView.MouseEvent('contextmenu', { bubbles: true, cancelable: true, clientX: 24, clientY: 24 })));
  await page.getByTestId('blox-context-paste').click();
  await expect(column.getByTestId('blox-tree-element')).toHaveCount(before + 1);
  await expect(page.getByTestId('blox-toast')).toBeVisible();

  await undo(page);
  await expect(column.getByTestId('blox-tree-element')).toHaveCount(before);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('cross-column drag survives Sortable rebind @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page, 2);
  const columns = section.getByTestId('blox-tree-column');
  const first = columns.nth(0);
  const second = columns.nth(1);
  const contentFrame = await frame(page);

  const expectCounts = async (left, right) => {
    await expect(first.getByTestId('blox-tree-element')).toHaveCount(left);
    await expect(second.getByTestId('blox-tree-element')).toHaveCount(right);
  };

  await expectCounts(1, 0);
  await dragElement(first.getByTestId('blox-tree-element'), second, page);
  await expectCounts(0, 1);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.1.0"]`)).toHaveCount(1);

  await undo(page);
  await expectCounts(1, 0);
  await dragElement(first.getByTestId('blox-tree-element'), second, page);
  await expectCounts(0, 1);
  await expect(contentFrame.locator(`[data-yk-el="${sectionIndex}.1.0"]`)).toHaveCount(1);

  await undo(page);
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('local template insertion uses catalog resolve without reload @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  await page.route('**/admin/blox_template_api.php?action=list**', async (route) => {
    await route.fulfill({
      status: 200,
      contentType: 'application/json',
      body: JSON.stringify({
        code: 0,
        msg: 'ok',
        data: {
          items: [{
            key: 'local:1',
            type: 'section',
            name: 'E2E 本地区块',
            source: 'local',
            provider: 'import',
            updated_at: 1,
            paid: false,
            locked: false,
            locked_reason: '',
          }],
          remote_error: '',
        },
      }),
    });
  });
  const originalURL = page.url();
  const before = await countSections(page);
  await page.getByTestId('blox-templates-open').click();
  const item = page.getByTestId('blox-template-item');
  await expect(item).toHaveCount(1);
  await expect(item).toBeEnabled();
  await item.click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  await expect((await frame(page)).locator('[data-yk-el-type="heading"]')).toContainText('E2E 模板标题');
  expect(page.url()).toBe(originalURL);
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

test('real remote template channel @local', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440' || process.env.BLOX_E2E_REMOTE !== '1', 'opt-in signed remote channel check');
  await page.getByTestId('blox-templates-open').click();
  const remote = page.getByTestId('blox-template-item').filter({ has: page.locator('[class*="ti-cloud-download"]') }).first();
  await expect(remote).toBeVisible();
});

// ── 语言档：编辑器 chrome 三语化验收 ──────────────────────────────
// en：chrome（顶栏+元素库面板）不得含 CJK。ja 无法用「无 CJK」断言——日语汉字
// 与中文同在 CJK 统一表意区间——改为断言日语标记文案已生效。
// 结构树/画布不在断言范围：它们显示用户文档内容（中文示例数据），属合法中文。
const { execFileSync } = require('child_process');
const setAdminLang = (lang) => execFileSync('php', ['tests/e2e/set-lang.php', lang], { cwd: require('path').resolve(__dirname, '../..') });

test('editor chrome localizes to en and ja @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  try {
    setAdminLang('en');
    await page.reload();
    await expect(page.getByTestId('blox-tree')).toBeVisible();
    const headerText = await page.locator('.blox-editor-header').innerText();
    expect(headerText, 'en header must not contain CJK').not.toMatch(/[\u4e00-\u9fff]/);
    // 元素库面板默认展开，直接断言其标题与分类已英语化
    await expect(page.getByText('Element library').first()).toBeVisible();

    setAdminLang('ja');
    await page.reload();
    await expect(page.getByTestId('blox-tree')).toBeVisible();
    await expect(page).toHaveTitle(/エディター/);
    await expect(page.getByText('要素ライブラリ').first()).toBeVisible();
  } finally {
    setAdminLang('zh-CN');
  }
  await page.reload();
  await expect(page.getByTestId('blox-tree')).toBeVisible();
});

// ── 模板模式（r7）：头模板草稿编辑 = 可编辑区 + 首页正文只读灰罩上下文 ──
test('template mode edits header draft with dimmed context @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-canvas')).toBeVisible();
  await expect(page.getByTestId('blox-tree')).toBeAttached();
  await page.waitForFunction(() => {
    const frame = document.querySelector('[data-testid="blox-canvas"]');
    return frame && frame.contentDocument && frame.contentDocument.readyState === 'complete'
      && frame.contentDocument.querySelectorAll('[data-yk-sec]').length > 0;
  });

  // 画布合成契约：可编辑模板段 + 灰罩上下文（上下文不带编辑标记）
  const contentFrame = await frame(page);
  await expect(contentFrame.locator('.yk-ctx-dim')).toHaveCount(1);
  const dimEditable = await contentFrame.locator('.yk-ctx-dim [data-yk-sec]').count();
  expect(dimEditable, 'context body must not be editable').toBe(0);

  // 模板模式工具栏：发布模板按钮在场；首页发布/回退不在场
  await expect(page.getByTestId('blox-publish-template')).toBeAttached();
  await expect(page.getByTestId('blox-publish')).toHaveCount(0);
  await expect(page.getByTestId('blox-rollback')).toHaveCount(0);

  // 编辑 → 存草稿 → 重载持久化 → API 复位种子（幂等：无论此前状态如何，
  // 结束时草稿 = fixture 单源内容；审计指出「接受种子增长」违背可重复基线）。
  const seedDoc = JSON.stringify(JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, 'fixtures/header-template.json'), 'utf8')).document);
  const before = await countSections(page);
  await page.getByTestId('blox-add-section-1').click();
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  const savePair = Promise.all([
    page.waitForRequest((r) => r.method() === 'POST' && new URL(r.url()).pathname === '/admin/blox_template_api.php'),
    page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/blox_template_api.php'),
  ]);
  await page.getByTestId('blox-save').click();
  const [saveReq, saveRes] = await savePair;
  expect((await saveRes.json()).code).toBe(0);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();

  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);

  // 复位：CSRF 从真实保存请求捕获，API 直写种子文档（不走 UI，无脆弱定位）
  const token = new URLSearchParams(saveReq.postData() || '').get('_token');
  const restore = await page.request.post('/admin/blox_template_api.php', {
    form: {
      action: 'save_draft',
      id: String(fixtures.blox_header_template),
      blocks_data: seedDoc,
      _token: token || '',
    },
  });
  expect((await restore.json()).code).toBe(0);
  await page.reload({ waitUntil: 'domcontentloaded' });
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(1);
});

// ── 模板模式（r9）：预览上下文选择器——换正文 + Resolver 命中上报 ──
test('template mode context selector swaps body and reports resolver hit @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const fixtures = JSON.parse(require('fs').readFileSync(
    require('path').resolve(__dirname, '../smoke/fixtures.json'), 'utf8'));
  await page.goto('/admin/blox_editor.php?template=' + fixtures.blox_header_template,
    { waitUntil: 'domcontentloaded' });
  const canvasReady = () => page.waitForFunction(() => {
    const f = document.querySelector('[data-testid="blox-canvas"]');
    return f && f.contentDocument && f.contentDocument.readyState === 'complete'
      && f.contentDocument.querySelector('[data-yk-area]') !== null;
  });
  await canvasReady();

  // 头尾模板独有：上下文 select 在场，默认首页；命中 id 由画布上报（属性契约）
  const ctxSelect = page.getByTestId('blox-ctx-select');
  await expect(ctxSelect).toBeVisible();
  await expect(ctxSelect).toHaveValue('home');
  let contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area][data-yk-ctx-hit]')).toHaveCount(1);

  // 切到第二个选项（真实栏目/单页）→ 画布重载：正文换上下文、灰罩契约不变、命中标记仍在
  const second = await ctxSelect.locator('option').nth(1).getAttribute('value');
  expect(second).toBeTruthy();
  await ctxSelect.selectOption(second);
  await canvasReady();
  contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area][data-yk-ctx-hit]')).toHaveCount(1);
  await expect(contentFrame.locator('.yk-ctx-dim')).toHaveCount(1);
  expect(await contentFrame.locator('.yk-ctx-dim [data-yk-sec]').count(),
    'context body must not be editable').toBe(0);

  // 切回首页 → 同契约
  await ctxSelect.selectOption('home');
  await canvasReady();
  contentFrame = await frame(page);
  await expect(contentFrame.locator('[data-yk-area][data-yk-ctx-hit]')).toHaveCount(1);
});

// ── 画布插入轨道（r13）：区块边界精确插入 + 末尾常驻入口 ──
test('canvas insert rails add section at exact boundary @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const before = await countSections(page);
  expect(before).toBeGreaterThan(1);
  const contentFrame = await frame(page);

  // 第 2 个区块的上缘轨道：点「+」出快捷面板，选两列 → 新区块插在 index 1
  const rail = contentFrame.locator('[data-yk-insert="1"]');
  await rail.evaluate((el) => el.click());
  const pop = contentFrame.locator('.yk-insert-pop');
  await expect(pop).toBeVisible();
  await pop.locator('.yk-insert-pop-btn').nth(1).evaluate((el) => el.click()); // 两列
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before + 1);
  // 插入即选中，且位置正确（selectedSi=1 → 结构树第 2 项高亮由选择态保证；直接断言画布新区块两列）
  await expect(contentFrame.locator('[data-yk-sec="1"] [data-yk-col]')).toHaveCount(2);

  // 一次 undo 完整撤销
  await undo(page);
  await expect(page.getByTestId('blox-tree-section')).toHaveCount(before);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();

  // 末尾常驻条在场（非空文档）
  await expect(contentFrame.locator('.yk-insert-rail-tail .yk-insert-btn')).toBeVisible();
});

// ── 面包屑（r14）：选择模型的第二视图，点父级两击内回到区块 ──
test('breadcrumb reflects selection and climbs to parent @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const { section, sectionIndex } = await addTemporaryHeading(page);
  // 选中新加的标题元素 → 面包屑 = 区块 > 列 > 元素（3 项，末项高亮）
  await section.getByTestId('blox-tree-element').first().click();
  const crumb = page.getByTestId('blox-breadcrumb');
  await expect(crumb).toBeVisible();
  await expect(crumb.locator('button')).toHaveCount(3);
  // 点第一项 → 回到区块层（面包屑收敛为 1 项），选择仍在该区块
  await crumb.locator('button').first().click();
  await expect(crumb.locator('button')).toHaveCount(1);
  // 清理：临时标题=加区块+加元素两个历史项，undo 两次回 clean
  await undo(page);
  await undo(page);
  await expect(page.getByTestId('blox-dirty')).toBeHidden();
});

// ── 点空白取消选择（用户报告的回归，2026-08-08）──
test('clicking blank canvas deselects tree selection @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  // 结构树选中首个区块 → 左栏出现「取消选中（改为插入到末尾）」提示按钮
  await page.getByTestId('blox-tree-section').first().click();
  await expect(page.getByTestId('blox-clear-selection')).toBeVisible();

  // 点画布内空白 → 选择清除。首页画布内容从 y=0 铺满（真实空白只在内容间隙/尾部），
  // 坐标点击会命中 section——用合成 body 点击验证「未命中 yk 目标 → ykClear」链路。
  const contentFrame = await frame(page);
  await contentFrame.locator('body').evaluate((body) => body.click());
  await expect(page.getByTestId('blox-clear-selection')).toBeHidden();

  // 再选中一次，点画布宿主空白（iframe 外灰底）→ 同样清除
  await page.getByTestId('blox-tree-section').first().click();
  await expect(page.getByTestId('blox-clear-selection')).toBeVisible();
  // y 避开顶部面包屑条（r14 起有选择时显示在 host 顶部），点画布左侧灰底空白
  await page.getByTestId('blox-canvas-host').click({ position: { x: 4, y: 100 } });
  await expect(page.getByTestId('blox-clear-selection')).toBeHidden();
});

// ── Bootstrap Icons：双图库选择、搜索、预览资源加载 ──
test('Bootstrap icon picker selects and renders without reload @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop interaction baseline');
  const originalURL = page.url();
  const { sectionIndex } = await addTemporaryHeading(page);

  await page.getByTestId('blox-library-open').click();
  await page.getByTestId('blox-add-element-icon').click();
  await expect(page.getByTestId('blox-icon-value')).toBeVisible();
  await page.getByTestId('blox-icon-library-toggle').click();
  await page.getByTestId('blox-icon-provider-bootstrap').click();
  await page.getByTestId('blox-icon-search').fill('house-door');
  await expect(page.getByTestId('blox-icon-option-bi-house-door')).toBeVisible();

  await performPreviewUpdate(page, () => page.getByTestId('blox-icon-option-bi-house-door').click());
  await expect(page.getByTestId('blox-icon-value')).toHaveValue('bi:house-door');
  const contentFrame = await frame(page);
  const icon = contentFrame.locator(`[data-yk-el="${sectionIndex}.0.1"] i.bi.bi-house-door`);
  await expect(icon).toHaveCount(1);
  const fontFamily = await icon.evaluate((element) => getComputedStyle(element, '::before').fontFamily);
  expect(fontFamily.toLowerCase()).toContain('bootstrap-icons');
  expect(page.url()).toBe(originalURL);

  await restoreClean(page);
});