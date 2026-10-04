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
        this.onDiagnostic = options.onDiagnostic || function () {};
        this.sourceSignature = null;
        this.sourceHead = null;
        this.patchReason = '';
        this.lastDocument = null;
        this.lastHtml = null;
        this.lastParams = null;
        this.frameLoad = null;
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
        if (typeof doc.cloneNode === 'function') {
            var shell = doc.cloneNode(true);
            this.directSections(shell).forEach(function (section) { section.remove(); });
            return shell.documentElement.outerHTML;
        }
        var head = Array.prototype.map.call(doc.head.children || [], function (node) {
            return node.outerHTML || "";
        }).join("\n");
        var body = Array.prototype.filter.call(doc.body.children || [], function (node) {
            if (node.hasAttribute && node.hasAttribute("data-yk-sec")) return false;
            if (!node.classList) return true;
            return !node.classList.contains("yk-pick-overlay")
                && !node.classList.contains("yk-pick-label")
                && !node.classList.contains("yk-drop-line")
                && !node.classList.contains("yk-insert-rail");
        }).map(function (node) {
            return node.outerHTML || "";
        }).join("\n");
        return head + "\n---body---\n" + body;
    };

    BloxPreviewClient.prototype.directSections = function (doc) {
        if (!doc || !doc.body) return [];
        var host = this.sectionHost(doc);
        return Array.prototype.filter.call(host.children || [], function (node) {
            return node.hasAttribute && node.hasAttribute("data-yk-sec");
        });
    };

    BloxPreviewClient.prototype.patchFrame = function (frame, html) {
        var currentDoc = frame && frame.contentDocument;
        var nextDoc = this.parseHtml(html);
        this.patchReason = '';
        if (!currentDoc || !currentDoc.body) { this.patchReason = 'no-document'; return false; }
        if (!nextDoc || !nextDoc.body) { this.patchReason = 'parse-failed'; return false; }
        if (!this.sourceSignature && !(currentDoc.body.children || []).length) { this.patchReason = 'first-load'; return false; }
        var nextSignature = this.documentSignature(nextDoc);
        // Compare server-authored shells, not live DOM mutated by widgets and editor helpers.
        if ((this.sourceSignature || this.documentSignature(currentDoc)) !== nextSignature) {
            this.patchReason = this.sourceHead !== null && this.sourceHead !== nextDoc.head.outerHTML ? 'head-changed' : 'shell-changed'; return false;
        }

        var currentSections = this.directSections(currentDoc);
        var nextSections = this.directSections(nextDoc);
        var currentByIndex = Object.create(null);
        var nextIndexes = Object.create(null);
        var valid = true;
        var self = this;

        currentSections.forEach(function (section) {
            var index = self.sectionKey(section);
            if (index === null || currentByIndex[index]) valid = false;
            currentByIndex[index] = section;
        });
        nextSections.forEach(function (section) {
            var index = self.sectionKey(section);
            if (index === null || nextIndexes[index]) valid = false;
            nextIndexes[index] = true;
        });
        if (!valid) { this.patchReason = 'invalid-section-id'; return false; }

        var body = this.sectionHost(currentDoc);
        // 追加锚点：新 section 必须落在最后一个现有 section 之后。
        // 不能取「第一个非 section 子节点」——1.18 起画布 body 首位是头部区域壳
        // （.yk-blox-header），旧取法会把新增 section 插到页面最顶端（页头之上），
        // 直到下次整载才归位；连锁把画布滚动清零（r16-r17 五轮 CI 误诊为恢复时序）。
        var anchor = currentSections.length
            ? currentSections[currentSections.length - 1].nextSibling
            : body !== currentDoc.body ? null : (Array.prototype.find.call(body.children || [], function (node) {
                return !(node.hasAttribute && node.hasAttribute("data-yk-sec"))
                    && !(node.classList && node.classList.contains("yk-blox-header"));
            }) || null);
        var ordered = [];
        var changed = false;
        var updatedRoots = [];

        nextSections.forEach(function (nextSection) {
            var index = self.sectionKey(nextSection);
            var currentSection = currentByIndex[index] || null;
            var nextHtml = nextSection.outerHTML;
            var currentHtml = currentSection && (currentSection.__bloxSourceHtml || currentSection.outerHTML);
            if (currentSection && currentHtml === nextHtml) {
                currentSection.__bloxSourceHtml = nextHtml;
                ordered.push(currentSection);
                return;
            }
            if (currentSection && self.patchElements(currentSection, nextSection, currentHtml, updatedRoots)) {
                currentSection.__bloxSourceHtml = nextHtml;
                ordered.push(currentSection);
                changed = true;
                return;
            }
            var replacement = currentDoc.importNode(nextSection, true);
            replacement.__bloxSourceHtml = nextHtml;
            if (currentSection) { self.notifyRemoval(currentSection); body.replaceChild(replacement, currentSection); }
            ordered.push(replacement);
            updatedRoots.push(replacement);
            changed = true;
        });

        currentSections.forEach(function (section) {
            var index = self.sectionKey(section);
            if (!nextIndexes[index] && section.parentNode === body) {
                self.notifyRemoval(section);
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
            updatedRoots.forEach(function (root) {
                currentDoc.dispatchEvent(new currentDoc.defaultView.CustomEvent("blox:content-updated", {
                    detail: { root: root },
                }));
            });
            currentDoc.dispatchEvent(new currentDoc.defaultView.CustomEvent('blox:structure-updated'));
        }
        this.sourceSignature = nextSignature;
        this.sourceHead = nextDoc.head.outerHTML;
        return true;
    };

    BloxPreviewClient.prototype.notifyRemoval = function (root) {
        var doc = root.ownerDocument;
        if (doc && doc.defaultView && doc.dispatchEvent) {
            doc.dispatchEvent(new doc.defaultView.CustomEvent('blox:content-removing', {detail: {root: root}}));
        }
    };

    // Only patch direct column children when the server-authored layout is identical.
    // Complex/nested structural changes retain the existing section-level fallback.
    BloxPreviewClient.prototype.patchElements = function (current, next, previousHtml, roots) {
        if (!current.querySelectorAll || !previousHtml) return false;
        var previousDoc = this.parseHtml(previousHtml);
        var previous = previousDoc && previousDoc.body && previousDoc.body.firstElementChild;
        if (!previous) return false;
        function scaffold(node) {
            var clone = node.cloneNode(true);
            Array.prototype.forEach.call(clone.querySelectorAll('[data-yk-col] > [data-yk-el-id]'), function (el) { el.remove(); });
            return clone.outerHTML;
        }
        if (scaffold(previous) !== scaffold(next)) return false;
        var currentCols = Array.from(current.querySelectorAll('[data-yk-col]'));
        var nextCols = Array.from(next.querySelectorAll('[data-yk-col]'));
        var oldCols = Array.from(previous.querySelectorAll('[data-yk-col]'));
        if (nextCols.some(function (col) { return col.closest('[data-yk-el-id]'); })) return false;
        if (currentCols.length !== nextCols.length || oldCols.length !== nextCols.length) return false;
        function children(col) { return Array.from(col.children).filter(function (el) { return el.hasAttribute('data-yk-el-id'); }); }
        function map(nodes) {
            var result = Object.create(null);
            for (var node of nodes) {
                var id = node.getAttribute('data-yk-el-id');
                if (!id || result[id]) return null;
                result[id] = node;
            }
            return result;
        }
        var plans = [];
        for (var i = 0; i < nextCols.length; i++) {
            var live = map(children(currentCols[i]));
            var old = map(children(oldCols[i]));
            var upcoming = children(nextCols[i]);
            if (!live || !old || !map(upcoming)) return false;
            if (Object.keys(live).some(function (id) { return !old[id]; })) return false;
            plans.push({parent: currentCols[i], live: live, old: old, upcoming: upcoming});
        }
        var self = this;
        plans.forEach(function (plan) {
            var ordered = [];
            plan.upcoming.forEach(function (el) {
                var id = el.getAttribute('data-yk-el-id');
                var node = plan.live[id];
                if (!node || !plan.old[id] || plan.old[id].outerHTML !== el.outerHTML) {
                    var replacement = current.ownerDocument.importNode(el, true);
                    if (node) { self.notifyRemoval(node); plan.parent.replaceChild(replacement, node); }
                    node = replacement;
                    roots.push(node);
                }
                ordered.push(node);
                delete plan.live[id];
            });
            Object.keys(plan.live).forEach(function (id) { self.notifyRemoval(plan.live[id]); plan.live[id].remove(); });
            ordered.forEach(function (node, index) {
                var at = children(plan.parent)[index] || null;
                if (node !== at) plan.parent.insertBefore(node, at);
            });
        });
        return true;
    };

    BloxPreviewClient.prototype.sectionHost = function (doc) {
        return (doc.querySelector && doc.querySelector('[data-yk-region="content"]')) || doc.body;
    };

    BloxPreviewClient.prototype.sectionKey = function (section) {
        return section.getAttribute('data-yk-sec-id') || section.getAttribute('data-yk-sec');
    };

    BloxPreviewClient.prototype.partialTarget = function (document) {
        if (!this.lastDocument || !this.lastHtml) return null;
        var previous = this.lastDocument;
        var nextSections = Array.isArray(document) ? document : document.sections;
        var oldSections = Array.isArray(previous) ? previous : previous.sections;
        if (!Array.isArray(nextSections) || !Array.isArray(oldSections)) return null;
        var candidate = null;
        var copy = JSON.parse(JSON.stringify(document));
        var copySections = Array.isArray(copy) ? copy : copy.sections;
        nextSections.forEach(function (section, si) {
            (section.columns || []).forEach(function (column, ci) {
                (column.elements || []).forEach(function (el, ei) {
                    var old = oldSections[si]?.columns?.[ci]?.elements?.[ei];
                    if (!old || el.id !== old.id || el.type !== old.type || JSON.stringify(el) === JSON.stringify(old)) return;
                    if (!['heading', 'text', 'button', 'image', 'icon', 'spacer', 'divider'].includes(el.type)) return;
                    // Asset/background/interaction changes need the full resource inventory.
                    var safeKeys = ['text', 'html', 'url', 'new_tab', 'color', 'align', 'level', 'visual_size', 'html_id', 'alt', 'src'];
                    var keys = new Set(Object.keys(old.data || {}).concat(Object.keys(el.data || {})));
                    if (Array.from(keys).some(function (key) {
                        return JSON.stringify((old.data || {})[key]) !== JSON.stringify((el.data || {})[key]) && !safeKeys.includes(key);
                    })) return;
                    var oldMeta = Object.assign({}, old), nextMeta = Object.assign({}, el);
                    delete oldMeta.data;
                    delete nextMeta.data;
                    if (JSON.stringify(oldMeta) !== JSON.stringify(nextMeta)) return;
                    copySections[si].columns[ci].elements[ei] = old;
                    candidate = candidate === null ? el.id : false;
                });
            });
        });
        return candidate && JSON.stringify(copy) === JSON.stringify(previous) ? candidate : null;
    };

    BloxPreviewClient.prototype.rememberSections = function (frame, html) {
        var currentDoc = frame && frame.contentDocument;
        var sourceDoc = this.parseHtml(html);
        if (!currentDoc || !sourceDoc) return;
        this.sourceSignature = this.documentSignature(sourceDoc);
        this.sourceHead = sourceDoc.head && sourceDoc.head.outerHTML;
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
        var sequence = this.sequence;
        this.restoreScroll(frame, state);
        this.onLoaded();
        var raf = global.requestAnimationFrame || function (callback) { setTimeout(callback, 0); };
        raf(function () {
            if (sequence !== self.sequence) return;
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
        clearTimeout(this.timer);
        this.timer = null;
        var frame = this.getFrame();
        if (!frame) return Promise.resolve(false);

        if (this.controller) this.controller.abort();
        var controller = typeof global.AbortController === "function" ? new global.AbortController() : null;
        var sequence = ++this.sequence;
        var started = Date.now();
        this.controller = controller;
        this.setLoading(true);

        var body = new global.URLSearchParams();
        body.set("action", "preview");
        body.set("blox", "1");
        var document = JSON.parse(JSON.stringify(this.getDocument()));
        var extraParams = this.getParams() || {};
        var paramsKey = JSON.stringify(extraParams);
        var partialId = !this.rebuilding && this.lastParams === paramsKey ? this.partialTarget(document) : null;
        body.set("blocks_data", JSON.stringify(document));
        if (partialId) {
            body.set('preview_scope', 'element');
            body.set('preview_element', partialId);
        }
        body.set("_token", this.csrf);
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
                var mode = 'patch';
                if (partialId && html.charAt(0) === '{') {
                    var fragment = JSON.parse(html);
                    if (fragment.protocol !== 'blox-element-v1' || fragment.element_id !== partialId || typeof fragment.html !== 'string') {
                        throw new Error('Invalid preview fragment');
                    }
                    var source = self.parseHtml(self.lastHtml);
                    var matches = Array.from(source.querySelectorAll('[data-yk-el-id]')).filter(function (el) { return el.getAttribute('data-yk-el-id') === partialId; });
                    var rendered = self.parseHtml(fragment.html);
                    var replacement = rendered.body.firstElementChild;
                    if (matches.length !== 1 || rendered.body.children.length !== 1 || !replacement || replacement.getAttribute('data-yk-el-id') !== partialId) {
                        throw new Error('Invalid preview target');
                    }
                    matches[0].replaceWith(source.importNode(replacement, true));
                    html = '<!doctype html>' + source.documentElement.outerHTML;
                    mode = 'element-ssr';
                }
                var scrollState = self.captureScroll(frame);
                if (self.rebuilding) self.patchReason = 'load-in-progress';
                if (!self.rebuilding && self.patchFrame(frame, html)) {
                    self.lastHtml = html;
                    self.lastDocument = document;
                    self.lastParams = paramsKey;
                    self.onDiagnostic({sequence: sequence, mode: mode, reason: '', elapsed: Date.now() - started});
                    self.finishUpdate(frame, scrollState);
                    return true;
                }
                var onFrameLoad = function () {
                    frame.removeEventListener("load", onFrameLoad);
                    if (sequence !== self.sequence) return;
                    self.frameLoad = null;
                    self.rememberSections(frame, html);
                    self.lastHtml = html;
                    self.lastDocument = document;
                    self.lastParams = paramsKey;
                    self.finishUpdate(frame, scrollState);
                };
                if (self.frameLoad) frame.removeEventListener('load', self.frameLoad);
                self.frameLoad = onFrameLoad;
                frame.addEventListener("load", onFrameLoad);
                self.onDiagnostic({sequence: sequence, mode: 'reload', reason: self.patchReason, elapsed: Date.now() - started});
                self.rebuilding = true;
                frame.srcdoc = html;
                return true;
            })
            .catch(function (error) {
                if (sequence !== self.sequence) return false;
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
        this.lastDocument = null;
        this.lastHtml = null;
        this.lastParams = null;
        var frame = this.getFrame();
        if (frame && this.frameLoad) frame.removeEventListener('load', this.frameLoad);
        this.frameLoad = null;
        if (this.controller) this.controller.abort();
        this.controller = null;
        this.setLoading(false);
    };

    global.BloxPreviewClient = BloxPreviewClient;
})(window);
