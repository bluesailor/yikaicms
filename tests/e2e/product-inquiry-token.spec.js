const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });

test('real product inquiry submits signed localized forms and rejects missing tokens @ci', async ({ page, context }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Shared product submission flow');
  test.setTimeout(90000);
  fixture('contact-form-fixture.php', 'setup');
  try {
    fixture('form-spam-fixture.php', 'setup');
    await context.clearCookies();
    const marker = `E7 Form ${Date.now()}`;
    const state = () => JSON.parse(fixture('form-spam-fixture.php', 'result', marker));
    for (const [index, lang] of ['zh-CN', 'en', 'ja'].entries()) {
      const routes = JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', lang));
      await page.goto(routes.find(route => route.kind === 'product-detail').url);
      const form = page.locator('#inquiryForm');
      await form.locator('[name="name"]').fill(marker);
      await form.locator('[name="phone"]').fill('+86 13800000000');
      await form.locator('[name="email"]').fill('visitor@example.invalid');
      await form.locator('[name="content"]').fill(`Product inquiry regression ${lang}`);
      await expect(form.locator('[name="form_sig"]')).toHaveCount(1);
      await expect(form.locator('[name="_lang"]')).toHaveValue(lang);
      await expect.poll(async () => Date.now() / 1000 - Number(await form.locator('[name="form_ts"]').inputValue())).toBeGreaterThanOrEqual(2);
      const payload = await form.evaluate(node => Object.fromEntries(new FormData(node)));
      const missing = { ...payload }; delete missing.form_ts; delete missing.form_sig;
      const rejected = await context.request.post(`/form_submit.php?_lang=${lang}`, { form: missing });
      expect((await rejected.json()).code).toBe(1);
      expect(state().rows).toHaveLength(index);
      const response = page.waitForResponse(r => new URL(r.url()).pathname === '/form_submit.php');
      await form.locator('[type="submit"]').click();
      const submitted = await response;
      expect(new URL(submitted.url()).searchParams.get('_lang')).toBe(lang);
      const result = await submitted.json();
      expect(result.code).toBe(0);
      await expect(page.locator('#inquiryMsg')).toHaveText(result.msg);
      await expect(form.locator('[name="name"]')).toHaveValue('');
      const saved = state();
      expect(saved.rows).toHaveLength(index + 1);
      expect(saved.mail).toHaveLength(index + 1);
      expect(saved.rows.some(row => Number(row.product_id) === Number(payload.product_id) && row.content === payload.content)).toBe(true);
    }
  } finally {
    fixture('form-spam-fixture.php', 'restore');
    fixture('contact-form-fixture.php', 'restore');
  }
});
