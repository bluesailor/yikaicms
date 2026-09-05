'use strict';

const SHARDS = Object.freeze({
  core: Object.freeze({
    key: 'core',
    label: 'Editor core and publishing',
    port: 8081,
  }),
  media: Object.freeze({
    key: 'media',
    label: 'Banner, media and video',
    port: 8082,
  }),
  design: Object.freeze({
    key: 'design',
    label: 'Templates, areas and design system',
    port: 8083,
  }),
  locale: Object.freeze({
    key: 'locale',
    label: 'Languages, free mode and responsive',
    port: 8084,
  }),
});

const SHARD_KEYS = Object.freeze(Object.keys(SHARDS));

function shardMatrix(keys = SHARD_KEYS) {
  return {
    include: keys.map((key) => {
      const shard = SHARDS[key];
      if (!shard) throw new Error(`Unknown browser shard: ${key}`);
      return { shard: shard.key, label: shard.label, port: shard.port };
    }),
  };
}

module.exports = { SHARDS, SHARD_KEYS, shardMatrix };
