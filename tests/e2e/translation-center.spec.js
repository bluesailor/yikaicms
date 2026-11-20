const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const path = require('path');

// 翻译工作中心（2.3.0 CORE-02）：状态页扩到文章、单页、产品分类；每格给下一步；
// 已审与已发布分开显示；页面说的「已发布 / 草稿」要和访客实际能不能打开一致。
const root = path.resolve(__dirname, '../..');
const csrf = (page) => page.locator('meta[name="csrf-token"]').getAttribute('content');
async function saveLanguages(page, enabled) {
  await page.goto('/admin/setting_lang.php');
  const body = new URLSearchParams({ action: 'save_lang', default_lang: 'zh-CN', admin_lang: 'zh-CN', show_switcher: '0', _token: await csrf(page) });
  enabled.forEach((lang) => body.append('enabled[]', lang));
  const response = await page.request.post('/admin/setting_lang.php', { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: body.toString() });
  expect((await response.json()).code).toBe(0);
}

test('translation work center covers four content types and matches what visitors can open @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(120000);
  await saveLanguages(page, ['zh-CN', 'en']);
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });   // 没有后台登录态的访客
  try {
    const seeded = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'translation-center-fixture.php')], { cwd: root, encoding: 'utf8' }));
    // 新装站点：翻译相关的迁移不该显示为「待升级」
    const pending = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'translation-center-fixture.php'), 'pending'], { cwd: root, encoding: 'utf8' }));
    expect(pending.filter((id) => /translation/.test(id))).toEqual([]);
    const entry = { products: '/product.php?id=', contents: '/detail.php?id=', pages: '/page.php?id=' };
    const editor = { products: /product_edit\.php\?id=/, contents: /article_edit\.php\?id=/, pages: /page_edit\.php\?id=/, categories: /product_category\.php\?lang=en/ };

    for (const kind of ['products', 'contents', 'pages', 'categories']) {
      await page.goto(`/admin/translation_status.php?kind=${kind}&q=WC`);
      await expect(page.locator(`nav a[data-kind="${kind}"]`)).toHaveAttribute('aria-current', 'page');
      // 批量建任务只对产品开放
      await expect(page.locator('#tssMissing')).toHaveCount(kind === 'products' ? 1 : 0);

      for (const variant of ['live', 'draft']) {
        const item = seeded[kind][variant];
        const cell = page.locator(`tr[data-source="${item.source}"] td[data-lang="en"]`);
        await expect(cell).toHaveAttribute('data-target', String(item.target));
        // 两条译文都记为已审：审阅状态相同，发布状态不同
        await expect(cell).toContainText(/已审|Reviewed/);
        await expect(cell).toHaveAttribute('data-live', variant === 'live' ? '1' : '0');
        await expect(cell).toContainText(variant === 'live' ? /已发布|Published/ : /草稿|Draft/);
        await expect(cell).toHaveAttribute('data-next', variant === 'live' ? '' : 'publish');
        if (variant === 'draft') await expect(cell.getByRole('link', { name: /去发布|Publish/ })).toHaveAttribute('href', editor[kind]);

        // 访客实际拿到的：已发布能打开，草稿是 404
        if (entry[kind]) {
          const response = await visitor.request.get(entry[kind] + item.target);
          expect(response.status(), `${kind} ${variant} #${item.target}`).toBe(variant === 'live' ? 200 : 404);
        }
      }
    }

    // 产品分类没有独立详情页：用英文产品列表页带分类筛选的地址
    for (const variant of ['live', 'draft']) {
      const response = await visitor.request.get(`/list.php?id=${seeded.listChannel}&cat=${seeded.categories[variant].slug}`);
      const html = await response.text();
      // 停用的分类对访客不存在：地址按「没有这个分类」处理（列出全部产品），不出现分类名
      expect([variant, response.status(), html.includes(seeded.categories[variant].name)]).toEqual([variant, 200, variant === 'live']);
    }

    // 缺译文：下一步是去原文的编辑页翻译；「有缺失」筛选能筛出它
    await page.goto('/admin/translation_status.php?kind=contents&filter=missing&q=WC');
    const missing = page.locator(`tr[data-source="${seeded.contents.missing.source}"] td[data-lang="en"]`);
    await expect(missing).toHaveAttribute('data-next', 'translate');
    await expect(missing.getByRole('link', { name: /去翻译|Translate/ })).toHaveAttribute('href', new RegExp(`article_edit\\.php\\?id=${seeded.contents.missing.source}$`));
    await expect(page.locator(`tr[data-source="${seeded.contents.draft.source}"]`)).toHaveCount(0);
    // 「有未发布」筛出已审的草稿
    await page.goto('/admin/translation_status.php?kind=contents&filter=draft&q=WC');
    await expect(page.locator(`tr[data-source="${seeded.contents.draft.source}"]`)).toHaveCount(1);
    await expect(page.locator(`tr[data-source="${seeded.contents.live.source}"]`)).toHaveCount(0);
  } finally {
    await visitor.close();
    await saveLanguages(page, ['zh-CN']);
  }
});

