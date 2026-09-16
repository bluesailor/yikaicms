/**
 * A 轨故障场景补验（R1B/R1C）：真实浏览器中的失败注入。
 *
 * - 草稿保存失败：状态落红、出现就地重试、服务器保存时间不被推进；重试成功后恢复。
 * - 登录过期（302 → 登录页）：区别于一般失败的明确提示，内容不丢。
 * - 本机恢复稿：quota / 不可用 / 超限三种失败可见、旧副本原样保留、恢复可写后回到 saved。
 */
const { test, expect } = require('@playwright/test');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

const app = (page, fn, arg) => page.evaluate(([body, value]) => {
  const alpine = window.Alpine.$data(document.body);
  // eslint-disable-next-line no-new-func
  return new Function('app', 'arg', `return (${body})(app, arg);`)(alpine, value);
}, [fn.toString(), arg === undefined ? null : arg]);

async function editHeadingText(page, text) {
  await page.evaluate((value) => {
    const alpine = window.Alpine.$data(document.body);
    alpine.selectSection(alpine.sections.length - 1, false);
    alpine.addElement(alpine.elementLib.find(element => element.type === 'heading'));
    const section = alpine.sections[alpine.sections.length - 1];
    const column = section.columns[section.columns.length - 1];
    column.elements[column.elements.length - 1].data.text = value;
  }, text);
  await page.waitForTimeout(120);
}

test('save failure keeps the failed state, offers inline retry and never advances the server time @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);
  await editHeadingText(page, '故障场景一');

  let failNext = true;
  await page.route('**/admin/blox_page_api.php*', async (route) => {
    const body = route.request().postData() || '';
    if (failNext && body.includes('action=save_draft')) {
      failNext = false;
      await route.fulfill({ status: 500, contentType: 'text/plain', body: 'boom' });
      return;
    }
    await route.continue();
  });

  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', 'failed', { timeout: 10000 });
  await expect(page.getByTestId('blox-save-retry')).toBeVisible();
  expect(await app(page, a => a.lastServerSaveAt)).toBe(0);
  // 内容仍在编辑器里
  expect(await app(page, a => JSON.stringify(a.sections).includes('故障场景一'))).toBe(true);

  // 就地重试 → 服务器成功 → 状态与时间恢复
  await page.getByTestId('blox-save-retry').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|published|clean/, { timeout: 10000 });
  expect(await app(page, a => a.lastServerSaveAt)).toBeGreaterThan(0);
  await expect(page.getByTestId('blox-save-retry')).toBeHidden();
  await page.unroute('**/admin/blox_page_api.php*');
});

test('session expiry (real cleared session → login redirect) is reported distinctly and the content stays put @ci', async ({ page, context }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);
  await editHeadingText(page, '故障场景二');

  // 真实过期：清掉会话 cookie，让后台 checkLogin 自己 302 到真登录页。
  // 不再用 route.fulfill 伪造 302——Playwright 不拦截重定向的后续请求，
  // 伪造的 302 会落到真 login.php，而"仍在登录态"的会话又被弹回 index，判定必然失真。
  const cookies = await context.cookies();
  await context.clearCookies();

  await page.getByTestId('blox-save').click();
  await expect.poll(() => app(page, a => a.sessionExpired), { timeout: 10000 }).toBe(true);
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', 'failed');
  // 明确的过期提示（zh 站点文案），不是一般"保存失败"
  await expect(page.locator('text=登录已过期')).toBeVisible();
  expect(await app(page, a => a.lastServerSaveAt)).toBe(0);
  expect(await app(page, a => JSON.stringify(a.sections).includes('故障场景二'))).toBe(true);

  // 会话恢复（放回 cookie，服务器会话仍在）后重试成功，过期标记清除
  await context.addCookies(cookies);
  await page.getByTestId('blox-save-retry').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|published|clean/, { timeout: 10000 });
  expect(await app(page, a => a.sessionExpired)).toBe(false);
});

