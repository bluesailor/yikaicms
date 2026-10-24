// 同域名子目录复制站的会话隔离（2.1.3）：同一份 config.php 复制出两个站（同名会话 cookie），
// 放在同一域名的两个子目录、同一台服务器上，登录一个站不能进另一个站的后台。
//
// 准备：
//   1. 站点 A 装成冒烟站：SMOKE_SITE_URL=http://127.0.0.1:8791/sub php tests/smoke/setup.php
//   2. 把 A 整个复制成同级目录 sub2（连同 config/config.php，模拟「复制整站」）
//   3. php -S 127.0.0.1:8791 tests/smoke/subdir-router.php
//   4. node tests/smoke/subdir-session-isolation.js [http://127.0.0.1:8791] [/sub] [/sub2] [A 站目录]
// 场景：新会话互不相通、两站可同时登录且互不踢、退出互不影响、升级前的旧会话（path=/、已登录、
// 没记站点）只能被一个站认领（认领的站保持登录，另一个站拿不到）。
// 旧会话夹具会临时写进 A 站目录，跑完删除。
const { chromium } = require('playwright');
const fs = require('fs');
const path = require('path');

const O = process.argv[2] || 'http://127.0.0.1:8791';
const A = process.argv[3] || '/sub';
const B = process.argv[4] || '/sub2';
const ROOT_A = process.argv[5] || path.resolve(__dirname, '..', '..');
const FIXTURE = '_yk_legacy_session_' + process.pid + '.php';
const fixturePath = path.join(ROOT_A, FIXTURE);

const results = [];
const check = (name, cond, extra) => results.push({ ok: !!cond, name, extra: extra || '' });

async function login(page, site) {
  await page.goto(O + site + '/admin/login.php');
  await page.fill('input[name="username"]', 'admin');
  await page.fill('input[name="password"]', 'smoke@Test123');   // 冒烟夹具的测试账号（tests/smoke/setup.php）
  await Promise.all([page.waitForNavigation(), page.click('button[type="submit"] >> visible=true')]);
}
async function inAdmin(page, site) {
  await page.goto(O + site + '/admin/index.php');
  return !page.url().includes('login.php');
}

(async () => {
  // 升级前旧代码开的会话：只经 config.php（path=/），已登录、没记站点
  fs.writeFileSync(fixturePath, "<?php\ndefine('ROOT_PATH', __DIR__);\nrequire __DIR__ . '/config/config.php';\n"
    + "$_SESSION['admin_id'] = 1; $_SESSION['admin_username'] = 'admin'; $_SESSION['admin_role_id'] = 1;\n"
    + "$_SESSION['admin_permissions'] = ['*']; echo 'legacy';\n");
  const browser = await chromium.launch();
  try {
    let ctx = await browser.newContext();
    let page = await ctx.newPage();
    await login(page, A);
    check('logged into ' + A, await inAdmin(page, A));
    check(B + ' admin not reachable with an ' + A + ' login', !(await inAdmin(page, B)));
    const paths = (await ctx.cookies()).map((c) => c.name.slice(0, 3) + '@' + c.path);
    check('no cookie left at path=/ (session, admin marker, sidebar all scoped)', paths.length > 0 && paths.every((p) => !p.endsWith('@/')), paths.join(','));
    await login(page, B);
    check('logged into ' + B + ' too', await inAdmin(page, B));
    check(A + ' still logged in', await inAdmin(page, A));
    check(B + ' still logged in', await inAdmin(page, B));
    await page.goto(O + A + '/admin/logout.php').catch(() => {});
    check(A + ' logged out', !(await inAdmin(page, A)));
    check(B + ' unaffected by ' + A + ' logout', await inAdmin(page, B));
    await ctx.close();

    for (const [first, second] of [[A, B], [B, A]]) {
      ctx = await browser.newContext();
      page = await ctx.newPage();
      await page.goto(O + A + '/' + FIXTURE);
      check('legacy session adopted by ' + first + ' (upgrade keeps admins logged in)', await inAdmin(page, first));
      check(second + ' cannot claim the same legacy session', !(await inAdmin(page, second)));
      check(first + ' still logged in afterwards', await inAdmin(page, first));
      await ctx.close();
    }

    ctx = await browser.newContext();
    page = await ctx.newPage();
    for (const site of [A, B]) {
      const r = await page.goto(O + site + '/');
      check('front ' + site + '/ 200', r && r.status() === 200);
    }
    await ctx.close();
  } finally {
    await browser.close();
    fs.rmSync(fixturePath, { force: true });
  }
  for (const r of results) console.log((r.ok ? 'PASS ' : 'FAIL ') + r.name + (r.extra ? '  [' + r.extra + ']' : ''));
  const failed = results.filter((r) => !r.ok).length;
  console.log(failed ? failed + ' failed' : 'all ' + results.length + ' passed');
  process.exit(failed ? 1 : 0);
})().catch((e) => { console.error(e); fs.rmSync(fixturePath, { force: true }); process.exit(1); });
