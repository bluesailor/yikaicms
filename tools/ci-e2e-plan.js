#!/usr/bin/env node
'use strict';

const fs = require('node:fs');
const path = require('node:path');
const { SHARD_KEYS, shardMatrix } = require('../tests/e2e/shards');

const ALL = new Set(SHARD_KEYS);

function normalize(file) {
  return String(file || '').trim().replaceAll('\\', '/').replace(/^\.\//, '');
}

function specShards(file, root) {
  const fullPath = path.join(root, file);
  if (!fs.existsSync(fullPath)) return new Set();
  const source = fs.readFileSync(fullPath, 'utf8');
  return new Set([...source.matchAll(/@shard-(core|media|design|locale)\b/g)].map((match) => match[1]));
}

function shardsForPath(input, root = path.resolve(__dirname, '..')) {
  const file = normalize(input);
  if (!file) return new Set();
  if (/^(?:docs\/|README|LICENSE|\.agents\/)/i.test(file) || /\.md$/i.test(file)) return new Set();

  if (/^tests\/e2e\/[^/]+\.spec\.js$/i.test(file)) {
    const tags = specShards(file, root);
    if (tags.size > 0) return tags;
    if (/blox-home-language/i.test(file)) return new Set(['locale']);
    return new Set(['core']);
  }

  if (/^(?:\.github\/workflows\/ci\.yml|\.github\/scripts\/(?:inject-blox|install-playwright-chromium)\.sh|playwright\.config\.js|package(?:-lock)?\.json|tests\/e2e\/(?:helpers|global-setup|isolated-site|router|run-local|run-shard|shards|validate-shards)\.js|tests\/smoke\/(?:setup|fixtures)\.php|tools\/ci-e2e-plan\.js)$/i.test(file)) {
    return new Set(ALL);
  }
  if (/^(?:install\/sql\/|migrations\/|config\/blox-assets\.json|assets\/css\/)/i.test(file)
      || /^includes\/(?:init|functions|security)\.php$/i.test(file)) {
    return new Set(ALL);
  }

  if (/^(?:admin\/(?:media(?:_api)?|upload|banner)\.php|admin\/blox_editor\/partials\/(?:banner|video)|includes\/models\/(?:Media|Banner)Model\.php|includes\/(?:BundledMediaLibrary|Media|RemoteOfficialMedia|ResponsiveImage)|includes\/commands\/media\.php|includes\/blocks\/banner\.php|includes\/builder\/elements\/(?:Banner|HomeBannerItem|Video)Element\.php|assets\/js\/(?:blox-(?:background-video|banner|media|video)|media-library|official-media)|(?:marketplace\/)?themes\/[^/]+\/blocks\/banner\.php)/i.test(file)) {
    return new Set(['media']);
  }
  if (/^(?:admin\/(?:blox_templates|blox_template_api|blox_design|site_design|theme)\.php|includes\/(?:Theme|builder\/Blox(?:Area|Design|Header|Template|ThemeHeader))|marketplace\/themes\/|themes\/)/i.test(file)) {
    return new Set(['design']);
  }
  if (/^(?:lang\/|includes\/(?:i18n|language|HomeSettingsLanguageDefaults)|includes\/builder\/(?:BloxAreaLanguageManager|BloxResponsiveValue)\.php|includes\/builder\/elements\/LanguageSwitcherElement\.php|admin\/role\.php|admin\/blox_templates\/partials\/language-areas\.php|assets\/js\/blox-(?:language-switcher|responsive)\.js|tests\/e2e\/blox-home-language\.spec\.js|deploy\/)/i.test(file)) {
    return new Set(['locale']);
  }
  if (/^(?:admin\/blox_editor|admin\/blox_(?:home|page|preview)_api|includes\/builder\/|assets\/js\/blox-|admin\/|controllers\/|includes\/)/i.test(file)) {
    return new Set(['core']);
  }

  if (/^(?:tests\/Unit\/|tests\/Models\/|tests\/Controllers\/|tests\/js\/|tools\/)/i.test(file)) return new Set();
  if (/\.(?:php|js|json)$/i.test(file)) return new Set(['core']);
  return new Set();
}

function plan(files, options = {}) {
  if (options.full) return SHARD_KEYS.slice();
  const selected = new Set();
  for (const file of files) {
    for (const shard of shardsForPath(file, options.root)) selected.add(shard);
  }
  return SHARD_KEYS.filter((key) => selected.has(key));
}

function parseArgs(args) {
  const options = { full: false, filesFile: '' };
  for (const arg of args) {
    if (arg === '--full') options.full = true;
    else if (arg.startsWith('--files-file=')) options.filesFile = arg.slice('--files-file='.length);
    else throw new Error(`Unknown argument: ${arg}`);
  }
  return options;
}

function writeOutputs(keys) {
  const matrix = JSON.stringify(shardMatrix(keys));
  const hasShards = keys.length > 0 ? 'true' : 'false';
  const summary = keys.length > 0 ? keys.join(', ') : 'none';
  if (process.env.GITHUB_OUTPUT) {
    fs.appendFileSync(process.env.GITHUB_OUTPUT, `matrix=${matrix}\nhas_shards=${hasShards}\nsummary=${summary}\n`);
  }
  console.log(JSON.stringify({ matrix: JSON.parse(matrix), has_shards: hasShards, summary }));
}

if (require.main === module) {
  try {
    const options = parseArgs(process.argv.slice(2));
    const files = options.filesFile
      ? fs.readFileSync(options.filesFile, 'utf8').split(/\r?\n/).filter(Boolean)
      : [];
    writeOutputs(plan(files, options));
  } catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exitCode = 2;
  }
}

module.exports = { normalize, plan, shardsForPath };
