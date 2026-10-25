// 大文档编辑成本采样（不断言，只打印）：把单页文档复制到接近上限，量一次属性修改的同步开销、
// 各序列化函数耗时、撤销历史占用、预览请求体积。不保存。
const { test } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

async function measure(page, label) {
  const result = await page.evaluate(async () => {
    const app = window.Alpine.$data(document.body);
    const time = (fn, n = 5) => { const t = performance.now(); for (let i = 0; i < n; i++) fn(); return (performance.now() - t) / n; };
    const count = (els) => els.reduce((sum, el) => sum + 1 + count(((el.data || {}).children) || []), 0);
    const elements = app.sections.reduce((sum, s) => sum + (s.columns || []).reduce((c, col) => c + count(col.elements || []), 0), 0);
    const docBytes = app.documentData().length;
    const out = {
      sections: app.sections.length, elements, docKB: Math.round(docBytes / 1024),
      documentDataMs: +time(() => app.documentData()).toFixed(1),
      historyDataMs: +time(() => app.historyData()).toFixed(1),
      historyStructureMs: +time(() => app.historyStructure()).toFixed(1),
      rawStringifyMs: +time(() => JSON.stringify(window.Alpine.raw(app.sections))).toFixed(1),
      proxyStringifyMs: +time(() => JSON.stringify(app.sections)).toFixed(1),
      rawSame: JSON.stringify(window.Alpine.raw(app.sections)) === JSON.stringify(app.sections),
    };
    // 一次属性修改：改第一个标题的文字，等 Alpine 跑完 watcher 与 DOM 更新
    const target = app.sections.flatMap((s) => s.columns || []).flatMap((c) => c.elements || []).find((e) => e.type === 'heading');
    const samples = [];
    for (let i = 0; i < 8; i++) {
      const t = performance.now();
      target.data.text = String(target.data.text || '') + 'x';
      await window.Alpine.nextTick();
      await new Promise((r) => setTimeout(r, 0));
      samples.push(performance.now() - t);
      await new Promise((r) => setTimeout(r, 120));
    }
    samples.sort((a, b) => a - b);
    out.editMedianMs = +samples[Math.floor(samples.length / 2)].toFixed(1);
    out.editMaxMs = +samples[samples.length - 1].toFixed(1);
    app.flushHistory(false);
    const store = app.historyStore();
    out.historyEntries = store.entries.length;
    out.historyKB = Math.round(store.entries.reduce((sum, e) => sum + (e.data || '').length + (e.structure || '').length + (e.settings || '').length, 0) / 1024);
    out.historyKBAt51 = Math.round(out.historyKB / Math.max(1, out.historyEntries) * 51);
    out.heapMB = performance.memory ? Math.round(performance.memory.usedJSHeapSize / 1048576) : null;
    return out;
  });
  console.log(`[bigdoc:${label}] ${JSON.stringify(result)}`);
}

test('editor cost on a large document', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop editor layout');
  test.setTimeout(300000);
  let previewBytes = 0;
  let previewMs = 0;
  page.on('requestfinished', async (request) => {
    if (!request.url().includes('/admin/blox_') || request.method() !== 'POST') return;
    const body = request.postDataBuffer();
    if (!body || !body.includes('blocks_data')) return;
    previewBytes = Math.max(previewBytes, body.length);
    const timing = request.timing();
    previewMs = Math.max(previewMs, Math.round(timing.responseEnd));
  });
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page, 30000);
  await measure(page, 'fixture');

  // 复制到 100 个区块（上限），元素按 2000 封顶
  await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const base = JSON.parse(JSON.stringify(app.sections));
    const big = [];
    let n = 0;
    const leaves = base.flatMap((s) => s.columns || []).flatMap((c) => c.elements || []).filter((e) => !((e.data || {}).children || []).length);
    while (big.length < 100) {
      const copy = JSON.parse(JSON.stringify(base[n % base.length]));
      // 每个区块第一列补到 19 个元素：100 × 19 ≈ 1900，接近 2000 上限
      const col = (copy.columns || [])[0];
      if (col) {
        col.elements = col.elements || [];
        let k = 0;
        while (col.elements.length < 19 && leaves.length) col.elements.push(JSON.parse(JSON.stringify(leaves[k++ % leaves.length])));
      }
      big.push(copy);
      n++;
    }
    app.sections = big;
    app.normalizeIds();
  });
  await waitPreviewSettled(page, 120000);
  previewBytes = 0; previewMs = 0;
  await measure(page, 'large');
  await waitPreviewSettled(page, 120000);
  console.log(`[bigdoc:large] previewPostKB=${Math.round(previewBytes / 1024)} previewRoundTripMs=${previewMs}`);

  // 真实连续打字：在设置面板的标题输入框里打 12 个字，每键 150 ms；统计这段时间的长任务与最慢一次输入响应
  await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const si = app.sections.findIndex((s) => (s.columns || []).some((c) => (c.elements || []).some((e) => e.type === 'heading')));
    const ci = app.sections[si].columns.findIndex((c) => (c.elements || []).some((e) => e.type === 'heading'));
    const ei = app.sections[si].columns[ci].elements.findIndex((e) => e.type === 'heading');
    app.selectElement(si, ci, ei);
  });
  const field = page.getByTestId('blox-heading-text');
  await field.waitFor();
  await page.evaluate(() => {
    window.__burstLong = [];
    window.__burstEvents = [];
    window.__burstObs = [
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__burstLong.push(e.duration); }),
      new PerformanceObserver((l) => { for (const e of l.getEntries()) window.__burstEvents.push(e.duration); }),
    ];
    window.__burstObs[0].observe({ type: 'longtask' });
    window.__burstObs[1].observe({ type: 'event', durationThreshold: 16 });
  });
  const started = Date.now();
  await field.pressSequentially('TypingProbe!', { delay: 150 });
  await waitPreviewSettled(page, 120000);
  const burst = await page.evaluate(() => {
    window.__burstObs.forEach((o) => o.disconnect());
    const long = window.__burstLong;
    const events = window.__burstEvents;
    return {
      longTasks: long.length,
      longTaskMs: Math.round(long.reduce((a, b) => a + b, 0)),
      longestTask: Math.round(Math.max(0, ...long)),
      slowInputs: events.length,
      slowestInputMs: Math.round(Math.max(0, ...events)),
    };
  });
  console.log(`[bigdoc:typing] ${JSON.stringify({ ...burst, untilSettledMs: Date.now() - started })}`);
});
