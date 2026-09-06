'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { SHARD_KEYS, shardForSpec, extraPhasesForShard } = require('./shards');

const root = path.resolve(__dirname);
const counts = Object.fromEntries(SHARD_KEYS.map((key) => [key, 0]));
const errors = [];

for (const file of fs.readdirSync(root).filter((name) => name.endsWith('.spec.js')).sort()) {
  const source = fs.readFileSync(path.join(root, file), 'utf8');
  if (!source.includes('@ci')) continue;
  const shard = shardForSpec(file);
  counts[shard]++;
}

for (const key of SHARD_KEYS) {
  if (counts[key] === 0) errors.push(`Shard ${key} has no @ci spec files`);
}

for (const phase of extraPhasesForShard('locale', root)) {
  if (!fs.existsSync(phase.spec)) {
    errors.push(`Extra phase ${phase.name} is missing ${phase.spec}`);
    continue;
  }
  const source = fs.readFileSync(phase.spec, 'utf8');
  if (!source.includes(phase.grep)) {
    errors.push(`Extra phase ${phase.name} has no ${phase.grep} test marker`);
  }
}

if (errors.length) {
  errors.forEach((error) => console.error(error));
  process.exit(1);
}

console.log(`Browser shard coverage: ${JSON.stringify(counts)}`);
