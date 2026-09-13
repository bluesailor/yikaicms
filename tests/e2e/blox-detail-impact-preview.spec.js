const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');
const { waitPreviewSettled } = require('./helpers');

// 第四轮：影响范围预览。只读、分页；计数只在检查完全部内容后才是总数。
const run = (...args) => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'product-template-fixture.php'), ...args.map(String)],
  { cwd: path.resolve(__dirname, '../..'), encoding: 'utf8' });
const read = (id) => JSON.parse(run('read', id));

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

function watchPreview(page) {
  const log = [];
  page.on('response', async (response) => {
    if (new URL(response.url()).pathname !== '/admin/blox_template_api.php') return;
    if (new URLSearchParams(response.request().postData() || '').get('action') !== 'preview_impact') return;
    try { log.push(await response.json()); } catch (error) { log.push(null); }
  });
  return log;
}

async function preview(page, log, button = 'blox-impact-run') {
  const before = log.length;
  await page.getByTestId(button).click();
  await expect.poll(() => log.length).toBeGreaterThan(before);
  await expect(page.getByTestId('blox-impact-result')).not.toHaveAttribute('data-status', 'running');
  return log[log.length - 1];
}

test('preview separates winning and excluded content, links to real pages and stays read-only', async ({ page, request }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop impact baseline');
  test.setTimeout(150000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchPreview(page);
  let id;
  try {
    id = await createTemplate(page, 'product', `TB-R2 impact ${info.project.name}`);
    const previewId = await page.getByTestId('product-template-preview').inputValue();

    // 空条件：明确"不应用于任何内容"
    let res = await preview(page, log);
    expect(res.code).toBe(0);
    await expect(page.getByTestId('blox-impact-result')).toHaveAttribute('data-status', 'empty');

    // 全部产品 + 排除预览产品
    await page.getByTestId('blox-cond-add-include-all').click();
    await page.getByTestId('blox-cond-add-exclude-item').click();
    await page.getByTestId('blox-cond-exclude-target-0').selectOption(previewId);
    const dirtyBefore = await page.evaluate(() => window.Alpine.$data(document.body).dirty);
    const draftBefore = read(id).draft_data;
    res = await preview(page, log);
    expect(res.code).toBe(0);
    const data = res.data.preview;
    expect(data.complete).toBe(true);
    await expect(page.getByTestId('blox-impact-result')).toHaveAttribute('data-status', 'complete');
    expect(data.counts.excluded).toBe(1);
    expect(data.counts.won).toBeGreaterThan(0);
    expect(data.counts.won + data.counts.conflicted + data.counts.lost + data.counts.excluded).toBeLessThanOrEqual(data.total);
    await expect(page.getByTestId('blox-impact-count-excluded')).toHaveText('共 1 条');
    await expect(page.getByTestId(`blox-impact-sample-excluded-${previewId}`)).toBeVisible();
    expect(JSON.stringify(data), '实例不带正文').not.toContain('"content"');

    // 只读：不改 dirty、不写库
    expect(await page.evaluate(() => window.Alpine.$data(document.body).dirty)).toBe(dirtyBefore);
    expect(read(id).draft_data).toBe(draftBefore);

    // 与单条诊断一致：胜出实例诊断为命中，被排除实例诊断为被排除
    const winner = data.samples.won[0];
    await page.getByTestId('product-template-preview').selectOption(String(winner.id));
    await page.getByTestId('blox-diagnose-run').click();
    await expect(page.getByTestId('blox-diagnose-verdict')).toContainText('会命中本模板');
    await expect(page.getByTestId('blox-impact-stale'), '预览内容不改条件，不算过期').toBeHidden();

    // 链接是真实前台地址
    const link = await page.getByTestId(`blox-impact-link-${winner.id}`).getAttribute('href');
    expect(link).toBe(winner.url);
    const front = await request.get(link);
    expect(front.status()).toBe(200);
    expect(await front.text()).toContain(winner.title);

    // 改条件后预览立即过期
    await page.getByTestId('blox-cond-priority').fill('3');
    await expect(page.getByTestId('blox-impact-stale')).toBeVisible();

    // 非法条件不发请求
    await page.getByTestId('blox-cond-add-include-item').click();
    const count = log.length;
    await page.getByTestId('blox-impact-run').click();
    await page.waitForTimeout(600);
    expect(log.length).toBe(count);
  } finally {
    if (id) run('restore', id);
  }
});

test('beyond one page the counts are labelled partial until the last page and then match a single full pass', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop impact baseline');
  test.setTimeout(180000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchPreview(page);
  let id;
  try {
    id = await createTemplate(page, 'product', `TB-R2 impact-paged ${info.project.name}`);
    await page.getByTestId('blox-cond-add-include-all').click();

    const full = (await preview(page, log)).data.preview;
    expect(full.complete).toBe(true);
    expect(full.total).toBeGreaterThan(2);

    run('setting', 0, 'blox_detail_publish_page_rows', '2');
    let res = await preview(page, log);
    expect(res.data.preview.complete).toBe(false);
    await expect(page.getByTestId('blox-impact-result')).toHaveAttribute('data-status', 'partial');
    await expect(page.getByTestId('blox-impact-summary')).toContainText(`2 / ${full.total}`);
    await expect(page.getByTestId('blox-impact-count-won')).toContainText('已检查部分中');

    for (let i = 0; i < 50; i += 1) {
      res = await preview(page, log, 'blox-impact-more');
      if (res.data.preview.complete) break;
    }
    await expect(page.getByTestId('blox-impact-result')).toHaveAttribute('data-status', 'complete');
    const merged = await page.evaluate(() => JSON.parse(JSON.stringify(window.Alpine.$data(document.body).impactPreview)));
    expect(merged.scanned).toBe(full.total);
    expect(merged.counts).toEqual(full.counts);
    await expect(page.getByTestId('blox-impact-count-won')).toHaveText(`共 ${full.counts.won} 条`);
    await expect(page.getByTestId('blox-impact-more')).toBeHidden();
  } finally {
    run('setting', 0, 'blox_detail_publish_page_rows', '');
    if (id) run('restore', id);
  }
});

test('non-default language links, permission refusal, stop and failure states', async ({ page, browser }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'desktop impact baseline');
  test.setTimeout(150000);
  page.setDefaultTimeout(15000);
  page.on('dialog', (dialog) => dialog.accept());
  const log = watchPreview(page);
  let id;
  const account = JSON.parse(run('limited-user'));
  const limited = await browser.newContext({ storageState: { cookies: [], origins: [] } });
  try {
    id = await createTemplate(page, 'product', `TB-R2 impact-en ${info.project.name}`, 'en');
    await page.getByTestId('blox-cond-add-include-all').click();
    const res = await preview(page, log);
    const sample = res.data.preview.samples.won[0];
    expect(sample.lang).toBe('en');
    expect(sample.url, '英文内容带英文前缀（或动态模式的 lang 参数）').toMatch(/^\/en\/|[?&]lang=en(&|$)/);
    const front = await page.request.get(sample.url);
    expect(front.status()).toBe(200);
    expect(await front.text()).toContain(sample.title);

    // 停止：保留已有部分并标明
    let release = () => {};
    const gate = new Promise((resolve) => { release = resolve; });
    await page.route('**/admin/blox_template_api.php', async (route) => {
      if (new URLSearchParams(route.request().postData() || '').get('action') === 'preview_impact') await gate;
      await route.continue();
    });
    await page.getByTestId('blox-impact-run').click();
    await expect(page.getByTestId('blox-impact-cancel')).toBeVisible();
    await page.getByTestId('blox-impact-cancel').click();
    release();
    await expect(page.getByTestId('blox-impact-result')).toHaveAttribute('data-status', 'cancelled');
    await expect(page.getByTestId('blox-impact-cancelled')).toBeVisible();
    await page.unroute('**/admin/blox_template_api.php');

    // 请求失败：显示失败，不影响保存
    await page.route('**/admin/blox_template_api.php', async (route) => {
      if (new URLSearchParams(route.request().postData() || '').get('action') === 'preview_impact') {
        await route.abort('aborted');
        return;
      }
      await route.continue();
    });
    await page.getByTestId('blox-impact-run').click();
    await expect(page.getByTestId('blox-impact-error')).toHaveText('影响范围预览失败，请重试');
    await page.unroute('**/admin/blox_template_api.php');
    const saved = page.waitForResponse((r) => new URL(r.url()).pathname === '/admin/blox_template_api.php'
      && new URLSearchParams(r.request().postData() || '').get('action') === 'save_draft');
    await page.getByTestId('blox-save').click();
    expect((await (await saved).json()).code).toBe(0);

    // 没有产品编辑权限：与后台产品列表一致，不给预览
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
    await other.getByTestId('blox-impact-run').click();
    await expect(other.getByTestId('blox-impact-error')).toHaveText('你没有查看这类内容的权限，无法预览影响范围');
    await expect(other.getByTestId('blox-impact-result')).toHaveCount(0);
  } finally {
    await limited.close();
    run('limited-user', 'remove');
    if (id) run('restore', id);
  }
});