// 所有者 2026-11-20：只有文章或单页权限的编辑也要有看得见的入口；批量翻译任务的链接只给 edit_product
test('restricted editors reach the work center and only product editors see batch tasks @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(120000);
  await saveLanguages(page, ['zh-CN', 'en']);
  const accounts = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'translation-center-fixture.php'), 'editors'], { cwd: root, encoding: 'utf8' }));
  const expected = {
    article: { kinds: ['contents'], batch: false },
    page: { kinds: ['pages'], batch: false },
    product: { kinds: ['products', 'categories'], batch: true },
  };
  try {
    for (const [name, want] of Object.entries(expected)) {
      const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
      const editor = await context.newPage();
      editor.setDefaultTimeout(10000);
      try {
        await editor.goto('/admin/login.php');
        await editor.locator('input[name="username"]').fill(accounts[name].username);
        await editor.locator('input[name="password"]').fill(accounts[name].password);
        await editor.locator('button[type="submit"]').click();
        await editor.waitForURL((url) => !url.pathname.endsWith('/admin/login.php'), { waitUntil: 'domcontentloaded', timeout: 15000 })
          .catch(async () => { throw new Error(`${name}: login did not complete: ` + (await editor.locator('body').innerText()).slice(0, 400)); });
        // 侧栏里有看得见的入口，点进去能打开
        await editor.goto('/admin/index.php');
        // 侧栏有折叠态的隐藏副本：点开展开态里所在的分组，取看得见的那个入口
        await expect(editor.locator('a[href$="/admin/translation_status.php"]').first(), `${name}: sidebar entry exists`).toBeAttached();
        await editor.getByRole('button', { name: name === 'product' ? /商品与展示/ : /文章与资讯/ }).locator('visible=true').first().click();
        const entry = editor.locator('a[href$="/admin/translation_status.php"]').locator('visible=true').first();
        // 入口在折叠的分组里：先展开各组（真实编辑也是点开分组再点）
        await expect(entry, `${name}: sidebar entry`).toBeVisible();
        await entry.click();
        await expect(editor).toHaveURL(/translation_status\.php/);
        await expect(editor.locator('h1').last()).toContainText(/翻译状态|Translation status/);
        // 只列自己能编辑的种类
        await expect(editor.locator('nav a[data-kind]')).toHaveCount(want.kinds.length);
        for (const kind of want.kinds) await expect(editor.locator(`nav a[data-kind="${kind}"]`)).toBeVisible();
        await expect(editor.locator(`nav a[data-kind="${want.kinds[0]}"]`)).toHaveAttribute('aria-current', 'page');
        // 批量翻译任务：链接与建批按钮只给产品编辑
        await expect(editor.getByTestId('tss-batch-tasks')).toHaveCount(want.batch ? 1 : 0);
        await expect(editor.locator('#tssMissing')).toHaveCount(want.batch ? 1 : 0);
        // 地址里硬写别人的种类：落回自己能编辑的第一种，不泄露别的种类
        await editor.goto(`/admin/translation_status.php?kind=${name === 'product' ? 'contents' : 'products'}`);
        await expect(editor.locator(`nav a[data-kind="${want.kinds[0]}"]`)).toHaveAttribute('aria-current', 'page');
        await expect(editor.locator('#tssMissing')).toHaveCount(want.batch ? 1 : 0);
        // 产品导入只给超级管理员：产品编辑也看不到入口、打不开页面
        await editor.goto('/admin/product.php');
        await expect(editor.getByTestId('product-import-entry')).toHaveCount(0);
        await editor.goto('/admin/product_import.php');
        await expect(editor.locator('[data-pimp-wizard]')).toHaveCount(0);
        // 任务页本身仍要 edit_product：没有权限的得到「无权限」页（不是任务页）
        await editor.goto('/admin/translation_tasks.php');
        const taskHeading = editor.locator('h1').filter({ hasText: /批量翻译任务|Batch translation tasks/ });
        if (want.batch) await expect(taskHeading.last()).toBeVisible();
        else await expect(taskHeading).toHaveCount(0);
      } finally {
        await context.close();
      }
    }
  } finally {
    await saveLanguages(page, ['zh-CN']);
  }
});

