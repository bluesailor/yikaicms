const assert = require("node:assert/strict");
const test = require("node:test");

global.window = global;
global.addEventListener = function () {};
global.BloxMediaClient = {
    formatDuration(value) {
        return value > 0 ? "0:08" : "";
    },
};
require("../../assets/js/media-library-page.js");

function classes(initial = []) {
    const values = new Set(initial);
    return {
        toggle(name, enabled) {
            if (enabled) values.add(name);
            else values.delete(name);
        },
        has(name) {
            return values.has(name);
        },
    };
}

function fixture() {
    const nodes = {
        "[data-media-video-status]": { hidden: false },
        "[data-media-video-status-text]": { textContent: "" },
        "[data-media-video-status-icon]": { classList: classes(["ti-loader-2", "animate-spin"]) },
        "[data-media-video-duration]": { hidden: true, textContent: "" },
        "[data-media-video-meta]": { hidden: true, textContent: "" },
    };
    const attributes = {};
    const card = {
        querySelector(selector) { return nodes[selector] || null; },
        setAttribute(name, value) { attributes[name] = value; },
    };
    const video = {
        style: {},
        closest() { return card; },
        getAttribute(name) { return name === "data-src" ? "/uploads/videos/demo.mp4" : null; },
    };
    return { video, nodes, attributes };
}

test("video metadata stays compact and omits unknown values", function () {
    assert.equal(global.MediaLibraryPage.formatVideoMeta({ width: 1920, height: 1080, duration: 8.4 }), "1920x1080 · 0:08");
    assert.equal(global.MediaLibraryPage.formatVideoMeta({ width: 0, height: 0, duration: 0 }), "");
});

test("uploads use the image, video, or protected file bucket", function () {
    assert.equal(global.MediaLibraryPage.uploadType({ name: "photo.JPG", type: "" }), "images");
    assert.equal(global.MediaLibraryPage.uploadType({ name: "clip.bin", type: "video/mp4" }), "videos");
    assert.equal(global.MediaLibraryPage.uploadType({ name: "clip.webm", type: "" }), "videos");
    assert.equal(global.MediaLibraryPage.uploadType({ name: "manual.pdf", type: "application/pdf" }), "files");
});

test("video cards expose loading, ready, and unavailable states", function () {
    const current = fixture();
    const labels = { loading: "Loading", unavailable: "Unavailable" };

    global.MediaLibraryPage.updateVideoCard(current.video, { status: "loading", width: 0, height: 0, duration: 0 }, labels);
    assert.equal(current.attributes["data-video-status"], "loading");
    assert.equal(current.nodes["[data-media-video-status-text]"].textContent, "Loading");
    assert.equal(current.video.style.opacity, "0");

    global.MediaLibraryPage.updateVideoCard(current.video, { status: "ready", width: 1280, height: 720, duration: 8.4 }, labels);
    assert.equal(current.nodes["[data-media-video-status]"].hidden, true);
    assert.equal(current.nodes["[data-media-video-duration]"].textContent, "0:08");
    assert.equal(current.nodes["[data-media-video-meta]"].textContent, "1280x720 · 0:08");
    assert.equal(current.video.style.opacity, "1");

    global.MediaLibraryPage.updateVideoCard(current.video, { status: "error", width: 0, height: 0, duration: 0 }, labels);
    assert.equal(current.nodes["[data-media-video-status-text]"].textContent, "Unavailable");
    assert.equal(current.nodes["[data-media-video-status-icon]"].classList.has("ti-alert-circle"), true);
    assert.equal(current.nodes["[data-media-video-status-icon]"].classList.has("animate-spin"), false);
});

test("standalone page reuses the shared bounded preview queue", function () {
    const current = fixture();
    let queueOptions = null;
    let observed = null;
    global.BloxMediaClient.createVideoPreviewQueue = function (options) {
        queueOptions = options;
        return {
            observe(video, url, notify) { observed = { video, url, notify }; },
            reset() {},
        };
    };
    const root = {
        querySelectorAll() { return [current.video]; },
    };

    const queue = global.MediaLibraryPage.initVideoPreviews(root, { loading: "Loading", unavailable: "Unavailable" });
    assert.ok(queue);
    assert.equal(queueOptions.maxConcurrent, 2);
    assert.equal(observed.video, current.video);
    assert.equal(observed.url, "/uploads/videos/demo.mp4");

    observed.notify({ status: "ready", width: 640, height: 360, duration: 8.4 });
    assert.equal(current.nodes["[data-media-video-meta]"].textContent, "640x360 · 0:08");
});
