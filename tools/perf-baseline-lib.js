'use strict';

// 性能基线的纯函数部分：从 profile 用例的输出里取数、汇总、与旧基线对比。
// 只汇报，不判失败——单次共享机器上的耗时噪声太大，判定留给人看重复运行的结果。

/**
 * 认的行：
 *   [profile:home] cold {"wallUntilCanvasMs":1234,...}
 *   [bigdoc:before] {"docKB":900,...}
 *   [bigdoc:large] previewPostKB=812 previewRoundTripMs=640
 * 返回 { "profile:home.cold.wallUntilCanvasMs": 1234, ... }（只留数值）。
 */
function parseProfileOutput(text) {
  const values = {};
  for (const line of String(text).split(/\r?\n/)) {
    const match = line.match(/\[((?:profile|bigdoc):[\w-]+)\]\s*(?:(\w+)\s+)?(.*)$/);
    if (!match) continue;
    const prefix = match[2] ? `${match[1]}.${match[2]}` : match[1];
    const rest = match[3].trim();
    let fields = null;
    if (rest.startsWith('{')) {
      try { fields = JSON.parse(rest); } catch { fields = null; }
    } else {
      fields = {};
      for (const pair of rest.matchAll(/(\w+)=(-?\d+(?:\.\d+)?)/g)) fields[pair[1]] = Number(pair[2]);
    }
    if (!fields || typeof fields !== 'object') continue;
    for (const [key, value] of Object.entries(fields)) {
      if (typeof value === 'number' && Number.isFinite(value)) values[`${prefix}.${key}`] = value;
    }
  }
  return values;
}

/** 最近秩法分位数（样本少时比插值更不容易「编」出没出现过的数） */
function percentile(sorted, p) {
  if (sorted.length === 0) return null;
  const rank = Math.ceil((p / 100) * sorted.length);
  return sorted[Math.min(sorted.length, Math.max(1, rank)) - 1];
}

/** @param {Array<Record<string, number>>} runs 每次运行 parseProfileOutput 的结果 */
function summarize(runs) {
  const metrics = {};
  const keys = new Set(runs.flatMap((run) => Object.keys(run)));
  for (const key of [...keys].sort()) {
    const samples = runs.map((run) => run[key]).filter((v) => typeof v === 'number');
    const sorted = [...samples].sort((a, b) => a - b);
    metrics[key] = {
      n: sorted.length,
      median: percentile(sorted, 50),
      p95: percentile(sorted, 95),
      min: sorted[0],
      max: sorted[sorted.length - 1],
    };
  }
  return metrics;
}

/**
 * 对比两份基线。新中位数高于旧 P95（且两边都有至少 3 个样本）记为「需要复核」；
 * 只是提示，调用方不据此判失败。
 */
function compare(baseline, current) {
  const rows = [];
  for (const [key, now] of Object.entries(current)) {
    const before = baseline[key];
    if (!before) continue;
    const delta = before.median === 0 ? null : (now.median - before.median) / before.median;
    const enough = before.n >= 3 && now.n >= 3;
    rows.push({ key, before: before.median, now: now.median, delta, review: enough && now.median > before.p95 });
  }
  return rows;
}

module.exports = { parseProfileOutput, percentile, summarize, compare };
