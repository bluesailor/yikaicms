'use strict';

const fs = require('node:fs');
const path = require('node:path');

const SHARDS = Object.freeze({
  // Each shard owns a 900-port range. Phase ports advance by 10, with the
  // adjacent port reserved for the template fixture server.
  core: Object.freeze({ key: 'core', label: 'Editor core and publishing', port: 8100 }),
  media: Object.freeze({ key: 'media', label: 'Banner, media and video', port: 9100 }),
  design: Object.freeze({ key: 'design', label: 'Templates, areas and design system', port: 10100 }),
  locale: Object.freeze({ key: 'locale', label: 'Languages, free mode and responsive', port: 11100 }),
});

const SHARD_KEYS = Object.freeze(Object.keys(SHARDS));
const PHASE_PORT_STEP = 10;
const INSTANCE_PORT_SPAN = 10_000;
// 分片基址相隔 1000，留 100 缓冲。phase 数量长到越界必须炸，不能静默借用下一个
// 分片的基址——并行跑时那会让两个一次性站点抢同一个端口。
const SHARD_PORT_WINDOW = 900;

function phasePort(key, phaseIndex, slot = 0) {
  const shard = SHARDS[key];
  if (!shard || !Number.isInteger(phaseIndex) || phaseIndex < 0 || !Number.isInteger(slot) || slot < 0) {
    throw new Error('Invalid browser phase port request');
  }
  const offset = phaseIndex * PHASE_PORT_STEP;
  if (offset + PHASE_PORT_STEP > SHARD_PORT_WINDOW) {
    throw new Error(`Browser shard ${key} exceeded its ${SHARD_PORT_WINDOW}-port window at phase ${phaseIndex}`);
  }
  return shard.port + (slot * INSTANCE_PORT_SPAN) + offset;
}

// A spec file runs in one fresh site. This keeps tests that intentionally seed
// themes or settings from leaking state into another spec while avoiding
// brittle per-test database resets inside a single scenario file.
const SPEC_OWNERSHIP = Object.freeze([
  ['media', /(?:background-video|banner|media-|responsive-image|site-health-media)/i],
  ['design', /(?:default-areas|design-|market-theme|minimal-(?:footer|header)|business-surfaces|theme-)/i],
  ['locale', /(?:channel-pagination|catalog-results|dynamic-query|frontend-language|home-language|element-responsive)/i],
]);

function normalizeSpecName(file) {
  return path.basename(String(file || '')).replaceAll('\\', '/');
}

function shardForSpec(file) {
  const name = normalizeSpecName(file);
  for (const [key, pattern] of SPEC_OWNERSHIP) {
    if (pattern.test(name)) return key;
  }
  return 'core';
}

function specsForShard(key, root = path.resolve(__dirname)) {
  if (!SHARDS[key]) throw new Error(`Unknown browser shard: ${key}`);
  return fs.readdirSync(root)
    .filter((name) => name.endsWith('.spec.js'))
    .filter((name) => shardForSpec(name) === key)
    .sort()
    .map((name) => path.join(root, name));
}

function ciSpecsForShard(key, root = path.resolve(__dirname)) {
  return specsForShard(key, root).filter((file) => fs.readFileSync(file, 'utf8').includes('@ci'));
}

function extraPhasesForShard(key, root = path.resolve(__dirname)) {
  if (key !== 'locale') return [];
  return [
    {
      name: 'language-en',
      spec: path.join(root, 'blox-home-language.spec.js'),
      grep: '@language',
      args: ['--lang=en'],
      projects: ['desktop-1440'],
    },
    {
      name: 'language-ja',
      spec: path.join(root, 'blox-home-language.spec.js'),
      grep: '@language',
      args: ['--lang=ja'],
      projects: ['desktop-1440'],
    },
    {
      name: 'free-mode',
      spec: path.join(root, 'blox-page.spec.js'),
      grep: '@ci',
      args: [],
      projects: ['desktop-1440'],
      free: true,
    },
  ];
}

function phasesForShard(key, root = path.resolve(__dirname)) {
  return [
    ...ciSpecsForShard(key, root).map((spec) => ({ spec, grep: '@ci', args: [], projects: undefined, free: false })),
    ...extraPhasesForShard(key, root),
  ];
}

function shardMatrix(keys = SHARD_KEYS) {
  return {
    include: keys.map((key) => {
      const shard = SHARDS[key];
      if (!shard) throw new Error(`Unknown browser shard: ${key}`);
      return { shard: shard.key, label: shard.label, port: shard.port };
    }),
  };
}

module.exports = {
  SHARDS,
  SHARD_KEYS,
  PHASE_PORT_STEP,
  INSTANCE_PORT_SPAN,
  SHARD_PORT_WINDOW,
  phasePort,
  shardForSpec,
  specsForShard,
  ciSpecsForShard,
  extraPhasesForShard,
  phasesForShard,
  shardMatrix,
};
