const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { openPageEditor, frame, addTemporaryHeading, performPagePreviewUpdate, expectClean } = require('./helpers');
const root = path.resolve(__dirname, '../..');
const fixture = (...args) => execFileSync(process.env.PHP_BINARY || 'php', [path.join(__dirname, 'contact-form-fixture.php'), ...args], { cwd: root, encoding: 'utf8' });
let contact;
test.beforeAll(() => { contact = JSON.parse(fixture('setup')); });
test.afterAll(() => fixture('restore'));

test('Blox contact fields persist and anonymous submissions reach the inbox without external mail @ci', async ({ page, browser, baseURL }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Contact persistence baseline');
  test.setTimeout(90000);
  page.setDefaultTimeout(12000);
  const marker = `E7 Form ${Date.now()}`;
  const result = () => JSON.parse(fixture('result', marker));
  async function selectForm() {
    const row = page.getByTestId('blox-tree-element').and(page.locator('[data-element-type="contact_form"]'));
    await page.getByTestId('blox-tree-section').filter({ has: row }).getByTestId('blox-tree-section-label').click();
    await row.locator('[data-element-drag-handle]').first().click();
    await expect(page.getByTestId('blox-contact-form-editor')).toBeVisible();
  }
  await openPageEditor(page, contact.page);
  await selectForm();
  await page.getByTestId('blox-contact-form-title').fill(marker + ' title');
  await page.getByTestId('blox-contact-form-description').fill(marker + ' description');
  await page.getByTestId('blox-contact-form-success').fill(marker + ' received');
  const first = page.getByTestId('blox-contact-form-field-0');
  await expect(first.getByTestId('blox-contact-field-key')).toHaveValue('name');
  await first.getByTestId('blox-contact-field-label').fill(marker + ' name');
  await first.getByTestId('blox-contact-field-placeholder').fill(marker + ' placeholder');
  const save = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_contact_api.php' && r.request().method() === 'POST');
  await page.getByTestId('blox-contact-form-save').click();
  expect((await (await save).json()).code).toBe(0);
  await expect(page.getByTestId('blox-contact-form-save')).toBeDisabled();
  await page.goto('/admin/page.php');
  await openPageEditor(page, contact.page);
  await selectForm();
  await expect(page.getByTestId('blox-contact-form-title')).toHaveValue(marker + ' title');
  await expect(first.getByTestId('blox-contact-field-label')).toHaveValue(marker + ' name');
  await expect((await frame(page)).locator('#shortcode-form-contact [name="name"]')).toHaveAttribute('placeholder', marker + ' placeholder');
  // Form settings are shared and saved immediately; page layout is published separately.
  await addTemporaryHeading(page);
  await performPagePreviewUpdate(page, () => page.locator('[data-control-key="text"] input').fill(marker + ' layout'));
  const published = page.waitForResponse(r => new URL(r.url()).pathname === '/admin/blox_page_api.php'
    && new URLSearchParams(r.request().postData() || '').get('action') === 'publish');
  page.once('dialog', dialog => dialog.accept());
  await page.getByTestId('blox-publish-page').click();
  expect((await (await published).json()).code).toBe(0);
  await expectClean(page);

  const context = await browser.newContext({ baseURL, storageState: { cookies: [], origins: [] } });
  try {
    const visitor = await context.newPage(), errors = [];
    visitor.on('pageerror', error => errors.push(error.message));
    visitor.on('console', message => { if (message.type() === 'error') errors.push(message.text()); });
    const response = await visitor.goto(`/index.php?yk_route=page&id=${contact.page}`);
    expect(response.status()).toBe(200);
    await expect(visitor.locator('#ik-adminbar')).toHaveCount(0);
    await expect(visitor.getByText(marker + ' layout', { exact: true })).toBeVisible();
    const form = visitor.locator('#shortcode-form-contact');
    await expect(form.locator('[name="name"]')).toHaveAttribute('placeholder', marker + ' placeholder');
    await form.locator('button[type="submit"]').click();
    await expect.poll(() => form.evaluate(node => node.checkValidity())).toBe(false);
    expect(result()).toEqual({ rows: [], mail: [] });
    await form.locator('[name="name"]').fill(marker);
    await form.locator('[name="phone"]').fill('13800000000');
    await form.locator('[name="email"]').fill('visitor@example.invalid');
    await form.locator('[name="content"]').fill(marker + ' inquiry');
    await expect.poll(async () => Date.now() / 1000 - Number(await form.locator('[name="form_ts"]').inputValue())).toBeGreaterThanOrEqual(2);
    const signature = await form.locator('[name="form_sig"]').inputValue();
    expect(signature).toMatch(/^[a-f0-9]{32}$/);
    await form.locator('[name="form_sig"]').evaluate(node => { node.value = 'invalid-test-signature'; });
    const rejected = visitor.waitForResponse(r => new URL(r.url()).pathname === '/form_submit.php');
    await form.locator('button[type="submit"]').click();
    expect((await (await rejected).json()).code).not.toBe(0);
    await expect(visitor.locator('#shortcode-form-contact-msg')).toContainText('安全令牌');
    expect(result()).toEqual({ rows: [], mail: [] });
    await form.locator('[name="form_sig"]').evaluate((node, value) => { node.value = value; }, signature);
    const submitted = visitor.waitForResponse(r => new URL(r.url()).pathname === '/form_submit.php');
    await form.locator('button[type="submit"]').click();
    expect(await (await submitted).json()).toMatchObject({ code: 0, msg: marker + ' received' });
    await expect(visitor.locator('#shortcode-form-contact-msg')).toHaveText(marker + ' received');
    await expect(form.locator('[name="name"]')).toHaveValue('');
    const stored = result();
    expect(stored.rows).toHaveLength(1);
    expect(stored.rows[0].content).toBe(marker + ' inquiry');
    expect(stored.mail).toHaveLength(1);
    expect(stored.mail[0]).toMatchObject({ tpl: 'inquiry', name: marker });
    expect(stored.mail[0].body).toContain(marker + ' inquiry');
    expect(errors).toEqual([]);
    await page.goto(`/admin/form.php?view=${stored.rows[0].id}`);
    await expect(page.locator('#detailContent')).toContainText(marker + ' inquiry');
    await expect(page.locator('#detailContent')).toContainText(marker);
  } finally { await context.close(); }
});
