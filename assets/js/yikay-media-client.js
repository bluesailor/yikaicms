(function (global) {
    "use strict";

    function payload(response) {
        return response.json().then(function (result) {
            return result && typeof result === "object" ? result : {};
        });
    }

    function list(endpoint, page, keyword, options) {
        var requestedType = String((options && options.type) || "image");
        var type = requestedType === "video" ? "video" : "image";
        var url = endpoint + "?action=list&type=" + encodeURIComponent(type) + "&page=" + encodeURIComponent(page);
        var query = String(keyword || "").trim();
        var usage = String((options && options.usage) || "").trim();
        var allowedSorts = ["default", "newest", "oldest", "largest", "smallest", "name"];
        var requestedSort = String((options && options.sort) || "default");
        var sort = allowedSorts.indexOf(requestedSort) !== -1 ? requestedSort : "default";
        if (query) url += "&keyword=" + encodeURIComponent(query);
        if (usage) url += "&usage=" + encodeURIComponent(usage);
        if (sort !== "default") url += "&sort=" + encodeURIComponent(sort);

        return fetch(url, { cache: "no-store" }).then(payload).then(function (result) {
            var data = result.data && typeof result.data === "object" ? result.data : {};
            return {
                ok: Number(result.code) === 0,
                message: String(result.msg || ""),
                items: Array.isArray(data.items) ? data.items : [],
                pages: Math.max(1, Number(data.pages) || 1),
                total: Math.max(0, Number(data.total) || 0),
            };
        });
    }

    function latestRequestGuard() {
        var revision = 0;
        return {
            begin: function () {
                revision += 1;
                return revision;
            },
            invalidate: function () {
                revision += 1;
            },
            isCurrent: function (requestId) {
                return requestId === revision;
            },
        };
    }

    function imageOptions(options) {
        var value = options && typeof options === "object" ? options : {};
        var hasMaxDimension = Object.prototype.hasOwnProperty.call(value, "maxDimension");
        var hasMinBytes = Object.prototype.hasOwnProperty.call(value, "minBytes");
        var hasMaxCanvasPixels = Object.prototype.hasOwnProperty.call(value, "maxCanvasPixels");
        var hasMaxSourceBytes = Object.prototype.hasOwnProperty.call(value, "maxSourceBytes");
        var maxDimension = hasMaxDimension ? Number(value.maxDimension) : 2560;
        var minBytes = hasMinBytes ? Number(value.minBytes) : 512 * 1024;
        var maxCanvasPixels = hasMaxCanvasPixels ? Number(value.maxCanvasPixels) : 16 * 1024 * 1024;
        var maxSourceBytes = hasMaxSourceBytes ? Number(value.maxSourceBytes) : 0;
        return {
            maxDimension: Number.isFinite(maxDimension) && maxDimension === 0
                ? 0
                : Math.max(320, Number.isFinite(maxDimension) ? maxDimension : 2560),
            quality: Math.max(0.5, Math.min(0.95, Number(value.quality) || 0.82)),
            minBytes: Math.max(0, Number.isFinite(minBytes) ? minBytes : 512 * 1024),
            maxCanvasPixels: Number.isFinite(maxCanvasPixels) && maxCanvasPixels === 0
                ? 0
                : Math.max(1, Number.isFinite(maxCanvasPixels) ? maxCanvasPixels : 16 * 1024 * 1024),
            maxSourceBytes: Number.isFinite(maxSourceBytes) && maxSourceBytes === 0
                ? 0
                : Math.max(1, Number.isFinite(maxSourceBytes) ? maxSourceBytes : 0),
        };
    }

    function formatBytes(value) {
        var bytes = Math.max(0, Number(value) || 0);
        if (bytes < 1024) return Math.round(bytes) + " B";

        var units = ["KB", "MB", "GB"];
        var amount = bytes / 1024;
        var index = 0;
        while (amount >= 1024 && index < units.length - 1) {
            amount /= 1024;
            index++;
        }
        var precision = amount < 10 ? 1 : 0;
        return amount.toFixed(precision).replace(/\.0$/, "") + " " + units[index];
    }

    function formatDuration(value) {
        var seconds = Number(value);
        if (!Number.isFinite(seconds) || seconds <= 0) return "";
        var total = Math.max(1, Math.round(seconds));
        var hours = Math.floor(total / 3600);
        var minutes = Math.floor((total % 3600) / 60);
        var remainder = total % 60;
        return hours > 0
            ? hours + ":" + String(minutes).padStart(2, "0") + ":" + String(remainder).padStart(2, "0")
            : minutes + ":" + String(remainder).padStart(2, "0");
    }

    function createVideoPreviewQueue(options) {
        var config = options && typeof options === "object" ? options : {};
        var maxConcurrent = Math.max(1, Math.min(4, Math.floor(Number(config.maxConcurrent) || 2)));
        var timeoutMs = Math.max(1000, Math.min(30000, Math.floor(Number(config.timeoutMs) || 10000)));
        var root = config.root || null;
        var records = [];
        var pending = [];
        var active = 0;
        var generation = 0;
        var observer = null;

        function notify(record, patch) {
            record.state = Object.assign({}, record.state, patch || {});
            if (record.generation === generation && typeof record.notify === "function") {
                record.notify(Object.assign({}, record.state));
            }
        }

        function detach(record) {
            if (!record.listeners) return;
            Object.keys(record.listeners).forEach(function (event) {
                record.video.removeEventListener(event, record.listeners[event]);
            });
            record.listeners = null;
            if (record.timer) {
                global.clearTimeout(record.timer);
                record.timer = null;
            }
        }

        function unload(record) {
            var video = record.video;
            if (!video || typeof video.removeAttribute !== "function") return;
            if (video.getAttribute("src")) {
                video.removeAttribute("src");
                try { if (typeof video.load === "function") video.load(); } catch (error) { /* 已在销毁，忽略解码器异常。 */ }
            }
        }

        function finish(record, status) {
            if (record.done || record.generation !== generation) return;
            record.done = true;
            detach(record);
            notify(record, { status: status });
            active = Math.max(0, active - 1);
            drain();
        }

        function start(record) {
            if (record.done || record.started || record.generation !== generation) return;
            record.started = true;
            active += 1;
            notify(record, { status: "loading" });

            var video = record.video;
            record.listeners = {
                loadedmetadata: function () {
                    var width = Math.max(0, Number(video.videoWidth) || 0);
                    var height = Math.max(0, Number(video.videoHeight) || 0);
                    var duration = Number.isFinite(Number(video.duration)) ? Math.max(0, Number(video.duration)) : 0;
                    notify(record, { width: width, height: height, duration: duration });
                    if (duration > 0.2 && Number(video.currentTime || 0) === 0) {
                        try { video.currentTime = Math.min(0.1, duration / 2); } catch (error) { /* 首帧仍可由 loadeddata 提供。 */ }
                    }
                },
                loadeddata: function () {
                    notify(record, {
                        width: Math.max(0, Number(video.videoWidth) || record.state.width || 0),
                        height: Math.max(0, Number(video.videoHeight) || record.state.height || 0),
                        duration: Number.isFinite(Number(video.duration)) ? Math.max(0, Number(video.duration)) : record.state.duration,
                    });
                    finish(record, "ready");
                },
                error: function () { finish(record, "error"); },
            };
            Object.keys(record.listeners).forEach(function (event) {
                video.addEventListener(event, record.listeners[event]);
            });
            record.timer = global.setTimeout(function () { finish(record, "error"); }, timeoutMs);

            try {
                video.preload = "metadata";
                video.muted = true;
                video.playsInline = true;
                video.setAttribute("src", record.url);
                if (typeof video.load === "function") video.load();
            } catch (error) {
                finish(record, "error");
            }
        }

        function drain() {
            while (active < maxConcurrent && pending.length > 0) {
                var record = pending.shift();
                if (record && !record.done && !record.started && record.generation === generation) start(record);
            }
        }

        function enqueue(record) {
            if (record.done || record.started || record.queued || record.generation !== generation) return;
            record.queued = true;
            pending.push(record);
            drain();
        }

        function ensureObserver() {
            if (observer || typeof global.IntersectionObserver !== "function") return observer;
            observer = new global.IntersectionObserver(function (entries) {
                entries.forEach(function (entry) {
                    if (!entry.isIntersecting) return;
                    var record = records.find(function (candidate) { return candidate.video === entry.target; });
                    if (!record) return;
                    observer.unobserve(entry.target);
                    enqueue(record);
                });
            }, { root: root, rootMargin: "120px 0px" });
            return observer;
        }

        function observe(video, url, notifyState) {
            var normalizedUrl = String(url || "").trim();
            if (!video || !normalizedUrl || typeof video.addEventListener !== "function") return false;
            var record = {
                video: video,
                url: normalizedUrl,
                notify: notifyState,
                generation: generation,
                state: { status: "idle", width: 0, height: 0, duration: 0 },
                queued: false,
                started: false,
                done: false,
                listeners: null,
                timer: null,
            };
            records.push(record);
            notify(record, record.state);
            var currentObserver = ensureObserver();
            if (currentObserver) currentObserver.observe(video);
            else enqueue(record);
            return true;
        }

        function reset() {
            generation += 1;
            if (observer) observer.disconnect();
            observer = null;
            records.forEach(function (record) {
                detach(record);
                unload(record);
            });
            records = [];
            pending = [];
            active = 0;
        }

        return { observe: observe, reset: reset };
    }

    var mimeExtensions = {
        "image/jpeg": ["jpg", "jpeg"],
        "image/png": ["png"],
        "image/webp": ["webp"],
        "image/gif": ["gif"],
    };

    function imageName(file, fallback, outputType) {
        var name = String((file && file.name) || fallback || "upload");
        var type = String(outputType || (file && file.type) || "").toLowerCase();
        var allowed = mimeExtensions[type] || [];
        var match = name.match(/\.([a-z0-9]+)$/i);
        if (match && (allowed.length === 0 || allowed.indexOf(match[1].toLowerCase()) !== -1)) return name;

        var extension = allowed[0] || "jpg";
        return match ? name.slice(0, -match[0].length) + "." + extension : name + "." + extension;
    }

    function decodeImageElement(file) {
        return new Promise(function (resolve, reject) {
            if (typeof global.Image !== "function" || !global.URL || typeof global.URL.createObjectURL !== "function") {
                reject(new Error("Image decoding is unavailable"));
                return;
            }
            var url = global.URL.createObjectURL(file);
            var image = new global.Image();
            image.onload = function () {
                resolve({
                    source: image,
                    width: image.naturalWidth || image.width,
                    height: image.naturalHeight || image.height,
                    cleanup: function () { global.URL.revokeObjectURL(url); },
                });
            };
            image.onerror = function () {
                global.URL.revokeObjectURL(url);
                reject(new Error("Image decoding failed"));
            };
            image.src = url;
        });
    }

    function decodeImage(file) {
        if (typeof global.createImageBitmap !== "function") return decodeImageElement(file);

        return Promise.resolve().then(function () {
            return global.createImageBitmap(file);
        }).then(function (bitmap) {
            return {
                source: bitmap,
                width: bitmap.width,
                height: bitmap.height,
                cleanup: function () { if (typeof bitmap.close === "function") bitmap.close(); },
            };
        }).catch(function () {
            // Safari and older Chromium builds can expose createImageBitmap while
            // rejecting image variants that the regular image decoder accepts.
            return decodeImageElement(file);
        });
    }

    function canvasBlob(canvas, type, quality) {
        return new Promise(function (resolve, reject) {
            canvas.toBlob(function (blob) {
                if (blob) resolve(blob);
                else reject(new Error("Image encoding failed"));
            }, type, quality);
        });
    }

    function prepareImage(file, options) {
        var type = String((file && file.type) || "").toLowerCase();
        if (!file || ["image/jpeg", "image/png", "image/webp"].indexOf(type) === -1) {
            return Promise.resolve(file);
        }

        var config = imageOptions(options);
        if (config.maxSourceBytes > 0 && Number(file.size || 0) > config.maxSourceBytes) {
            return Promise.resolve(file);
        }

        return decodeImage(file).then(function (decoded) {
            var maxSide = Math.max(decoded.width, decoded.height);
            var sourcePixels = decoded.width * decoded.height;
            if (!Number.isFinite(sourcePixels) || sourcePixels <= 0 || maxSide <= 0) {
                decoded.cleanup();
                return file;
            }

            var dimensionScale = config.maxDimension > 0 && maxSide > config.maxDimension
                ? config.maxDimension / maxSide
                : 1;
            var pixelScale = config.maxCanvasPixels > 0 && sourcePixels > config.maxCanvasPixels
                ? Math.sqrt(config.maxCanvasPixels / sourcePixels)
                : 1;
            var scale = Math.min(dimensionScale, pixelScale);
            var shouldResize = scale < 1;
            var shouldCompress = type !== "image/png" && Number(file.size || 0) > config.minBytes;
            if (!shouldResize && !shouldCompress) {
                decoded.cleanup();
                return file;
            }

            var width = Math.max(1, Math.floor(decoded.width * scale));
            var height = Math.max(1, Math.floor(decoded.height * scale));
            var canvas;
            var context;
            try {
                canvas = global.document.createElement("canvas");
                canvas.width = width;
                canvas.height = height;
                context = canvas.getContext("2d");
                if (!context) {
                    decoded.cleanup();
                    return file;
                }
                context.drawImage(decoded.source, 0, 0, width, height);
            } catch (error) {
                decoded.cleanup();
                throw error;
            }

            return canvasBlob(canvas, type, config.quality).then(function (blob) {
                decoded.cleanup();
                if (!shouldResize && blob.size >= Number(file.size || 0)) return file;
                return blob;
            }, function (error) {
                decoded.cleanup();
                throw error;
            });
        }).catch(function () {
            return file;
        });
    }

    function upload(endpoint, file, options) {
        var config = options && typeof options === "object" ? options : {};
        var type = config.type === "video" ? "video" : "image";
        var maxBytes = Math.max(0, Number(config.maxBytes) || 0);
        var originalBytes = Math.max(0, Number(file && file.size) || 0);
        if (maxBytes > 0 && originalBytes > maxBytes) {
            return Promise.resolve({
                ok: false,
                message: "",
                url: "",
                optimized: false,
                originalBytes: originalBytes,
                uploadBytes: 0,
                error: "too_large",
                limitBytes: maxBytes,
            });
        }
        var preparation = type === "video" ? Promise.resolve(file) : prepareImage(file, config);
        return preparation.then(function (prepared) {
            var body = new FormData();
            var filename = type === "video"
                ? String(config.filename || (file && file.name) || "banner.mp4")
                : imageName(file, config.filename, prepared && prepared.type);
            body.append("file", prepared, filename);
            body.append("type", type === "video" ? "videos" : "images");
            if (config.csrf) body.append("_token", String(config.csrf));

            return fetch(endpoint + "?action=upload", { method: "POST", body: body })
                .then(payload)
                .then(function (result) {
                    var data = result.data && typeof result.data === "object" ? result.data : {};
                    return {
                        ok: Number(result.code) === 0 && typeof data.url === "string" && data.url !== "",
                        message: String(result.msg || ""),
                        url: String(data.url || ""),
                        optimized: prepared !== file,
                        originalBytes: Math.max(0, Number(file && file.size) || 0),
                        uploadBytes: Math.max(0, Number(prepared && prepared.size) || 0),
                    };
                });
        });
    }

    global.BloxMediaClient = {
        list: list,
        latestRequestGuard: latestRequestGuard,
        formatBytes: formatBytes,
        formatDuration: formatDuration,
        createVideoPreviewQueue: createVideoPreviewQueue,
        prepareImage: prepareImage,
        upload: upload,
    };
})(window);
