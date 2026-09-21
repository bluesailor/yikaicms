const { test, expect } = require('@playwright/test');

test.describe('SEO workshop @ci', () => {
  let scriptErrors;

  test.beforeEach(async ({ page }) => {
    await page.goto('/admin/plugin.php');
    const activate = page.locator('#plugin-seo button[onclick*="pluginAction(\'activate\'"]');
    if (await activate.isVisible()) {
      // 插件页加载时还会 POST 拉市场列表（action=market_list，CI 无外网时返回 code 1），只认本次启用请求。
      // 启用走 FormData（multipart），也兼容 urlencoded。
      const activated = page.waitForResponse((response) => response.request().method() === 'POST'
        && new URL(response.url()).pathname === '/admin/plugin.php'
        && /(?:^|&)action=activate(?:&|$)|name="action"\r?\n\r?\nactivate\r?\n/.test(response.request().postData() || ''));
      await activate.click();
      expect((await (await activated).json()).code).toBe(0);
    }
    scriptErrors = [];
    page.on('pageerror', (error) => scriptErrors.push(error.message));
    page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/admin/plugin_page.php?plugin=seo');
  });

  test.afterEach(() => {
    expect(scriptErrors).toEqual([]);
  });

  test('generation controls and URL count initialize', async ({ page }, testInfo) => {
    const generate = page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true });
    await expect(generate).toBeVisible();
    await expect(generate).toBeEnabled();
    await expect(page.locator('strong[x-text="urlCount"]')).toHaveText(/^\d+$/);
    expect(Number(await page.locator('strong[x-text="urlCount"]').textContent())).toBeGreaterThan(0);
    await expect(page.getByRole('button', { name: /生成密钥/ })).toBeVisible();
    expect(await page.evaluate(() => typeof window.seoAutopush)).toBe('function');
    await page.screenshot({ path: testInfo.outputPath('seo-workshop.png') });
  });

  test('generate writes a readable file and persists after reopening', async ({ page, request }) => {
    const generated = page.waitForResponse((response) => response.request().method() === 'POST'
      && response.request().postData()?.includes('action=gen_llms'));
    await page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true }).click();
    expect((await (await generated).json()).code).toBe(0);
    await expect(page.getByRole('link', { name: '查看 /llms.txt', exact: true })).toBeVisible();
    const file = await request.get('/llms.txt');
    expect(file.ok()).toBe(true);
    expect(await file.text()).toMatch(/^# .+/m);
    await page.reload();
    await expect(page.getByRole('link', { name: '查看 /llms.txt', exact: true })).toBeVisible();
    await expect(page.locator('[x-show="genAt"]')).toContainText(/上次生成：\d{4}-\d{2}-\d{2}/);
    await expect(page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true })).toBeEnabled();
  });

  test('generation failure shows feedback and restores the button', async ({ page }) => {
    await page.route('**/admin/plugin_page.php?plugin=seo', async (route) => {
      if (route.request().method() === 'POST') {
        expect(new URLSearchParams(route.request().postData()).get('action')).toBe('gen_llms');
        await route.fulfill({ status: 200, json: { code: 1, msg: 'Fixture: generation refused' } });
      } else {
        await route.continue();
      }
    });
    const feedback = page.waitForEvent('dialog');
    await page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true }).click();
    expect((await feedback).message()).toBe('Fixture: generation refused');
    await expect(page.getByRole('button', { name: '生成 / 更新 llms.txt', exact: true })).toBeEnabled();
  });

  // URL 别名管理（免费档）：卡片渲染 + 列表往返。改名的净化/唯一性规则由
  // SeoSlugManagerTest 锁，这里只保证 Alpine 绑定与端点接线没断（afterEach 断言无 JS 错误）。
  test('slug manager renders and reloads the list from the server', async ({ page }) => {
    const card = page.locator('#seo-slugs');
    await expect(card).toBeVisible();
    await expect(card.getByRole('heading', { name: 'URL 别名管理' })).toBeVisible();
    // 计数文案由 x-text 组装，占位符必须已被替换
    const counts = card.locator('[x-text*="slugTotal"]');
    await expect(counts).toHaveText(/共 \d+ 条别名，其中 \d+ 条异常/);

    // 切换「只看异常」触发 slug_list 往返
    const reloaded = page.waitForResponse((response) => response.request().method() === 'POST'
      && new URL(response.url()).pathname === '/admin/plugin_page.php'
      && new URLSearchParams(response.request().postData() || '').get('action') === 'slug_list');
    await card.getByText('只看异常', { exact: true }).click();
    const payload = await (await reloaded).json();
    expect(payload.code).toBe(0);
    expect(Array.isArray(payload.data.rows)).toBe(true);
    expect(payload.data.total).toBeGreaterThan(0);
  });
});
