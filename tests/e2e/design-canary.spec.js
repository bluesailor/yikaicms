// 设计系统金丝雀（v2.1 三源归一的安全网）：同一组设计数据在前台的计算样式与 :root 变量必须与基线一致。
// 设计数据换存储、换输出方式（token 迁移、主题降为编辑视图）都要过这一关。
// 有意改变外观时：CANARY_UPDATE=1 重跑一次重写基线，并在提交说明里写清改了什么。
// 截图只作附件供人工对照，不参与比对（字体渲染随浏览器版本变化）。
const { test, expect } = require('./site-diagnostics');
const { execFileSync } = require('child_process');
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '../..');
const baselinePath = path.join(__dirname, 'canary', 'design-baseline.json');
// run-local 在临时副本里跑；更新基线时写回源码树
const sourceBaselinePath = path.join(process.env.BLOX_E2E_SOURCE_ROOT || root, 'tests', 'e2e', 'canary', 'design-baseline.json');
const fixture = action => execFileSync(process.env.PHP_BINARY || 'php',
  [path.join(__dirname, 'design-canary-fixture.php'), action], { cwd: root, encoding: 'utf8' });

// 采集点：元素 ID → 取样的节点（元素根或它里面真正承载样式的标签）
const PROBES = {
  'cn-h1': 'h1', 'cn-h2': 'h2', 'cn-h3': 'h3', 'cn-body': 'p',
  'cn-btn-filled': 'a', 'cn-btn-outline': 'a', 'cn-btn-pill': 'a',
  'cn-box': null, 'cn-token-text': 'h3',
};
const PROPS = [
  'font-family', 'font-size', 'font-weight', 'line-height', 'color', 'background-color',
  'border-top-width', 'border-top-style', 'border-top-color', 'border-top-left-radius',
  'padding-top', 'padding-left', 'margin-bottom', 'max-width', 'gap', 'box-shadow',
];
const VIEWPORTS = { desktop: { width: 1440, height: 1000 }, mobile: { width: 390, height: 844 } };

let state;
test.describe.configure({ mode: 'serial' });
test.beforeAll(() => {
  fixture('restore');
  state = JSON.parse(fixture('seed').trim().split('\n').pop());
});
test.afterAll(() => fixture('restore'));
test.beforeEach(async ({}, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'canary owns its own viewports');
  test.setTimeout(120000);
});

async function sample(page) {
  return page.evaluate(({ probes, props }) => {
    const pick = (style) => Object.fromEntries(props.map(name => [name, style.getPropertyValue(name).trim()]));
    // :root 上所有 --yk-* 变量：从样式表里收集名字再逐个取计算值（getComputedStyle 不枚举自定义属性）
    const names = new Set();
    for (const sheet of Array.from(document.styleSheets)) {
      let rules;
      try { rules = sheet.cssRules; } catch (e) { continue; }
      const scan = list => Array.from(list || []).forEach(rule => {
        if (rule.cssRules) scan(rule.cssRules);
        const text = rule.style ? rule.style.cssText : '';
        (text.match(/--yk-[a-z0-9_-]+(?=\s*:)/gi) || []).forEach(name => names.add(name));
      });
      scan(rules);
    }
    const rootStyle = getComputedStyle(document.documentElement);
    const vars = {};
    Array.from(names).sort().forEach(name => {
      const value = rootStyle.getPropertyValue(name).trim();
      if (value !== '') vars[name] = value;
    });
    const elements = {};
    for (const [id, inner] of Object.entries(probes)) {
      const host = document.getElementById(id);
      const node = host && inner ? (host.matches(inner) ? host : host.querySelector(inner)) : host;
      elements[id] = node ? pick(getComputedStyle(node)) : null;
    }
    const anchor = document.getElementById('cn-h1');
    const main = anchor ? anchor.closest('section') : null;
    return { vars, elements, section: main ? pick(getComputedStyle(main)) : null };
  }, { probes: PROBES, props: PROPS });
}

test('design canary page keeps the same computed styles @ci', async ({ browser, baseURL }, info) => {
  const result = {};
  for (const [name, viewport] of Object.entries(VIEWPORTS)) {
    const context = await browser.newContext({ baseURL, viewport, storageState: { cookies: [], origins: [] } });
    try {
      const page = await context.newPage();
      expect((await page.goto(state.url)).status()).toBe(200);
      await expect(page.locator('#cn-h1')).toBeVisible();
      await page.evaluate(() => document.fonts && document.fonts.ready);
      result[name] = await sample(page);
      await info.attach(`canary-${name}`, { body: await page.screenshot({ fullPage: true }), contentType: 'image/png' });
    } finally {
      await context.close();
    }
  }
  for (const [viewport, data] of Object.entries(result)) {
    for (const [id, styles] of Object.entries(data.elements)) {
      expect(styles, `${viewport}: #${id} 未渲染`).not.toBeNull();
    }
  }
  if (process.env.CANARY_UPDATE === '1') {
    fs.mkdirSync(path.dirname(sourceBaselinePath), { recursive: true });
    fs.writeFileSync(sourceBaselinePath, JSON.stringify(result, null, 2) + '\n');
    info.annotations.push({ type: 'canary', description: '基线已写入 ' + sourceBaselinePath });
    return;
  }
  expect(fs.existsSync(baselinePath), '缺少金丝雀基线：CANARY_UPDATE=1 运行一次生成').toBe(true);
  const baseline = JSON.parse(fs.readFileSync(baselinePath, 'utf8'));
  expect(result, '设计数据在前台的计算样式变了；若是有意改动，CANARY_UPDATE=1 重写基线').toEqual(baseline);
});
