(function (global) {
    "use strict";

    function payload(response) {
        return response.json().then(function (result) {
            return result && typeof result === "object" ? result : {};
        });
    }

    function list(endpoint, page, keyword) {
        var url = endpoint + "?action=list&type=image&page=" + encodeURIComponent(page);
        var query = String(keyword || "").trim();
        if (query) url += "&keyword=" + encodeURIComponent(query);

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
        return prepareImage(file, config).then(function (prepared) {
            var body = new FormData();
            body.append("file", prepared, imageName(file, config.filename, prepared && prepared.type));
            body.append("type", "images");
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
        formatBytes: formatBytes,
        prepareImage: prepareImage,
        upload: upload,
    };
})(window);
