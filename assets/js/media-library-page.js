(function (global) {
    "use strict";

    function formatVideoMeta(state) {
        var values = [];
        var width = Math.max(0, Number(state && state.width) || 0);
        var height = Math.max(0, Number(state && state.height) || 0);
        var duration = Math.max(0, Number(state && state.duration) || 0);
        if (width > 0 && height > 0) values.push(Math.round(width) + "x" + Math.round(height));
        if (duration > 0 && global.BloxMediaClient) {
            values.push(global.BloxMediaClient.formatDuration(duration));
        }
        return values.join(" · ");
    }

    function uploadType(file) {
        var mime = String((file && file.type) || "").toLowerCase();
        var name = String((file && file.name) || "").toLowerCase();
        if (mime.indexOf("image/") === 0 || /\.(jpe?g|png|gif|webp|svg)$/.test(name)) return "images";
        if (mime.indexOf("video/") === 0 || /\.(mp4|webm|ogg|ogv|mov|m4v)$/.test(name)) return "videos";
        return "files";
    }

    function updateVideoCard(video, state, labels) {
        var card = typeof video.closest === "function" ? video.closest("[data-media-card]") : null;
        if (!card) return;

        var status = card.querySelector("[data-media-video-status]");
        var statusText = card.querySelector("[data-media-video-status-text]");
        var statusIcon = card.querySelector("[data-media-video-status-icon]");
        var duration = card.querySelector("[data-media-video-duration]");
        var meta = card.querySelector("[data-media-video-meta]");
        var ready = state.status === "ready";
        var failed = state.status === "error";

        card.setAttribute("data-video-status", state.status || "idle");
        video.style.opacity = ready ? "1" : "0";
        if (status) status.hidden = ready;
        if (statusText) statusText.textContent = failed ? labels.unavailable : labels.loading;
        if (statusIcon) {
            statusIcon.classList.toggle("ti-loader-2", !failed);
            statusIcon.classList.toggle("animate-spin", !failed);
            statusIcon.classList.toggle("ti-alert-circle", failed);
            statusIcon.classList.toggle("text-amber-300", failed);
        }

        var durationText = global.BloxMediaClient
            ? global.BloxMediaClient.formatDuration(state.duration)
            : "";
        if (duration) {
            duration.textContent = durationText;
            duration.hidden = !ready || durationText === "";
        }
        if (meta) {
            var value = formatVideoMeta(state);
            meta.textContent = value;
            meta.hidden = value === "";
        }
    }

    function initVideoPreviews(root, options) {
        if (!root || !global.BloxMediaClient) return null;
        var config = options && typeof options === "object" ? options : {};
        var labels = {
            loading: String(config.loading || ""),
            unavailable: String(config.unavailable || ""),
        };
        var queue = global.BloxMediaClient.createVideoPreviewQueue({
            root: null,
            maxConcurrent: 2,
        });
        root.querySelectorAll("[data-media-video-preview]").forEach(function (video) {
            queue.observe(video, video.getAttribute("data-src"), function (state) {
                updateVideoCard(video, state, labels);
            });
        });
        global.addEventListener("pagehide", function () { queue.reset(); }, { once: true });
        return queue;
    }

    global.MediaLibraryPage = {
        formatVideoMeta: formatVideoMeta,
        uploadType: uploadType,
        updateVideoCard: updateVideoCard,
        initVideoPreviews: initVideoPreviews,
    };
})(window);
