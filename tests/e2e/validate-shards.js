'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { SHARD_KEYS } = require('./shards');

const CI_MARKER = '@ci';
const SHARD_PATTERN = /@shard-([a-z-]+)/g;

function validateSpecs(root = __dirname) {
  const counts = Object.fromEntries(SHARD_KEYS.map((key) => [key, 0]));
  const errors = [];
  const files = fs.readdirSync(root)
    .filter((name) => name.endsWith('.spec.js'))
    .sort();

  for (const file of files) {
    const lines = fs.readFileSync(path.join(root, file), 'utf8').split(/\r?\n/);
    lines.forEach((line, index) => {
      if (!line.includes(CI_MARKER)) return;
      const tags = [...line.matchAll(SHARD_PATTERN)].map((match) => match[1]);
      if (tags.length !== 1) {
        errors.push(`${file}:${index + 1} must contain exactly one @shard-* tag`);
        return;
      }
      if (!Object.hasOwn(counts, tags[0])) {
        errors.push(`${file}:${index + 1} uses unknown shard ${tags[0]}`);
        return;
      }
      counts[tags[0]] += 1;
    });
  }

  for (const key of SHARD_KEYS) {
    if (counts[key] === 0) errors.push(`Shard ${key} has no @ci tests`);
  }
  return { counts, errors };
}

if (require.main === module) {
  const result = validateSpecs();
  if (result.errors.length > 0) {
    for (const error of result.errors) console.error(error);
    process.exitCode = 1;
  } else {
    console.log(`Browser shard coverage: ${JSON.stringify(result.counts)}`);
  }
}

module.exports = { validateSpecs };