test('permission/CSRF style 403 is a plain failure with the server message, never "session expired" @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);
  await editHeadingText(page, '故障场景二乙');

  // 后台权限/CSRF 拒绝的真实形状：HTTP 403 + JSON msg（见 BloxAuthExpiredContractTest）
  let denyNext = true;
  await page.route('**/admin/blox_page_api.php*', async (route) => {
    const body = route.request().postData() || '';
    if (denyNext && body.includes('action=save_draft')) {
      denyNext = false;
      await route.fulfill({ status: 403, contentType: 'application/json', body: JSON.stringify({ code: 403, msg: '临时权限拒绝', data: null }) });
      return;
    }
    await route.continue();
  });

  await page.getByTestId('blox-save').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', 'failed', { timeout: 10000 });
  // 服务器 msg 报出来了，且没有被误报成登录过期
  await expect(page.locator('text=临时权限拒绝')).toBeVisible();
  expect(await app(page, a => a.sessionExpired)).toBe(false);
  await expect(page.locator('text=登录已过期')).toBeHidden();
  expect(await app(page, a => JSON.stringify(a.sections).includes('故障场景二乙'))).toBe(true);

  // 放行后就地重试成功
  await page.getByTestId('blox-save-retry').click();
  await expect(page.getByTestId('blox-dirty')).toHaveAttribute('data-state', /saved|published|clean/, { timeout: 10000 });
  await page.unroute('**/admin/blox_page_api.php*');
});

test('recovery quota/unavailable/toolarge are visible once and never destroy the previous copy @ci', async ({ page }, testInfo) => {
  test.skip(testInfo.project.name !== 'desktop-1440', 'desktop-only acceptance');
  await openPageEditor(page, fixtures.blox_page);

  // 先产生一份有效恢复稿
  await editHeadingText(page, '恢复稿基线');
  await app(page, (a) => { a.queueDraftRecovery(); a.draftRecovery().flush(); });
  const key = await app(page, a => a.recoveryKey);
  const baseline = await page.evaluate(k => window.localStorage.getItem(k), key);
  expect(baseline).toBeTruthy();
  await expect.poll(() => app(page, a => a.recoveryState)).toBe('saved');
  await expect(page.getByTestId('blox-recovery-state')).toBeHidden();

  // quota：写失败 → 指示可见、旧副本原样、待写内容保留
  await app(page, (a) => {
    const recovery = a.draftRecovery();
    recovery.storage = {
      getItem: k => window.localStorage.getItem(k),
      setItem: () => { const error = new Error('full'); error.name = 'QuotaExceededError'; throw error; },
      removeItem: k => window.localStorage.removeItem(k),
    };
  });
  await editHeadingText(page, '恢复稿失败甲');
  await app(page, (a) => { a.queueDraftRecovery(); a.draftRecovery().flush(); });
  await expect.poll(() => app(page, a => a.recoveryState)).toBe('quota');
  await expect(page.getByTestId('blox-recovery-state')).toBeVisible();
  await expect(page.getByTestId('blox-recovery-state')).toHaveAttribute('data-state', 'quota');
  expect(await page.evaluate(k => window.localStorage.getItem(k), key)).toBe(baseline);

  // 不可用：非 quota 异常
  await app(page, (a) => {
    a.draftRecovery().storage.setItem = () => { throw new Error('SecurityError'); };
  });
  await editHeadingText(page, '恢复稿失败乙');
  await app(page, (a) => { a.queueDraftRecovery(); a.draftRecovery().flush(); });
  await expect.poll(() => app(page, a => a.recoveryState)).toBe('unavailable');
  expect(await page.evaluate(k => window.localStorage.getItem(k), key)).toBe(baseline);

  // 恢复可写：同一份待写内容重试成功，指示消失
  await app(page, (a) => {
    a.draftRecovery().storage = window.localStorage;
    a.queueDraftRecovery();
    a.draftRecovery().flush();
  });
  await expect.poll(() => app(page, a => a.recoveryState)).toBe('saved');
  await expect(page.getByTestId('blox-recovery-state')).toBeHidden();
  const updated = await page.evaluate(k => window.localStorage.getItem(k), key);
  expect(updated).not.toBe(baseline);
  expect(updated).toContain('恢复稿失败乙');

  // 超限：不更新副本但可见
  await app(page, (a) => {
    a.draftRecovery().maxBytes = 1024;
    a.queueDraftRecovery();
  });
  await expect.poll(() => app(page, a => a.recoveryState)).toBe('toolarge');
  await expect(page.getByTestId('blox-recovery-state')).toHaveAttribute('data-state', 'toolarge');
  expect(await page.evaluate(k => window.localStorage.getItem(k), key)).toBe(updated);
});
