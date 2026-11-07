const { test, expect } = require('./site-diagnostics');
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

// 批量翻译（评审 T03）：产品列表勾选 → 选语言 → 任务页逐项翻成草稿。
// 服务端的模型请求打到本地模拟上游（tests/fixtures/ai-mock-router.php 的 /translate/ 路由）。
const root = path.resolve(__dirname, '../..');
let mock = null;
let mockBase = '';
let mockLog = '';

const freePort = () => new Promise((resolve, reject) => {
  const srv = net.createServer();
  srv.once('error', reject);
  srv.listen(0, '127.0.0.1', () => { const { port } = srv.address(); srv.close(() => resolve(port)); });
});
const waitPort = async (port) => {
  for (let i = 0; i < 50; i++) {
    const ok = await new Promise((resolve) => {
      const s = net.connect(port, '127.0.0.1', () => { s.end(); resolve(true); });
      s.once('error', () => resolve(false));
    });
    if (ok) return;
    await new Promise((r) => setTimeout(r, 100));
  }
  throw new Error('mock AI upstream did not start');
};
const csrf = (page) => page.locator('meta[name="csrf-token"]').getAttribute('content');
async function saveLanguages(page, enabled) {
  await page.goto('/admin/setting_lang.php');
  const form = { action: 'save_lang', default_lang: 'zh-CN', admin_lang: 'zh-CN', show_switcher: '0', _token: await csrf(page) };
  const body = new URLSearchParams(form);
  enabled.forEach((lang) => body.append('enabled[]', lang));
  const response = await page.request.post('/admin/setting_lang.php', { headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, data: body.toString() });
  expect((await response.json()).code).toBe(0);
}

async function useMockAi(page) {
  await page.goto('/admin/setting_ai.php');
  await page.locator('#aiProfileName').fill('Mock translate');
  await page.locator('input[name="ai_provider"][value="custom_openai"]').check({ force: true });
  await page.locator('#aiApiKey').fill('key-translate-1');
  await page.locator('#aiModelInput').fill('model-t');
  await page.locator('#aiTranslationModel').fill('');
  await page.locator('#aiBaseUrl').fill(`${mockBase}/translate/v1`);
  await page.getByTestId('ai-profile-save').click();
  await page.waitForURL(/\?profile=\d+/);
}
async function saveProduct(page, id, title, body) {
  await page.goto(`/admin/product_edit.php?id=${id}`);
  // 提交时正文取自编辑器；隔离站点里编辑器不一定加载得出来，这里绕过它、明确给出正文
  await page.evaluate((html) => { window.editor = null; document.getElementById('contentInput').value = html; }, body);
  await page.locator('input[name="title"]').fill(title);
  const response = page.waitForResponse((r) => r.request().method() === 'POST' && new URL(r.url()).pathname === '/admin/product_edit.php');
  await page.locator('#editForm button[name="product_save"][value="apply"]').click();
  const result = await (await response).json();
  expect(result.code, JSON.stringify(result)).toBe(0);
}

test.beforeAll(async () => {
  const port = await freePort();
  mockLog = path.join(os.tmpdir(), `ai-mock-tbt-${process.pid}-${Date.now()}.jsonl`);
  fs.writeFileSync(mockLog, '');
  mock = spawn(process.env.PHP_BINARY || 'php', ['-S', `127.0.0.1:${port}`, path.join(root, 'tests/fixtures/ai-mock-router.php')],
    { env: { ...process.env, AI_MOCK_LOG: mockLog }, stdio: 'ignore' });
  mockBase = `http://127.0.0.1:${port}`;
  await waitPort(port);
});
test.afterAll(() => {
  if (mock) mock.kill();
  if (mockLog && fs.existsSync(mockLog)) fs.unlinkSync(mockLog);
});

