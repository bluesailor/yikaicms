(function (global) {
    "use strict";

    function BloxPreviewClient(options) {
        this.endpoint = options.endpoint;
        this.csrf = options.csrf;
        this.delay = typeof options.delay === "number" ? options.delay : 400;
        this.getFrame = options.getFrame;
        this.getHost = options.getHost;
        this.getDocument = options.getDocument;
        this.getParams = options.getParams || function () { return {}; };
        this.setLoading = options.setLoading || function () {};
        this.onLoaded = options.onLoaded || function () {};
        this.onError = options.onError || function () {};
        this.fetch = options.fetch || global.fetch.bind(global);
        this.parseHtml = options.parseHtml || function (html) {
            if (typeof global.DOMParser !== "function") return null;
            return new global.DOMParser().parseFromString(html, "text/html");
        };
        this.timer = null;
        this.controller = null;
        this.sequence = 0;
        // srcdoc 重建期间宿主容器会因内容塌陷被浏览器夹到 scrollTop=0；
        // 此窗口内到达的新响应若直接采样会拿到假 0（CI r16-r17 五轮实证）。
        this.rebuilding = false;
        this.lastGoodScroll = null;
    }

    BloxPreviewClient.prototype.schedule = function () {
        var self = this;
        clearTimeout(this.timer);
        this.timer = setTimeout(function () {
            self.timer = null;
            self.refresh();
        }, this.delay);
    };

    BloxPreviewClient.prototype.captureScroll = function (frame) {
        var host = this.getHost();
        var state = {
            hostLeft: host ? host.scrollLeft : 0,
            hostTop: host ? host.scrollTop : 0,
            frameLeft: 0,
            frameTop: 0,
        };
        try {
            if (frame && frame.contentWindow) {
                state.frameLeft = frame.contentWindow.scrollX || 0;
                state.frameTop = frame.contentWindow.scrollY || 0;
            }
        } catch (error) {}
        if (this.rebuilding && this.lastGoodScroll) {
            // 重建窗口内的读数不可信：用最后一次稳定采样兜底（取较大者，
            // 用户在窗口内继续向下滚动时以用户位置为准）。
            state.hostLeft = Math.max(state.hostLeft, this.lastGoodScroll.hostLeft);
            state.hostTop = Math.max(state.hostTop, this.lastGoodScroll.hostTop);
            state.frameLeft = Math.max(state.frameLeft, this.lastGoodScroll.frameLeft);
            state.frameTop = Math.max(state.frameTop, this.lastGoodScroll.frameTop);
        } else {
            this.lastGoodScroll = {
                hostLeft: state.hostLeft,
                hostTop: state.hostTop,
                frameLeft: state.frameLeft,
                frameTop: state.frameTop,
            };
        }
        return state;
    };

    BloxPreviewClient.prototype.restoreScroll = function (frame, state) {
        if (!state) return;
        var host = this.getHost();
        if (host) {
            // 终审裁决：恢复目标取 max(捕获, 当前)——用户在请求在途时继续向下
            // 滚动则保留用户位置，绝不把人拉回顶部；新内容加载后当前为 0 时
            // 回到捕获位置。
            host.scrollLeft = Math.max(state.hostLeft, host.scrollLeft);
            host.scrollTop = Math.max(state.hostTop, host.scrollTop);
        }
        try {
            if (frame && frame.contentWindow) {
                var root = frame.contentDocument && frame.contentDocument.documentElement;
                var behavior = root ? root.style.scrollBehavior : "";
                // Restoring a preview is not navigation: ignore the page's smooth-scroll CSS.
                if (root) root.style.scrollBehavior = "auto";
                try {
                    frame.contentWindow.scrollTo(
                        Math.max(state.frameLeft, frame.contentWindow.scrollX || 0),
                        Math.max(state.frameTop, frame.contentWindow.scrollY || 0)
                    );
                } finally {
                    if (root) root.style.scrollBehavior = behavior;
                }
            }
        } catch (error) {}
    };

    BloxPreviewClient.prototype.documentSignature = function (doc) {
        if (!doc || !doc.head || !doc.body) return null;
        var head = Array.prototype.map.call(doc.head.children || [], function (node) {
            return node.outerHTML || "";
        }).join("\n");
        var body = Array.prototype.filter.call(doc.body.children || [], function (node) {
            if (node.hasAttribute && node.hasAttribute("data-yk-sec")) return false;
            if (!node.classList) return true;
            return !node.classList.contains("yk-pick-overlay")
                && !node.classList.contains("yk-pick-label")
                && !node.classList.contains("yk-drop-line");
        }).map(function (node) {
            return node.outerHTML || "";
        }).join("\n");
        return head + "\n---body---\n" + body;
    };

    BloxPreviewClient.prototype.directSections = function (doc) {
        if (!doc || !doc.body) return [];
        return Array.prototype.filter.call(doc.body.children || [], function (node) {
            return node.hasAttribute && node.hasAttribute("data-yk-sec");
        });
    };

    BloxPreviewClient.prototype.patchFrame = function (frame, html) {
        var currentDoc = frame && frame.contentDocument;
        var nextDoc = this.parseHtml(html);
        if (!currentDoc || !currentDoc.body || !nextDoc || !nextDoc.body) return false;
        if (this.documentSignature(currentDoc) !== this.documentSignature(nextDoc)) return false;

        var currentSections = this.directSections(currentDoc);
        var nextSections = this.directSections(nextDoc);
        var currentByIndex = Object.create(null);
        var nextIndexes = Object.create(null);
        var valid = true;

        currentSections.forEach(function (section) {
            var index = section.getAttribute("data-yk-sec");
            if (index === null || currentByIndex[index]) valid = false;
            currentByIndex[index] = section;
        });
        nextSections.forEach(function (section) {
            var index = section.getAttribute("data-yk-sec");
            if (index === null || nextIndexes[index]) valid = false;
            nextIndexes[index] = true;
        });
        if (!valid) return false;

        var body = currentDoc.body;
        // 追加锚点：新 section 必须落在最后一个现有 section 之后。
        // 不能取「第一个非 section 子节点」——1.18 起画布 body 首位是头部区域壳
        // （.yk-blox-header），旧取法会把新增 section 插到页面最顶端（页头之上），
        // 直到下次整载才归位；连锁把画布滚动清零（r16-r17 五轮 CI 误诊为恢复时序）。
        var anchor = currentSections.length
            ? currentSections[currentSections.length - 1].nextSibling
            : (Array.prototype.find.call(body.children || [], function (node) {
                return !(node.hasAttribute && node.hasAttribute("data-yk-sec"))
                    && !(node.classList && node.classList.contains("yk-blox-header"));
            }) || null);
        var ordered = [];
        var changed = false;

        nextSections.forEach(function (nextSection) {
            var index = nextSection.getAttribute("data-yk-sec");
            var currentSection = currentByIndex[index] || null;
            var nextHtml = nextSection.outerHTML;
            var currentHtml = currentSection && (currentSection.__bloxSourceHtml || currentSection.outerHTML);
            if (currentSection && currentHtml === nextHtml) {
                currentSection.__bloxSourceHtml = nextHtml;
                ordered.push(currentSection);
                return;
            }
            var replacement = currentDoc.importNode(nextSection, true);
            replacement.__bloxSourceHtml = nextHtml;
            if (currentSection) body.replaceChild(replacement, currentSection);
            ordered.push(replacement);
            changed = true;
        });

        currentSections.forEach(function (section) {
            var index = section.getAttribute("data-yk-sec");
            if (!nextIndexes[index] && section.parentNode === body) {
                body.removeChild(section);
                changed = true;
            }
        });
        ordered.forEach(function (section, index) {
            var currentOrder = this.directSections(currentDoc);
            var atIndex = currentOrder[index] || anchor;
            if (atIndex !== section) body.insertBefore(section, atIndex);
        }, this);

        if (changed && currentDoc.dispatchEvent && currentDoc.defaultView) {
            currentDoc.dispatchEvent(new currentDoc.defaultView.CustomEvent("blox:content-updated", {
                detail: { root: currentDoc },
            }));
        }
        return true;
    };

    BloxPreviewClient.prototype.rememberSections = function (frame, html) {
        var currentDoc = frame && frame.contentDocument;
        var sourceDoc = this.parseHtml(html);
        if (!currentDoc || !sourceDoc) return;
        var sourceByIndex = Object.create(null);
        this.directSections(sourceDoc).forEach(function (section) {
            sourceByIndex[section.getAttribute("data-yk-sec")] = section.outerHTML;
        });
        this.directSections(currentDoc).forEach(function (section) {
            var source = sourceByIndex[section.getAttribute("data-yk-sec")];
            if (source) section.__bloxSourceHtml = source;
        });
    };

    BloxPreviewClient.prototype.finishUpdate = function (frame, state) {
        var self = this;
        this.restoreScroll(frame, state);
        this.onLoaded();
        var raf = global.requestAnimationFrame || function (callback) { setTimeout(callback, 0); };
        raf(function () {
            self.restoreScroll(frame, state);
            // 重建结束、滚动已恢复：本刻位置即新的稳定基准。
            self.rebuilding = false;
            self.lastGoodScroll = null;
            // r17 稳定信号（审计方案）：最新序号已应用且滚动恢复完成——e2e 据此
            // 采样滚动不变量，替代对预览往返时序的猜测等待。旧响应（序号不符）
            // 不到达 finishUpdate，故此事件即"最新 generation 已 settle"。
            try {
                global.dispatchEvent(new global.CustomEvent("blox:preview-settled", { detail: { sequence: self.sequence } }));
            } catch (e) { /* CustomEvent 不可用的古老环境：仅少一个测试信号 */ }
        });
    };

    BloxPreviewClient.prototype.refresh = function () {
        var self = this;
        var frame = this.getFrame();
        if (!frame) return Promise.resolve(false);

        if (this.controller) this.controller.abort();
        var controller = typeof global.AbortController === "function" ? new global.AbortController() : null;
        var sequence = ++this.sequence;
        this.controller = controller;
        this.setLoading(true);

        var body = new global.URLSearchParams();
        body.set("action", "preview");
        body.set("blox", "1");
        body.set("blocks_data", JSON.stringify(this.getDocument()));
        body.set("_token", this.csrf);
        var extraParams = this.getParams() || {};
        Object.keys(extraParams).forEach(function (key) {
            body.set(key, String(extraParams[key]));
        });
        var request = { method: "POST", body: body };
        if (controller) request.signal = controller.signal;

        return this.fetch(this.endpoint, request)
            .then(function (response) {
                if (!response.ok) throw new Error("Preview request failed: " + response.status);
                return response.text();
            })
            .then(function (html) {
                if (sequence !== self.sequence) return false;
                var scrollState = self.captureScroll(frame);
                if (self.patchFrame(frame, html)) {
                    self.finishUpdate(frame, scrollState);
                    return true;
                }
                var onFrameLoad = function () {
                    frame.removeEventListener("load", onFrameLoad);
                    if (sequence !== self.sequence) {
                        self.rebuilding = false;
                        return;
                    }
                    self.rememberSections(frame, html);
                    self.finishUpdate(frame, scrollState);
                };
                frame.addEventListener("load", onFrameLoad);
                self.rebuilding = true;
                frame.srcdoc = html;
                return true;
            })
            .catch(function (error) {
                if (!error || error.name !== "AbortError") self.onError(error);
                return false;
            })
            .finally(function () {
                if (sequence !== self.sequence) return;
                self.setLoading(false);
                self.controller = null;
            });
    };

    BloxPreviewClient.prototype.cancel = function () {
        clearTimeout(this.timer);
        this.timer = null;
        this.sequence++;
        if (this.controller) this.controller.abort();
        this.controller = null;
        this.setLoading(false);
    };

    global.BloxPreviewClient = BloxPreviewClient;
})(window);
