const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const php = process.env.PHP_BINARY || 'php';
const fixture = (...args) => execFileSync(php, [path.join(__dirname, 'wp-import-fixture.php'), ...args], { cwd: root, encoding: 'utf8' });
const importer = (...args) => execFileSync(php, [path.join(root, 'tools/wp-import.php'), `--dsn=sqlite:${path.join(root, 'storage/e2e-wordpress.sqlite')}`, ...args], { cwd: root, encoding: 'utf8' });

// WordPress 迁移：从 WordPress 数据库导入后，原站网址原样可访问，内容、SEO、翻译、产品参数都在；再导一次是更新不是重复。
test('WordPress import keeps every original URL with content, SEO and translations @ci', async ({ browser, baseURL }) => {
  test.setTimeout(120000);
  const ids = JSON.parse(fixture('setup'));
  const visitor = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  const page = await visitor.newPage();
  try {
    const dry = importer('--dry-run');
    expect(dry).toContain('试运行');
    expect((await visitor.request.get('/slewing-bearing-installation-procedure/', { maxRedirects: 0 })).status()).toBe(404);

    const first = importer();
    expect(first).toContain('新建：');
    expect(first).toContain('表单：Request a Quote → 表单模板「request-a-quote」，含翻译 ja/de');
    expect(first).toContain('菜单：Main Menu（en');
    expect(first).toContain('原站位置 primary');
    expect(first).toContain('{"contact-form-7":1}');   // 导入的表单换成本站短代码；只有没导入的 id=5 算「去掉」
    expect(first).not.toContain('没认出的短代码');

    // Contact Form 7 → 表单模板（英文为主，日文进列、德文进 metas）；菜单 → 菜单组（日文菜单挂为语言版本）
    const imported = JSON.parse(fixture('inspect', JSON.stringify(ids)));
    expect(imported.form.slug).toBe('request-a-quote');
    expect(imported.form.fields).toContain('[email* your-email]');
    expect(imported.form.fields).toContain('[checkbox* privacy "I agree to the privacy policy"]');
    expect(imported.form.fields).not.toContain('quiz');
    expect(imported.form.fields_ja).toContain('お名前');
    expect(imported.form.fields_de).toContain('Firma');
    expect(imported.form.success_de).toBe('Vielen Dank für Ihre Anfrage.');
    const menu = JSON.stringify(imported.menu.items);
    expect(imported.menu.name).toBe('Main Menu');
    expect(imported.menu.items).toHaveLength(3);
    for (const piece of ['Our Team', '/product-category/slewing-drive/worm-gear-slew-drive-cat/', '/product/worm-gear-slew-drive/', 'SE7 manual', '/contact/']) expect(menu).toContain(piece);
    expect(menu).not.toContain('slewing-bearing.com');
    expect(imported.menu.ja).toBeGreaterThan(0);

    // ACF → 扩展字段：类型按表映射，布局字段静默跳过，地图字段跳过并提示；值转换（附件 id → 网址、Ymd → 日期、关联 → 新 id）
    expect(first).toContain('ACF 字段组：Product specs');
    expect(first).toContain('google_map');
    const acf = imported.acf;
    expect(acf.fields['product:spec_table'].type).toBe('repeater');
    expect(acf.fields['product:spec_table'].config.sub_fields.map(s => s.key)).toEqual(['model', 'load', 'drawing']);
    expect(acf.fields['product:spec_table'].config.button_label).toBe('Add model');
    expect(acf.fields['product:finish'].type).toBe('multi_select');
    expect(acf.fields['product:related_posts'].config.target).toBe('content');
    expect(acf.fields['product:tab_more']).toBeUndefined();
    expect(acf.fields['product:factory_map']).toBeUndefined();
    expect(acf.fields['product:torque_curve'].config.location).toEqual([acf.category_id]);
    expect(acf.fields['product_category:banner_text'].type).toBe('text');
    expect(acf.fields['site:certificates'].type).toBe('repeater');
    const rows = JSON.parse(acf.product.spec_table);
    expect(rows).toEqual([{ model: 'SE7A', load: '35', drawing: '/wp-content/uploads/2023/05/ring.png' }, { model: 'SE7B', load: '42', drawing: '' }]);
    expect(acf.product.datasheet).toBe('/wp-content/uploads/2023/05/gear.jpg');
    expect(JSON.parse(acf.product.buy_link)).toEqual({ url: 'https://shop.example.com/se7', title: 'Buy SE7', target: '_blank' });
    expect(acf.product.certified).toBe('1');
    expect(acf.product.mount).toBe('vertical');
    expect(acf.product.finish).toBe('galvanized,painted');
    expect(acf.product.related_posts).toBe(String(acf.post));
    expect(acf.product.extra_photos).toBe('/wp-content/uploads/2023/05/gear.jpg,/wp-content/uploads/2023/05/ring.png');
    expect(acf.product.release_date).toBe('2023-05-10');
    expect(acf.category.banner_text).toBe('Worm drives for solar trackers');
    expect(JSON.parse(acf.site.certificates)).toEqual([{ name: 'ISO 9001', image: '/wp-content/uploads/2023/05/ring.png' }]);

    // 旧站成批地址：?p=、?s=、feed、作者页、日期归档
    const location = async url => {
      const response = await visitor.request.get(url, { maxRedirects: 0 });
      expect(response.status(), url).toBe(301);
      return response.headers().location;
    };
    expect(await location(`/?p=${ids.post_install}`)).toMatch(/\/slewing-bearing-installation-procedure\/$/);
    expect(await location(`/?page_id=${ids.page_team}`)).toMatch(/\/about\/engineer-team\/$/);
    expect(await location('/?s=slew')).toMatch(/search.*keyword=slew|keyword=slew/);
    expect(await location('/feed/')).toMatch(/^(https?:\/\/[^/]+)?\/$/);
    expect(await location('/slewing-bearing-installation-procedure/feed/')).toMatch(/\/slewing-bearing-installation-procedure\/$/);
    expect(await location('/author/admin/')).toMatch(/^(https?:\/\/[^/]+)?\/$/);
    expect(await location('/2023/05/')).toMatch(/^(https?:\/\/[^/]+)?\/$/);

    // 联系页：表单渲染出来，宽表格包了横向滚动层
    expect((await page.goto('/contact/')).status()).toBe(200);
    await expect(page.locator('form [name="your-email"]').first()).toBeAttached();
    await expect(page.locator('.yk-table-scroll > table').first()).toBeAttached();

    expect((await page.goto('/slewing-bearing-installation-procedure/')).status()).toBe(200);
    await expect(page).toHaveTitle(/^Installing a Slewing Bearing/);
    await expect(page.locator('meta[name=description]')).toHaveAttribute('content', 'Step-by-step slewing bearing installation.');
    await expect(page.locator('img[src="/wp-content/uploads/2023/05/gear.jpg"]').first()).toBeAttached();
    await expect(page.locator('a[href="/product/worm-gear-slew-drive/"]').first()).toBeAttached();
    await expect(page.locator('link[rel=alternate][hreflang=ja]')).toHaveAttribute('href', /\/ja\/%E6%97%8B/i);

    for (const url of ['/about/', '/about/engineer-team/', '/category/resource/technical-information/', '/tag/worm-gear/',
      '/product-category/slewing-drive/worm-gear-slew-drive-cat/', '/product-tag/heavy-duty/', '/how-does-a-slewing-bearing-work%EF%BC%9F/']) {
      expect((await visitor.request.get(url, { maxRedirects: 0 })).status(), url).toBe(200);
    }
    await page.goto('/about/engineer-team/');
    await expect(page.getByText('Twenty engineers.').first()).toBeVisible();

    expect((await page.goto('/product/worm-gear-slew-drive/')).status()).toBe(200);
    await expect(page).toHaveTitle(/^SE7 Worm Gear Slew Drive/);
    expect(await page.content()).toContain('73:1');   // 参数在默认收起的选项卡里

    expect((await visitor.request.get('/draft-post/', { maxRedirects: 0 })).status()).toBe(404);
    const again = importer();
    expect(again).toContain('更新：');
    expect(again).not.toContain('新建：');
  } finally {
    fixture('restore');
    await visitor.close().catch(() => {});
  }
});
