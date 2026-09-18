/**
 * 表单模板的后台操作走真实 HTTP：验证码开关、翻译版保存、删除与「默认表单不可删」。
 * 2026-09-17 这三条路径都调用了不存在的 FormTemplateModel::findById()，点击即 500。
 */
const { test, expect } = require('@playwright/test');
const { observeConsole } = require('./helpers');

/** 在后台页面里发 POST，沿用页面自带的 CSRF 注入。 */
const postForm = (page, url, fields) => page.evaluate(async ({ url, fields }) => {
    const body = new FormData();
    Object.entries(fields).forEach(([key, value]) => body.append(key, String(value)));
    const response = await fetch(url, { method: 'POST', body, credentials: 'same-origin' });
    return { status: response.status, json: await response.json().catch(() => null) };
}, { url, fields });

test('spam page captcha switch saves and restores without a server error @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'server-side action, one viewport is enough');
    const consoleEntries = observeConsole(page);
    await page.goto('/admin/form_spam.php', { waitUntil: 'domcontentloaded' });
    const toggle = page.getByTestId('form-spam-captcha').getByRole('switch').first();
    await expect(toggle).toBeVisible();
    const before = await toggle.getAttribute('aria-checked');
    const after = before === 'true' ? 'false' : 'true';

    for (const expected of [after, before]) {
        const response = page.waitForResponse((r) => r.url().includes('/admin/form_spam.php') && r.request().method() === 'POST');
        await toggle.click();
        const result = await response;
        expect(result.status()).toBe(200);
        expect((await result.json()).code).toBe(0);
        await expect(toggle).toHaveAttribute('aria-checked', expected);
    }
    expect(consoleEntries).toEqual([]);
});

test('form design saves a translation, deletes a template and refuses to delete the default form @ci', async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'server-side action, one viewport is enough');
    await page.goto('/admin/form_design.php', { waitUntil: 'domcontentloaded' });
    const url = '/admin/form_design.php';
    const slug = 'e2e-delete-' + Date.now();

    const created = await postForm(page, url, {
        action: 'save', id: 0, lang: 'zh-CN', name: '待删除表单', slug,
        template_text: '[text* name "姓名"]', success_message: '',
    });
    expect(created.status).toBe(200);
    expect(created.json && created.json.code, JSON.stringify(created.json)).toBe(0);
    const id = Number(created.json.data.id);
    expect(id).toBeGreaterThan(0);

    try {
        // 翻译版保存先读源记录（原 findById 路径）；站点未启用英文时服务端回落为默认语言保存，同样不能 500
        const translated = await postForm(page, url, {
            action: 'save', id, lang: 'en', name: 'Form to delete',
            template_text: '[text* name "Name"]', success_message: '',
        });
        expect(translated.status).toBe(200);
        expect(translated.json && translated.json.code, JSON.stringify(translated.json)).toBe(0);
    } finally {
        const deleted = await postForm(page, url, { action: 'delete', id });
        expect(deleted.status).toBe(200);
        expect(deleted.json && deleted.json.code, JSON.stringify(deleted.json)).toBe(0);
    }

    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('code', { hasText: `[form-${slug}]` })).toHaveCount(0);

    // 默认 contact 表单不可删：列表里没有删除按钮，直接 POST 也要被拒绝
    const contactId = await page.evaluate(() => {
        const templates = Array.from(document.querySelectorAll('code')).find((node) => node.textContent.trim() === '[form-contact]');
        const row = templates && templates.closest('tr');
        const match = row && row.innerHTML.match(/toggleStatus\((\d+)/);
        return match ? Number(match[1]) : 0;
    });
    expect(contactId, 'contact form row exposes its id').toBeGreaterThan(0);
    const refused = await postForm(page, url, { action: 'delete', id: contactId });
    expect(refused.status).toBeLessThan(500);
    expect(refused.json && refused.json.code).not.toBe(0);
    await page.reload({ waitUntil: 'domcontentloaded' });
    await expect(page.locator('code', { hasText: '[form-contact]' })).toHaveCount(1);
});
