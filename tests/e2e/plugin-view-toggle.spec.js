/**
 * 插件管理页的「网格 / 列表」视图切换：已安装与插件市场两个页签各自记忆，默认保持改版前的样子
 * （已安装=列表，市场=网格），刷新后沿用上次选择，窄屏不横向溢出。
 */
const { test, expect } = require('@playwright/test');
const { observeConsole, observeUnsafeWrites } = require('./helpers');

const TINY_PNG = 'data:image/png;base64,iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNkYAAAAAMAASsJTYQAAAAASUVORK5CYII=';
const MARKET = [1, 2, 3, 4].map((i) => ({
    slug: 'view-demo-' + i, name: '视图演示插件 ' + i, description: '用于验证网格与列表视图',
    version: '1.0.' + i, author: 'Yikai CMS', category: 'tools', size_kb: 12 + i,
    tier: 'free', paid: false, entitled: false, thumbnail: TINY_PNG,
}));

async function mockMarket(page) {
    await page.route('**/admin/plugin.php**', async (route) => {
        const request = route.request();
        const body = new URLSearchParams(request.postData() || '');
        if (request.method() !== 'POST' || body.get('action') !== 'market_list') return route.continue();
        await route.fulfill({
            status: 200, contentType: 'application/json; charset=utf-8',
            body: JSON.stringify({ code: 0, data: { plugins: MARKET } }),
        });
    });
}

/** 每个卡片左上角坐标；同一行的卡片 top 相同，单列时 left 全相同。 */
const boxes = (locator) => locator.evaluateAll((els) => els
    .filter((el) => el.offsetParent !== null)
    .map((el) => { const r = el.getBoundingClientRect(); return { top: Math.round(r.top), left: Math.round(r.left), right: Math.round(r.right) }; }));

test('installed and market tabs switch between grid and list, remember the choice per tab @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'desktop layout baseline');
    const consoleEntries = observeConsole(page);
    const unsafeWrites = observeUnsafeWrites(page);
    await mockMarket(page);
    await page.goto('/admin/plugin.php', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => localStorage.removeItem('yikai:admin:plugin-views:v1'));
    await page.reload({ waitUntil: 'domcontentloaded' });

    const installed = page.getByTestId('plugin-installed-list');
    const installedItems = installed.getByTestId('plugin-installed-item');
    expect(await installedItems.count()).toBeGreaterThanOrEqual(2);

    // 默认：已安装是列表——每张卡占满一行，左边对齐、上下堆叠
    await expect(installed).toHaveAttribute('data-view', 'list');
    await expect(page.getByTestId('plugin-view-list')).toHaveAttribute('aria-pressed', 'true');
    let rows = await boxes(installedItems);
    expect(new Set(rows.map((b) => b.left)).size).toBe(1);
    expect(new Set(rows.map((b) => b.top)).size).toBe(rows.length);

    // 切到网格：同一行出现多张卡
    await page.getByTestId('plugin-view-grid').click();
    await expect(installed).toHaveAttribute('data-view', 'grid');
    await expect(page.getByTestId('plugin-view-grid')).toHaveAttribute('aria-pressed', 'true');
    rows = await boxes(installedItems);
    expect(rows.filter((b) => b.top === rows[0].top).length).toBeGreaterThanOrEqual(2);
    // 网格卡片里的操作按钮不越出卡片
    const overflowButtons = await installedItems.evaluateAll((cards) => cards.filter((card) => {
        const c = card.getBoundingClientRect();
        return Array.from(card.querySelectorAll('button, a')).some((btn) => {
            if (btn.offsetParent === null) return false;
            const b = btn.getBoundingClientRect();
            return b.right > c.right + 1 || b.left < c.left - 1;
        });
    }).length);
    expect(overflowButtons).toBe(0);

    // 市场：默认网格，且与已安装的选择互不影响
    await page.getByRole('button', { name: /插件市场/ }).click();
    const market = page.getByTestId('plugin-market-list');
    await expect(market.locator('[data-plugin-slug]')).toHaveCount(4);
    await expect(market).toHaveAttribute('data-view', 'grid');
    await expect(market.locator('img')).toHaveCount(4);
    let cards = await boxes(market.locator('[data-plugin-slug]'));
    expect(cards.filter((b) => b.top === cards[0].top).length).toBeGreaterThanOrEqual(2);

    await page.getByTestId('plugin-view-list').click();
    await expect(market).toHaveAttribute('data-view', 'list');
    cards = await boxes(market.locator('[data-plugin-slug]'));
    expect(new Set(cards.map((b) => b.left)).size).toBe(1);
    expect(new Set(cards.map((b) => b.top)).size).toBe(4);
    // 列表视图收起 16:9 大图，保持紧凑
    await expect(market.locator('img')).toHaveCount(0);

    // 刷新：各页签沿用各自上次的选择（已安装=网格，市场=列表）
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.getByTestId('plugin-installed-list')).toHaveAttribute('data-view', 'grid');
    await page.getByRole('button', { name: /插件市场/ }).click();
    await expect(page.getByTestId('plugin-market-list')).toHaveAttribute('data-view', 'list');

    expect(unsafeWrites, 'switching views must not install or activate anything').toEqual([]);
    expect(consoleEntries, 'plugin page console stays clean').toEqual([]);
});

test('both views fit a 390px phone without horizontal overflow @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'single run with explicit phone viewport');
    await mockMarket(page);
    await page.setViewportSize({ width: 390, height: 900 });
    await page.goto('/admin/plugin.php', { waitUntil: 'domcontentloaded' });
    await page.evaluate(() => localStorage.removeItem('yikai:admin:plugin-views:v1'));
    await page.reload({ waitUntil: 'domcontentloaded' });

    const overflow = () => page.evaluate(() => document.documentElement.scrollWidth - window.innerWidth);
    for (const view of ['list', 'grid']) {
        await page.getByTestId('plugin-view-' + view).click();
        await expect(page.getByTestId('plugin-installed-list')).toHaveAttribute('data-view', view);
        expect(await overflow(), 'installed ' + view + ' overflows at 390').toBeLessThanOrEqual(1);
    }
    await page.getByRole('button', { name: /插件市场/ }).click();
    await expect(page.getByTestId('plugin-market-list').locator('[data-plugin-slug]')).toHaveCount(4);
    for (const view of ['list', 'grid']) {
        await page.getByTestId('plugin-view-' + view).click();
        await expect(page.getByTestId('plugin-market-list')).toHaveAttribute('data-view', view);
        expect(await overflow(), 'market ' + view + ' overflows at 390').toBeLessThanOrEqual(1);
    }
});
