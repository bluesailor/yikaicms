const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });

test('contact and inquiry reject probes and replay while real multilingual forms submit once @ci', async ({ browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Shared server-side submission policy');
  test.setTimeout(90000);
  fixture('contact-form-fixture.php', 'setup');
  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    fixture('form-spam-fixture.php', 'setup');
    const page = await context.newPage();
    const pageErrors = [];
    page.on('pageerror', error => pageErrors.push(error.message));
    const marker = `E7 Form ${Date.now()}`;
    const state = () => JSON.parse(fixture('form-spam-fixture.php', 'result', marker));
    let savedPayload;
    for (const [index, lang] of ['zh-CN', 'en', 'ja'].entries()) {
      await page.goto(`/tests/e2e/form-spam-page.php?lang=${lang}`);
      const form = page.locator('#shortcode-form-contact');
      await form.locator('[name="name"]').fill(marker);
      await form.locator('[name="phone"]').fill(['+86 138-0000-0000', '+1 (555) 666-0606', '+81 (3) 1234-5678'][index]);
      await form.locator('[name="email"]').fill('visitor@example.invalid');
      await form.locator('[name="content"]').fill(['你好，请介绍产品。', 'Please explain SQL SELECT and O\'Reilly compatibility.', '製品の仕様を教えてください。'][index]);
      await expect.poll(async () => Date.now() / 1000 - Number(await form.locator('[name="form_ts"]').inputValue())).toBeGreaterThanOrEqual(2);
      savedPayload = await form.evaluate(node => Object.fromEntries(new FormData(node)));
      expect(savedPayload._lang).toBe(lang);
      if (index === 0) {
        for (const phone of ["-1' OR 5*5=25 --", '-1" OR 5*5=25 --']) {
          const bad = await context.request.post('/form_submit.php', { form: { ...savedPayload, phone } });
          expect(bad.status()).toBe(422);
          expect((await bad.json()).code).toBe(1);
        }
        const nested = new URLSearchParams({ ...savedPayload });
        nested.delete('phone'); nested.set('phone[x][y]', 'probe');
        const bad = await context.request.post('/form_submit.php', { data: nested.toString(), headers: { 'Content-Type': 'application/x-www-form-urlencoded' } });
        expect(bad.status()).toBe(422);
        const honey = await context.request.post('/form_submit.php', { form: { ...savedPayload, hp_url: 'bot.invalid' } });
        expect((await honey.json()).code).toBe(0);
        expect(state()).toEqual({ rows: [], mail: [] });
      }
      const response = page.waitForResponse(r => new URL(r.url()).pathname === '/form_submit.php');
      await form.locator('button[type="submit"]').click();
      const submitted = await response;
      expect(new URL(submitted.url()).searchParams.get('_lang')).toBe(lang);
      expect((await submitted.json()).code).toBe(0);
      await expect(form.locator('[name="name"]')).toHaveValue('');
      expect(state().rows).toHaveLength(index + 1);
      expect(state().mail).toHaveLength(index + 1);
      const duplicate = await context.request.post(`/form_submit.php?_lang=${lang}`, { form: savedPayload });
      expect(duplicate.status()).toBe(409);
      expect((await duplicate.json()).msg).toContain(['相同内容', 'already submitted', 'すでに送信'][index]);
      expect(duplicate.headers()['retry-after']).toBeTruthy();
      expect(state().rows).toHaveLength(index + 1);
      expect(state().mail).toHaveLength(index + 1);
    }
    const inquiry = page.locator('#shortcode-form-product-inquiry');
    const token = await inquiry.evaluate(node => Object.fromEntries(new FormData(node)));
    const crossForm = await context.request.post('/form_submit.php', { form: { ...savedPayload, form_slug: 'product-inquiry', form_sig: token.form_sig, form_ts: token.form_ts } });
    expect(crossForm.status()).toBe(409);
    const changed = { ...savedPayload, content: 'A distinct follow-up inquiry', form_slug: 'product-inquiry', form_sig: token.form_sig, form_ts: token.form_ts };
    expect((await (await context.request.post('/form_submit.php', { form: changed })).json()).code).toBe(0);
    expect(state().rows).toHaveLength(4);
    expect(state().mail).toHaveLength(4);
    // Failed token probes consume the shared budget and cannot reset it with a new session.
    let limited = false;
    for (let n = 0; n < 21; n++) {
      const result = await context.request.post('/form_submit.php', { form: { ...changed, form_sig: 'invalid' } });
      if (result.status() === 429) { limited = true; break; }
      expect((await result.json()).code).not.toBe(0);
    }
    expect(limited).toBe(true);
    const stranger = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
    try {
      expect((await stranger.request.post('/form_submit.php', { form: changed })).status()).toBe(429);
    } finally { await stranger.close(); }
    expect(state().rows).toHaveLength(4);
    expect(state().mail).toHaveLength(4);
    expect(pageErrors).toEqual([]);
  } finally {
    await context.close();
    fixture('form-spam-fixture.php', 'restore');
    fixture('contact-form-fixture.php', 'restore');
  }
});
