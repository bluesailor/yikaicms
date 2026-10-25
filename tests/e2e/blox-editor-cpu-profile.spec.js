// 大文档上改字的 CPU 剖析（不断言，只打印）：哪几个函数占了每次改动的时间。
const { test } = require('./site-diagnostics');
const fs = require('node:fs');
const path = require('node:path');
const { openPageEditor, waitPreviewSettled } = require('./helpers');

const fixtures = JSON.parse(fs.readFileSync(path.join(__dirname, '../smoke/fixtures.json'), 'utf8'));

test('cpu profile of edits on a large document', async ({ page }, info) => {
  test.skip(info.project.name !== 'desktop-1440', 'Desktop editor layout');
  test.setTimeout(300000);
  await openPageEditor(page, fixtures.blox_page);
  await waitPreviewSettled(page, 30000);
  await page.evaluate(() => {
    const app = window.Alpine.$data(document.body);
    const base = JSON.parse(JSON.stringify(app.sections));
    const leaves = base.flatMap((s) => s.columns || []).flatMap((c) => c.elements || []).filter((e) => !((e.data || {}).children || []).length);
    const big = [];
    for (let n = 0; big.length < 100; n++) {
      const copy = JSON.parse(JSON.stringify(base[n % base.length]));
      const col = (copy.columns || [])[0];
      if (col) { let k = 0; col.elements = col.elements || []; while (col.elements.length < 19 && leaves.length) col.elements.push(JSON.parse(JSON.stringify(leaves[k++ % leaves.length]))); }
      big.push(copy);
    }
    app.sections = big;
    app.normalizeIds();
  });
  await waitPreviewSettled(page, 120000);
  await page.evaluate(() => window.Alpine.$data(document.body).selectElement(0, 0, 0));
  await page.waitForTimeout(500);

  const cdp = await page.context().newCDPSession(page);
  await cdp.send('Profiler.enable');
  await cdp.send('Profiler.setSamplingInterval', { interval: 100 });
  await cdp.send('Profiler.start');
  await page.evaluate(async () => {
    const app = window.Alpine.$data(document.body);
    const target = app.sections.flatMap((s) => s.columns || []).flatMap((c) => c.elements || []).find((e) => e.type === 'heading');
    for (let i = 0; i < 10; i++) {
      target.data.text = String(target.data.text || '') + 'x';
      await window.Alpine.nextTick();
      await new Promise((r) => setTimeout(r, 0));
    }
  });
  const { profile } = await cdp.send('Profiler.stop');

  // 按函数汇总 self time 与 total time（total 用调用树累加）
  const byId = new Map(profile.nodes.map((n) => [n.id, n]));
  const selfUs = new Map();
  const dt = profile.timeDeltas;
  profile.samples.forEach((id, i) => selfUs.set(id, (selfUs.get(id) || 0) + (dt[i] || 0)));
  const label = (n) => `${n.callFrame.functionName || '(anon)'} ${(n.callFrame.url || '').split('/').pop().split('?')[0]}:${n.callFrame.lineNumber + 1}`;
  const totals = new Map();
  const walk = (node) => {
    let total = selfUs.get(node.id) || 0;
    for (const c of node.children || []) total += walk(byId.get(c));
    const key = label(node);
    const prev = totals.get(key) || { self: 0, total: 0 };
    prev.self += selfUs.get(node.id) || 0;
    prev.total = Math.max(prev.total, 0) + total;
    totals.set(key, prev);
    return total;
  };
  walk(profile.nodes[0]);
  const rows = [...totals.entries()].filter(([k]) => !k.startsWith('(root)') && !k.startsWith('(program)') && !k.startsWith('(idle)'));
  const ms = (us) => (us / 1000).toFixed(1);
  console.log('[cpu] top self:');
  rows.sort((a, b) => b[1].self - a[1].self).slice(0, 15).forEach(([k, v]) => console.log(`[cpu]   self ${ms(v.self)}ms total ${ms(v.total)}ms  ${k}`));
  console.log('[cpu] top total (editor functions):');
  rows.filter(([k]) => /blox_editor|yikay-/.test(k)).sort((a, b) => b[1].total - a[1].total).slice(0, 15)
    .forEach(([k, v]) => console.log(`[cpu]   total ${ms(v.total)}ms self ${ms(v.self)}ms  ${k}`));
});