test('batch translation turns selected products into draft translations', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(120000);

  await saveLanguages(page, ['zh-CN', 'en']);
  try {
    // 主 API 指向模拟上游
    await useMockAi(page);

    // 复制两个产品：副本没有任何译文
    await page.goto('/admin/product.php?lang=zh-CN');
    const firstId = await page.locator('input[name="ids[]"]').first().getAttribute('value');
    const copies = [];
    for (let i = 0; i < 2; i++) {
      const response = await page.request.post('/admin/product.php', { multipart: { action: 'duplicate', id: firstId, _token: await csrf(page) } });
      copies.push(String((await response.json()).data.id));
    }

    await page.goto('/admin/product.php?lang=zh-CN&per_page=100');
    for (const id of copies) await page.locator(`input[name="ids[]"][value="${id}"]`).check();
    await page.locator('#bulkAction').selectOption('batch_translate');
    await page.getByRole('button', { name: /应用|Apply/ }).click();
    const dialog = page.locator('#tbtDialog');
    await expect(dialog).toBeVisible();
    await dialog.locator('input[name="langs[]"][value="en"]').check();
    await dialog.locator('button[type="submit"]').click();
    await page.waitForURL(/translation_tasks\.php\?id=\d+/);

    const rows = page.locator('#tbtRows tr');
    await expect(rows).toHaveCount(2);
    await page.locator('#tbtRun').click();
    await expect(page.locator('#tbtRows')).not.toContainText(/等待|翻译中|Waiting|Translating/, { timeout: 60000 });
    await expect(rows.filter({ hasText: /已存为草稿|Saved as draft/ })).toHaveCount(2);
    await expect(page.locator('#tbtRun')).toBeDisabled();

    // 译文是草稿、标题来自模拟上游
    const link = rows.first().getByRole('link', { name: /打开译文|Open translation/ });
    await link.click();
    await expect(page.locator('input[name="title"]')).toHaveValue(/^Translated \d+/);

    // 再建同一批：两项都已有译文，不再排队
    const again = await page.request.post('/admin/translation_tasks.php', { multipart: { action: 'create', 'ids[]': copies[0], 'langs[]': 'en', _token: await csrf(page) } });
    const reply = await again.json();
    expect(reply.code).toBe(0);
    await page.goto(`/admin/translation_tasks.php?id=${reply.data.id}`);
    await expect(page.locator('#tbtRows')).toContainText(/已有译文，跳过|Translation exists, skipped/);

    const translateCalls = fs.readFileSync(mockLog, 'utf8').split('\n').filter((line) => line.includes('/translate/v1/chat/completions'));
    expect(translateCalls.length).toBeGreaterThan(0);
  } finally {
    await saveLanguages(page, ['zh-CN']);
  }
});

// T04：原文改了 → 状态页标过期 → 人改过的译文批量更新时转待审 → 逐字段采用 → 已审
test('outdated translations are flagged and edited ones are reviewed field by field', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');
  test.setTimeout(180000);

  await saveLanguages(page, ['zh-CN', 'en']);
  try {
    await useMockAi(page);
    await page.goto('/admin/product.php?lang=zh-CN');
    const firstId = await page.locator('input[name="ids[]"]').first().getAttribute('value');
    const copy = String((await (await page.request.post('/admin/product.php', { multipart: { action: 'duplicate', id: firstId, _token: await csrf(page) } })).json()).data.id);

    const created = await (await page.request.post('/admin/translation_tasks.php', { multipart: { action: 'create', 'ids[]': copy, 'langs[]': 'en', _token: await csrf(page) } })).json();
    expect(created.code).toBe(0);
    await page.goto(`/admin/translation_tasks.php?id=${created.data.id}`);
    await page.locator('#tbtRun').click();
    await expect(page.locator('#tbtRows')).toContainText(/已存为草稿|Saved as draft/, { timeout: 60000 });
    const target = new URL(await page.locator('#tbtRows a', { hasText: /打开译文|Open translation/ }).getAttribute('href'), page.url()).searchParams.get('id');

    const cell = page.locator(`tr[data-source="${copy}"] td[data-lang="en"]`);
    await page.goto('/admin/translation_status.php');
    await expect(cell).toContainText(/最新|Up to date/);

    // 原文改标题 → 过期；有人手改了译文标题 → 受保护
    await saveProduct(page, copy, `T04 source ${copy} v2`, '<p>Stable body</p>');
    await saveProduct(page, target, 'Hand edited title', '<p>Hand edited body</p>');
    await page.goto('/admin/translation_status.php?filter=outdated');
    await expect(cell).toContainText(/原文已改|Original changed/);

    await page.locator(`tr[data-source="${copy}"] input[type=checkbox]`).check();
    await page.locator('#tssUpdate').click();
    await page.waitForURL(/translation_tasks\.php\?id=\d+/);
    await page.locator('#tbtRun').click();
    await expect(page.locator('#tbtRows')).toContainText(/已存为待审|Saved for review/, { timeout: 60000 });

    // 只采用标题；其余字段保持
    await page.locator('#tbtRows a', { hasText: /去审阅|Review/ }).click();
    const panel = page.locator('#lseProposal');
    await expect(panel).toBeVisible();
    // 原文正文换过：新译文的正文有内容，人改过的正文作为「现有」并列（下面不采用它）
    const body = panel.locator('fieldset', { has: page.locator('input[value="content"]') }).locator('textarea');
    await expect(body.nth(0)).toHaveValue('<p>Hand edited body</p>');
    await expect(body.nth(1)).toHaveValue(/Translated \d+/);
    await info.attach('translation-proposal-panel', { body: await panel.screenshot(), contentType: 'image/png' });
    for (const box of await panel.locator('input[type=checkbox]').all()) {
      if ((await box.getAttribute('value')) !== 'title') await box.uncheck();
    }
    await page.locator('#lseAccept').click();
    await expect(panel).toBeHidden({ timeout: 15000 });
    await expect(page.locator('input[name="title"]')).toHaveValue(/^Translated \d+/);
    await expect(page.locator('[data-translation-state="en"]')).toContainText(/已审|Reviewed/);

    await page.goto('/admin/translation_status.php');
    await expect(cell).toContainText(/已审|Reviewed/);
  } finally {
    await saveLanguages(page, ['zh-CN']);
  }
});
