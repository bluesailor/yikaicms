/*
 * 后台上传前在浏览器里先压缩图片（v2.0.5，借鉴 WordPress 7.1）。
 *
 * 媒体库与构建器早就走 BloxMediaClient.prepareImage（最长边 2560、质量 0.82、小于 512KB 不动），
 * 但产品图、相册、横幅、编辑器插图等三十来处上传各自拼 FormData 直接 POST 原图——手机照片
 * 5–10MB，在共享主机上容易超内存、超时。这里与 CSRF 补丁同一个思路「在源头一次根治」：
 *
 * - 拦截 POST 到 /admin/upload.php 的 fetch：表单里的 JPEG / PNG / WebP 先压缩再发，其余字段原样保留；
 * - 相册这类自己发 XHR 的页面调用 YkUploadPrep.prepareAll(files)。
 *
 * 任何一步失败都原样上传（不阻断）；GIF、SVG 等不处理；服务端照旧校验。
 */
(function (global) {
    "use strict";

    var IMAGE_TYPES = ["image/jpeg", "image/png", "image/webp"];

    function isImageFile(value) {
        return typeof Blob !== "undefined" && value instanceof Blob
            && IMAGE_TYPES.indexOf(String(value.type || "").toLowerCase()) !== -1;
    }

    /** 单个文件：能压就压，失败或不支持返回原文件 */
    function prepare(file) {
        var client = global.BloxMediaClient;
        if (!client || typeof client.prepareImage !== "function" || !isImageFile(file)) {
            return Promise.resolve(file);
        }
        return client.prepareImage(file).then(function (prepared) {
            return prepared || file;
        }, function () {
            return file;
        });
    }

    /** 多个文件逐个处理（不并发，避免一次解码多张大图占满内存），保持顺序 */
    function prepareAll(files) {
        var list = Array.prototype.slice.call(files || []);
        var out = [];
        return list.reduce(function (chain, file) {
            return chain.then(function () {
                return prepare(file).then(function (prepared) { out.push(prepared); });
            });
        }, Promise.resolve()).then(function () { return out; });
    }

    /** 原文件名（压缩结果是 Blob，没有文件名；服务端按扩展名校验，必须带上） */
    function fileName(original) {
        return original && typeof original.name === "string" && original.name !== "" ? original.name : "image";
    }

    /** 表单里有图片就重建一份（字段顺序与其余内容不变）；没有图片返回 null */
    function prepareFormData(formData) {
        if (typeof FormData === "undefined" || !(formData instanceof FormData) || typeof formData.entries !== "function") {
            return Promise.resolve(null);
        }
        var entries = [];
        var hasImage = false;
        var iterator = formData.entries();
        for (var step = iterator.next(); !step.done; step = iterator.next()) {
            entries.push(step.value);
            if (isImageFile(step.value[1])) hasImage = true;
        }
        if (!hasImage) return Promise.resolve(null);

        var prepared = [];
        return entries.reduce(function (chain, entry) {
            return chain.then(function () {
                if (!isImageFile(entry[1])) {
                    prepared.push([entry[0], entry[1]]);
                    return null;
                }
                return prepare(entry[1]).then(function (blob) {
                    prepared.push([entry[0], blob, fileName(entry[1])]);
                });
            });
        }, Promise.resolve()).then(function () {
            var rebuilt = new FormData();
            prepared.forEach(function (item) {
                if (item.length === 3) rebuilt.append(item[0], item[1], item[2]);
                else rebuilt.append(item[0], item[1]);
            });
            return rebuilt;
        });
    }

    function isUploadEndpoint(input) {
        var url = typeof input === "string" ? input : ((input && input.url) || "");
        var path = String(url).split("#")[0].split("?")[0];
        return /\/admin\/upload\.php$/.test(path);
    }

    if (typeof global.fetch === "function" && !global.fetch.ykUploadPrep) {
        var originalFetch = global.fetch;
        var wrapped = function (input, init) {
            var options = init || {};
            var method = String(options.method || (input && input.method) || "GET").toUpperCase();
            if (method !== "POST" || !isUploadEndpoint(input) || !(options.body instanceof FormData)) {
                return originalFetch.call(this, input, init);
            }
            var self = this;
            return prepareFormData(options.body).then(function (rebuilt) {
                if (!rebuilt) return originalFetch.call(self, input, init);
                var next = {};
                Object.keys(options).forEach(function (key) { next[key] = options[key]; });
                next.body = rebuilt;
                return originalFetch.call(self, input, next);
            }, function () {
                return originalFetch.call(self, input, init);
            });
        };
        wrapped.ykUploadPrep = true;
        global.fetch = wrapped;
    }

    global.YkUploadPrep = {
        prepare: prepare,
        prepareAll: prepareAll,
        prepareFormData: prepareFormData,
        isUploadEndpoint: isUploadEndpoint,
    };
})(window);
