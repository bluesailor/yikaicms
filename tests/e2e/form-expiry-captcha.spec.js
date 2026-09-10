const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const root = path.resolve(__dirname, '../..');
const fixture = (file, ...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, file), ...args], { cwd: root, encoding: 'utf8' });
const configure = action => fixture('form-spam-fixture.php', action);
const productUrl = () => JSON.parse(fixture('theme-site-baseline-fixture.php', 'manifest', 'zh-CN')).find(r => r.kind === 'product-detail').url;
async function fill(form, marker) {
  await form.locator('[name="name"]').fill(marker);
  await form.locator('[name="phone"]').fill('13800000000');
  await form.locator('[name="content"]').fill(`Please provide details: ${marker}`);
}
async function mature(form, seconds = 2) {
  await expect.poll(async () => Date.now() / 1000 - Number(await form.locator('[name="form_ts"]').inputValue()), { timeout: 12000 }).toBeGreaterThanOrEqual(seconds);
}
async function submit(page, form) {
  const response = page.waitForResponse(r => new URL(r.url()).pathname === '/form_submit.php');
  await form.locator('button[type="submit"]').click();
  return (await response).json();
}
test.beforeEach(async ({ context }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Server policy and shared form behavior');
  fixture('contact-form-fixture.php', 'setup');
  configure('setup');
  await context.clearCookies();
});
test.afterEach(async ({}, info) => {
  if (info.project.name !== 'desktop-1440') return;
  configure('restore'); fixture('contact-form-fixture.php', 'restore');
});

test('cached inquiry and long-lived contact renew expired tokens without losing input or auto-submitting @ci', async ({ page, context }) => {
  test.setTimeout(90000);
  configure('short-expiry');
  for (const [url, selector] of [[productUrl(), '#inquiryForm'], ['/tests/e2e/form-spam-page.php?lang=en', '#shortcode-form-contact']]) {
    const marker = `E7 Form ${Date.now()}`;
    const state = () => JSON.parse(fixture('form-spam-fixture.php', 'result', marker));
    await page.goto(url);
    const form = page.locator(selector);
    const timestamp = await form.locator('[name="form_ts"]').inputValue();
    if (selector === '#inquiryForm') {
      const cached = await page.reload();
      expect(cached.headers()['x-cache']).toBe('HIT');
      await expect(form.locator('[name="form_ts"]')).toHaveValue(timestamp);
    }
    await fill(form, marker);
    await mature(form, 6);
    const payload = await form.evaluate(node => Object.fromEntries(new FormData(node)));
    const forged = await context.request.post('/form_submit.php', { form: { ...payload, form_sig: 'forged' } });
    const denied = await forged.json();
    expect(denied.code).toBe(1); expect(denied.refresh_token).toBeUndefined();
    const expired = await submit(page, form);
    expect(expired.code).toBe(1);
    expect(expired.refresh_token.form_sig).not.toBe(payload.form_sig);
    await expect(form.locator('[name="name"]')).toHaveValue(marker);
    await expect(form.locator('[name="content"]')).toHaveValue(payload.content);
    await expect(form.locator('[name="form_ts"]')).not.toHaveValue(timestamp);
    expect(state()).toEqual({ rows: [], mail: [] });
    await mature(form);
    expect((await submit(page, form)).code).toBe(0);
    await expect(form.locator('[name="name"]')).toHaveValue('');
    await expect(form.locator('[name="form_sig"]')).toHaveValue(expired.refresh_token.form_sig);
    expect(state().rows).toHaveLength(1); expect(state().mail).toHaveLength(1);
  }
});

test('enabled product captcha rejects wrong code, refreshes and then submits once @ci', async ({ page, context }) => {
  configure('captcha');
  await page.goto(productUrl());
  const form = page.locator('#inquiryForm');
  const input = form.locator('[name="captcha_code"]');
  await expect(input).toBeVisible();
  const image = form.locator('.form-captcha img');
  await expect.poll(() => image.evaluate(node => node.naturalWidth)).toBe(120);
  const marker = `E7 Form ${Date.now()}`;
  const state = () => JSON.parse(fixture('form-spam-fixture.php', 'result', marker));
  await fill(form, marker); await input.fill('WRONG'); await mature(form);
  const refreshed = page.waitForResponse(r => new URL(r.url()).pathname === '/captcha.php');
  expect((await submit(page, form)).code).toBe(1);
  expect((await refreshed).ok()).toBe(true);
  expect(state()).toEqual({ rows: [], mail: [] });
  await expect(form.locator('[name="name"]')).toHaveValue(marker);
  const correct = await (await context.request.get('/tests/e2e/form-captcha-state.php')).json();
  expect(correct.code).toMatch(/^[a-z2-9]{4}$/);
  await input.fill(correct.code);
  expect((await submit(page, form)).code).toBe(0);
  expect(state().rows).toHaveLength(1); expect(state().mail).toHaveLength(1);
});
