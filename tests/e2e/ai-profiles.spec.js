const { test, expect } = require('./site-diagnostics');
const { spawn } = require('child_process');
const fs = require('fs');
const net = require('net');
const os = require('os');
const path = require('path');

// 多套 AI API 配置（2.1.2）：保存两套、连接测试不改主 API 也不借用别的密钥、切换主 API、归档后两步删除。
// 服务端发出的 AI 请求打到本地模拟上游（tests/fixtures/ai-mock-router.php），不碰真实接口。
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
const requests = () => fs.readFileSync(mockLog, 'utf8').split('\n').filter(Boolean).map((l) => JSON.parse(l));

test.beforeAll(async () => {
  const port = await freePort();
  mockLog = path.join(os.tmpdir(), `ai-mock-${process.pid}-${Date.now()}.jsonl`);
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

async function fillProfile(page, { name, key, model, base, translation = '' }) {
  await page.locator('#aiProfileName').fill(name);
  await page.locator('input[name="ai_provider"][value="custom_openai"]').check({ force: true });
  await page.locator('#aiApiKey').fill(key);
  await page.locator('#aiModelInput').fill(model);
  await page.locator('#aiTranslationModel').fill(translation);
  await page.locator('#aiBaseUrl').fill(base);
}
const row = (page, name) => page.getByTestId('ai-profile-row').filter({ hasText: name });

test('several AI API profiles: save, test without saving, switch primary, archive and delete @ci', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'one viewport is enough');

  // 新装站点：安装时预填的 DeepSeek 单配置承接为 1 号配置（还没有密钥），即主 API
  await page.goto('/admin/setting_ai.php');
  await expect(page.getByTestId('ai-profile-row')).toHaveCount(1);
  await expect(page.getByTestId('ai-profile-primary-badge')).toHaveCount(1);

  // 1 号改成 Mock A 并保存
  await fillProfile(page, { name: 'Mock A', key: 'key-aaaa-1111', model: 'model-a', base: `${mockBase}/a/v1`, translation: 'translate-a' });
  await page.getByTestId('ai-profile-save').click();
  await page.waitForURL(/\?profile=\d+/);
  const idA = new URL(page.url()).searchParams.get('profile');
  await expect(row(page, 'Mock A').getByTestId('ai-profile-primary-badge')).toBeVisible();
  await expect(row(page, 'Mock A')).toContainText('1111');

  // 新增 Mock B：先在未保存时测试连接——请求打到 B 的地址、用 B 的密钥；主 API 不变，B 也没被存下
  await page.getByTestId('ai-profile-add').click();
  await expect(page.getByTestId('ai-profile-form-title')).toBeVisible();
  await fillProfile(page, { name: 'Mock B', key: 'key-bbbb-2222', model: 'model-b', base: `${mockBase}/b/v1` });
  await page.locator('#testBtn').click();
  await expect(page.locator('#testResult')).toContainText('OK /b/v1/chat/completions model-b');
  const tested = requests().at(-1);
  expect(tested.path).toBe('/b/v1/chat/completions');
  expect(tested.auth).toBe('Bearer key-bbbb-2222');
  await page.reload();
  await expect(page.getByTestId('ai-profile-row')).toHaveCount(1);
  await expect(row(page, 'Mock A').getByTestId('ai-profile-primary-badge')).toBeVisible();

  // 保存 B：不是主 API
  await fillProfile(page, { name: 'Mock B', key: 'key-bbbb-2222', model: 'model-b', base: `${mockBase}/b/v1` });
  await page.getByTestId('ai-profile-save').click();
  await page.waitForURL(/\?profile=\d+/);
  const idB = new URL(page.url()).searchParams.get('profile');
  expect(idB).not.toBe(idA);
  await expect(page.getByTestId('ai-profile-row')).toHaveCount(2);
  await expect(row(page, 'Mock B').getByTestId('ai-profile-primary-badge')).toHaveCount(0);

  // 已保存的 B 用脱敏值测试：用 B 自己存的密钥，密钥不出现在页面里
  await expect(page.locator('#aiApiKey')).toHaveValue(/\*\*\*/);
  await page.locator('#testBtn').click();
  await expect(page.locator('#testResult')).toContainText('OK /b/v1/chat/completions');
  expect(requests().at(-1).auth).toBe('Bearer key-bbbb-2222');
  const html = await page.content();
  expect(html).not.toContain('key-aaaa-1111');
  expect(html).not.toContain('key-bbbb-2222');

  // 切换主 API 到 B，刷新后保持
  await row(page, 'Mock B').getByTestId('ai-profile-set-primary').click();
  await expect(row(page, 'Mock B').getByTestId('ai-profile-primary-badge')).toBeVisible();
  await page.reload();
  await expect(row(page, 'Mock B').getByTestId('ai-profile-primary-badge')).toBeVisible();
  await expect(row(page, 'Mock A').getByTestId('ai-profile-primary-badge')).toHaveCount(0);

  // 归档 A，再在归档区两步删除（第一次只换成确认文案，不弹原生对话框）
  let dialogs = 0;
  page.on('dialog', (d) => { dialogs++; d.dismiss(); });
  await row(page, 'Mock A').getByRole('button', { name: /归档|Archive/ }).click();
  await expect(page.getByTestId('ai-profile-archived')).toBeVisible();
  await expect(page.getByTestId('ai-profile-row')).toHaveCount(1);
  await page.getByTestId('ai-profile-archived').locator('summary').click();
  const del = page.getByTestId('ai-profile-archived').getByRole('button', { name: /删除|Delete/ });
  await del.click();
  await expect(del).toHaveText(/再点一次|again/);
  await del.click();
  await expect(page.getByTestId('ai-profile-archived')).toHaveCount(0);
  expect(dialogs).toBe(0);
  await expect(row(page, 'Mock B').getByTestId('ai-profile-primary-badge')).toBeVisible();
});