// CORE-02 第二部分：批量发布已审的草稿译文。整条流程：机器译文草稿（发不了）→ 编辑标为已审 → 批量发布 → 访客能打开
test('reviewed draft translations are published in bulk and become visible to visitors @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(120000);
  await saveLanguages(page, ['zh-CN', 'en']);
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const status = async (url) => (await visitor.request.get(url)).status();
  const publish = async () => {
    const button = page.locator('#tssPublish');
    await button.click();   // 第一次点击只换成确认语
    await expect(button).toContainText(/再点一次确认|Click again/);
    const response = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname.endsWith('/admin/translation_status.php'));
    await button.click();
    return (await response).json();
  };
  try {
    const seeded = JSON.parse(execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'translation-center-fixture.php')], { cwd: root, encoding: 'utf8' }));
    const entry = { products: '/product.php?id=', contents: '/detail.php?id=', pages: '/page.php?id=' };

    // 文章、单页：筛「已审待发布」→ 全选 → 发布；已发布的那条不受影响
    for (const kind of ['contents', 'pages']) {
      const draft = seeded[kind].draft;
      expect(await status(entry[kind] + draft.target)).toBe(404);
      await page.goto(`/admin/translation_status.php?kind=${kind}&filter=ready&q=WC`);
      const cell = page.locator(`tr[data-source="${draft.source}"] td[data-lang="en"]`);
      await expect(cell).toHaveAttribute('data-ready', '1');
      await expect(page.locator(`tr[data-source="${seeded[kind].live.source}"]`)).toHaveCount(0);
      await page.locator(`tr[data-source="${draft.source}"] input[type="checkbox"]`).check();
      const reply = await publish();
      expect([reply.code, reply.data.published.map(Number)]).toEqual([0, [draft.target]]);
      await page.goto(`/admin/translation_status.php?kind=${kind}&q=WC`);
      await expect(cell).toHaveAttribute('data-live', '1');
      await expect(cell).toContainText(/已审|Reviewed/);          // 发布不改审阅状态
      await expect(cell).toHaveAttribute('data-next', '');
      expect(await status(entry[kind] + draft.target), `${kind}: visitor after publish`).toBe(200);
    }

    // 产品：机器译文草稿没人审过，勾上也发不了
    const machine = seeded.products.machine;
    await page.goto('/admin/translation_status.php?kind=products&q=WC');
    const machineCell = page.locator(`tr[data-source="${machine.source}"] td[data-lang="en"]`);
    await expect(machineCell).toHaveAttribute('data-ready', '0');
    await page.locator(`tr[data-source="${machine.source}"] input[type="checkbox"]`).check();
    await page.locator('#tssPublish').click();
    await expect(page.locator('#tssPublish')).not.toContainText(/再点一次确认|Click again/);   // 没有可发布的：不进入确认
    // 绕过页面直接提交：服务端也拒绝
    const forced = await page.request.post('/admin/translation_status.php', { multipart: { action: 'publish', kind: 'products', 'ids[]': String(machine.target), _token: await csrf(page) } });
    expect((await forced.json()).data.skipped[machine.target]).toBe('not_reviewed');
    expect(await status(entry.products + machine.target)).toBe(404);

    // 编辑进译文的编辑页标为已审
    await machineCell.getByRole('link', { name: /去发布|Publish/ }).click();
    await expect(page).toHaveURL(new RegExp(`product_edit\\.php\\?id=${machine.target}`));
    await page.locator('#lseMarkReviewed').click();
    await expect(page.locator('[data-translation-state="en"]')).toContainText(/已审|Reviewed/);

    // 回到工作中心：现在可发布；和另一条已审草稿一起发
    await page.goto('/admin/translation_status.php?kind=products&filter=ready&q=WC');
    await expect(machineCell).toHaveAttribute('data-ready', '1');
    await page.locator('#tssTable thead input[type="checkbox"]').check();
    const reply = await publish();
    expect(reply.code).toBe(0);
    // 全选还会带上同一轮里别的测试留下的已审草稿：只要求这两条在里面
    expect(reply.data.published.map(Number)).toEqual(expect.arrayContaining([machine.target, seeded.products.draft.target]));
    expect(await status(entry.products + machine.target)).toBe(200);
    expect(await status(entry.products + seeded.products.draft.target)).toBe(200);
    await page.goto('/admin/translation_status.php?kind=products&filter=ready&q=WC');
    await expect(page.locator('#tssTable tbody tr')).toHaveCount(0);
  } finally {
    await visitor.close();
    await saveLanguages(page, ['zh-CN']);
  }
});
