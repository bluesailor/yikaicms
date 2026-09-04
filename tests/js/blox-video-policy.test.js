const test = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const SRC = fs.readFileSync(path.join(__dirname, '..', '..', 'assets', 'js', 'blox-video-policy.js'), 'utf8');

function run({ mobile = false, reduced = false, saveData = false } = {}) {
    const window = {
        navigator: { connection: { saveData } },
        matchMedia(query) {
            return { matches: query.includes('max-width') ? mobile : reduced };
        },
    };
    vm.runInNewContext(SRC, { window });
    return window.BloxVideoPolicy;
}

function video(mode = 'poster') {
    return {
        getAttribute(name) {
            return name === 'data-blox-mobile-video' ? mode : null;
        },
    };
}

test('desktop playback is allowed by default', () => {
    assert.equal(run().allowsPlayback(video()), true);
});

test('reduced motion and save-data block playback', () => {
    assert.equal(run({ reduced: true }).allowsPlayback(video('video')), false);
    assert.equal(run({ saveData: true }).allowsPlayback(video('video')), false);
});

test('mobile poster mode is blocked while mobile video mode is allowed', () => {
    const policy = run({ mobile: true });
    assert.equal(policy.allowsPlayback(video('poster')), false);
    assert.equal(policy.allowsPlayback(video('video')), true);
});

test('an explicit reduced-motion value overrides the ambient preference', () => {
    const policy = run({ reduced: true });
    assert.equal(policy.allowsPlayback(video('video'), { reduceMotion: false }), true);
    assert.equal(policy.allowsPlayback(video('video'), { reduceMotion: true }), false);
});

test('invalid media nodes fail closed', () => {
    assert.equal(run().allowsPlayback(null), false);
    assert.equal(run().allowsPlayback({}), false);
});
