/**
 * 预置区块弹窗的「基础区块 / 精品区块」两个入口与精品权益说明（区块库任务书 §7）。
 *
 * 真实精品目录来自远程市场，编辑器所在站点连不到；这里在浏览器层替换目录接口的响应，
 * 只验证界面对各权益状态的呈现。服务端闸口与导入拒绝在更新服务侧 premium-sections-http.php
 * + PremiumSectionsLiveImportTest 里直接对接口验证，不靠界面。
 */
const { test, expect } = require('@playwright/test');
const fs = require('fs');
const path = require('path');
const { openPageEditor, countSections } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));
const SLUGS = ['hero-intro', 'hero-split', 'image-text-reverse', 'stats-band', 'trust-grid', 'feature-cards-soft',
    'card-grid', 'product-comparison', 'testimonial-grid', 'faq-accordion', 'faq-split', 'contact-strip'];

const premium = (slug, reason) => ({
    key: 'remote:' + slug, type: 'section', name: 'Premium ' + slug, description: '', source: 'remote',
    provider: 'official', category: 'features', access: 'licensed', paid: true, module: 'blox',
    entitled: reason === '', locked: reason !== '', locked_reason: reason, thumbnail: '',
    metadata: { purpose: 'features', page_types: ['general'], priority: 80 },
});

/** 用真实本地目录 + 替换掉的远程部分，拼出目录响应。 */
async function mockCatalog(page, build) {
    const calls = [];
    await page.route('**/admin/blox_template_api.php*', async (route) => {
        const url = new URL(route.request().url());
        if (route.request().method() !== 'GET' || url.searchParams.get('action') !== 'list') return route.continue();
        calls.push(url.search);
        const real = await route.fetch();
        const body = await real.json();
        const { remote, remoteError } = build(calls.length);
        body.data.items = body.data.items.filter((item) => item.source !== 'remote').concat(remote);
        body.data.remote_error = remoteError || '';
        await route.fulfill({ response: real, json: body });
    });
    return calls;
}

async function openPremium(page) {
    await page.getByTestId('blox-prebuilt-open').click();
    const tabs = [page.getByTestId('blox-template-tab-local'), page.getByTestId('blox-template-tab-remote')];
    await expect(tabs[0]).toContainText('基础区块');
    await expect(tabs[1]).toContainText('精品区块');
    await tabs[1].click();
    await expect(tabs[1]).toHaveAttribute('aria-selected', 'true');
}

const cards = (page) => page.locator('[data-testid="blox-template-item"][data-template-key^="remote:"]');

test('no licence: one purchase notice links to the official pro page, cards stay quiet @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'desktop library baseline');
    await openPageEditor(page, fixtures.blox_page);
    await mockCatalog(page, () => ({ remote: SLUGS.map((s) => premium(s, 'license_required')) }));
    await page.evaluate(() => { window.Alpine.$data(document.body).templateText.hasLicenseKey = false; });
    await openPremium(page);

    await expect(cards(page)).toHaveCount(12);
    const notice = page.getByTestId('blox-premium-notice');
    await expect(notice).toBeVisible();
    await expect(notice).toHaveAttribute('data-state', 'purchase');
    await expect(page.getByTestId('blox-premium-notice')).toHaveCount(1);
    const action = page.getByTestId('blox-premium-notice-action');
    await expect(action).toHaveAttribute('href', 'https://www.yikaicms.com/pro.php');
    await expect(action).toHaveAttribute('target', '_blank');
    await expect(action).toHaveAttribute('rel', /noopener/);

    // 不给每张卡叠锁：卡片上没有逐卡锁定文案、没有「精品」徽标；插入按钮禁用但仍可悬停看到原因
    const first = cards(page).first();
    await expect(first.locator('.ti-lock')).toHaveCount(0);
    await expect(first.locator('span.text-amber-700:visible')).toHaveCount(0);
    await expect(first.getByText('精品', { exact: true })).toBeHidden();
    await expect(first.getByTestId('blox-template-insert')).toBeDisabled();
    // 不重复引导：底部「授权管理」只在逐卡说明时出现
    await expect(page.getByRole('link', { name: '授权管理' })).toHaveCount(0);
});

test('licence states map to renew / activate / domain / disabled with the right single action @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'desktop library baseline');
    await openPageEditor(page, fixtures.blox_page);
    let reason = 'license_expired';
    await mockCatalog(page, () => ({ remote: SLUGS.map((s) => premium(s, reason)) }));
    const expectations = [
        ['license_expired', false, 'renew', 'https://www.yikaicms.com/pro.php'],
        ['license_required', true, 'activate', '/admin/license.php'],
        ['domain_mismatch', true, 'domain', '/admin/license.php'],
        ['disabled', true, 'disabled', '/admin/license.php'],
    ];
    for (const [nextReason, hasKey, state, href] of expectations) {
        reason = nextReason;
        await page.evaluate(([key]) => {
            const app = window.Alpine.$data(document.body);
            app.templateText.hasLicenseKey = key;
            app.templateOpen = false;
            return app.loadTemplates(true);
        }, [hasKey]);
        await openPremium(page);
        const notice = page.getByTestId('blox-premium-notice');
        await expect(notice).toHaveAttribute('data-state', state);
        await expect(page.getByTestId('blox-premium-notice-action')).toHaveAttribute('href', href);
        await page.keyboard.press('Escape');
    }
});

test('entitled users see no notice and can insert; a failed load offers retry without losing the canvas @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'desktop library baseline');
    await openPageEditor(page, fixtures.blox_page);
    const before = await countSections(page);
    let fail = true;
    const calls = await mockCatalog(page, () => (fail
        ? { remote: [], remoteError: '精品区块暂时无法加载' }
        : { remote: SLUGS.map((s) => premium(s, '')) }));
    await openPremium(page);

    // 网络/服务失败：提示并提供重试；画布不受影响
    const notice = page.getByTestId('blox-premium-notice');
    await expect(notice).toHaveAttribute('data-state', 'error');
    expect(await countSections(page)).toBe(before);
    fail = false;
    const requestsBefore = calls.length;
    await page.getByTestId('blox-premium-notice-retry').click();
    await expect.poll(() => calls.length).toBeGreaterThan(requestsBefore);
    expect(calls[calls.length - 1]).toContain('refresh=1');

    // 有权益：无任何提示、卡片可插入，没有「精品」徽标噪音
    await expect(page.getByTestId('blox-premium-notice')).toBeHidden();
    await expect(cards(page)).toHaveCount(12);
    await expect(cards(page).first().getByTestId('blox-template-insert')).toBeEnabled();
    await expect(cards(page).first().getByText('精品', { exact: true })).toBeHidden();
});

test('mixed reasons fall back to per-card labels and the licence management link @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'desktop library baseline');
    await openPageEditor(page, fixtures.blox_page);
    await mockCatalog(page, () => ({
        remote: SLUGS.map((s, i) => premium(s, i === 0 ? 'rate_limited' : '')),
    }));
    await openPremium(page);
    await expect(page.getByTestId('blox-premium-notice')).toBeHidden();
    const limited = page.locator('[data-testid="blox-template-item"][data-template-key="remote:hero-intro"]');
    await expect(limited.getByTestId('blox-template-insert')).toBeDisabled();
    await expect(page.getByRole('link', { name: '授权管理' })).toBeVisible();
});
