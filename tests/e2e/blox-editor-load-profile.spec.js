// 编辑器打开成本采样（耗时不设阈值，仍检查运行错误）：HTML、可编辑耗时、长任务、脚本、DOM、堆。
// 冷开（新上下文）与热开（同上下文第二次）各一次，首页编辑器与单页编辑器各一次。
const { test, expect } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function sample(page, url) {
  let htmlBytes = 0;
  const onResponse = async (response) => {
    if (response.url().includes('/admin/blox_editor.php') && response.request().resourceType() === 'document') {
      htmlBytes = (await response.body()).length;
    }
  };
  page.on('response', onResponse);
  const started = Date.now();
  await page.goto(url, { waitUntil: 'domcontentloaded' });
  await page.waitForFunction(() => {
    const canvas = document.querySelector('[data-testid="blox-canvas"]');
    return window.Alpine && canvas && canvas.contentDocument && canvas.contentDocument.readyState === 'complete'
      && canvas.contentDocument.body && canvas.contentDocument.body.children.length > 0;
  }, null, { timeout: 60000 });
  const wall = Date.now() - started;
  page.off('response', onResponse);
  const metrics = await page.evaluate(() => {
    const nav = performance.getEntriesByType('navigation')[0];
    const scripts = performance.getEntriesByType('resource').filter((r) => r.initiatorType === 'script');
    const heads = [...document.head.querySelectorAll('script[src]')];
    return {
      responseEnd: Math.round(nav.responseEnd),
      domInteractive: Math.round(nav.domInteractive),
      domContentLoaded: Math.round(nav.domContentLoadedEventEnd),
      scripts: scripts.length,
      scriptKB: Math.round(scripts.reduce((sum, r) => sum + (r.encodedBodySize || 0), 0) / 1024),
      headSyncScripts: heads.filter((s) => !s.defer && !s.async).length,
      inlineScriptKB: Math.round([...document.querySelectorAll('script:not([src])')].reduce((sum, s) => sum + new TextEncoder().encode(s.textContent).length, 0) / 1024),
      richTextRequests: scripts.filter(r => new URL(r.name).pathname.endsWith('/assets/hugerte/hugerte.min.js')).length,
      dom: document.getElementsByTagName('*').length,
      heapMB: performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null,
      longTasks: (window.__ykLong || []).length,
      longTaskMs: Math.round((window.__ykLong || []).reduce((sum, t) => sum + t, 0)),
      longestTask: Math.round(Math.max(0, ...(window.__ykLong || []))),
    };
  });
  return { wallUntilCanvasMs: wall, htmlKB: Math.round(htmlBytes / 1024), ...metrics };
}

for (const [name, url] of [['home', '/admin/blox_editor.php?home=1'], ['page', `/admin/blox_editor.php?id=${fixtures.blox_page}`]]) {
  test(`editor load profile: ${name}`, async ({ page }, info) => {
    test.skip(info.project.name !== 'desktop-1440', 'Desktop editor layout');
    test.setTimeout(180000);
    await page.addInitScript(() => {
      window.__ykLong = [];
      try {
        new PerformanceObserver((list) => { for (const e of list.getEntries()) window.__ykLong.push(e.duration); })
          .observe({ type: 'longtask', buffered: true });
      } catch (e) { /* not supported */ }
    });
    const cold = await sample(page, url);
    const warm = await sample(page, url);
    const warm2 = await sample(page, url);
    console.log(`[profile:${name}] cold ${JSON.stringify(cold)}`);
    console.log(`[profile:${name}] warm ${JSON.stringify(warm)}`);
    console.log(`[profile:${name}] warm2 ${JSON.stringify(warm2)}`);
    // 首屏本来就不请求 HugeRTE，无须再阻断一个不存在的请求作为性能对照。
    for (const result of [cold, warm, warm2]) expect(result.richTextRequests).toBe(0);
  });
}
