(function (global) {
    "use strict";

    // 编辑中页面的内容语言：内置模板按它返回英/日译文（空 = 站点默认内容）
    var contentLanguage = "";
    function setContentLanguage(language) {
        contentLanguage = String(language || "");
    }

    function responseData(response, fallbackMessage) {
        return response.text().then(function (body) {
            var result;
            try {
                result = JSON.parse(body);
            } catch (error) {
                var suffix = response && response.status ? " (HTTP " + response.status + ")" : "";
                throw new Error(fallbackMessage + suffix);
            }
            if (response && response.ok === false) {
                throw new Error((result && result.msg) || fallbackMessage + " (HTTP " + response.status + ")");
            }
            if (!result || Number(result.code) !== 0) {
                throw new Error((result && result.msg) || fallbackMessage);
            }
            return result.data || {};
        });
    }

    function request(endpoint, action, context, key, fallbackMessage, refresh) {
        var url = endpoint + "?action=" + encodeURIComponent(action)
            + "&context=" + encodeURIComponent(context);
        if (key) url += "&key=" + encodeURIComponent(key);
        if (refresh) url += "&refresh=1";
        if (contentLanguage) url += "&lang=" + encodeURIComponent(contentLanguage);
        return fetch(url, { cache: "no-store" }).then(function (response) {
            return responseData(response, fallbackMessage);
        });
    }

    function list(endpoint, context, fallbackMessage, refresh) {
        return request(endpoint, "list", context, "", fallbackMessage, refresh).then(function (data) {
            var items = (Array.isArray(data.items) ? data.items : []).map(normalizeItem);
            items.remoteError = String(data.remote_error || "");
            return items;
        });
    }

    function normalizeItem(item) {
        var normalized = Object.assign({}, item || {});
        normalized.locked = normalized.locked === true
            || normalized.locked === 1
            || normalized.locked === "1";
        normalized.locked_reason = String(normalized.locked_reason || "");
        normalized.metadata = normalizeMetadata(normalized.metadata);
        return normalized;
    }

    function normalizeMetadata(raw) {
        var metadata = raw && typeof raw === "object" && !Array.isArray(raw) ? raw : {};
        var allowedPageTypes = [
            "general", "home", "about", "product-list", "product-detail", "content-list",
            "case", "contact", "jobs", "service", "landing",
        ];
        var pageTypes = Array.isArray(metadata.page_types) ? metadata.page_types.filter(function (value, index, list) {
            return typeof value === "string" && allowedPageTypes.indexOf(value) !== -1
                && list.indexOf(value) === index;
        }).slice(0, 12) : [];
        var priority = Number(metadata.priority);
        var variants = ["standard", "split", "centered", "cards", "side-by-side", "minimal", "dynamic"];
        var variant = String(metadata.variant || "standard").trim().toLowerCase();
        var dataSources = ["static", "dynamic"];
        var dataSource = String(metadata.data_source || "static").trim().toLowerCase();
        var states = Array.isArray(metadata.states) ? metadata.states.filter(function (value, index, list) {
            return ["loading", "empty", "error"].indexOf(value) !== -1 && list.indexOf(value) === index;
        }).slice(0, 3) : [];
        return Object.assign({}, metadata, {
            schema: 1,
            page_types: pageTypes.length > 0 ? pageTypes : ["general"],
            variant: variants.indexOf(variant) !== -1 ? variant : "standard",
            data_source: dataSources.indexOf(dataSource) !== -1 ? dataSource : "static",
            states: states,
            priority: Number.isFinite(priority) ? Math.max(0, Math.min(100, Math.round(priority))) : 0,
        });
    }

    function recommendationScore(item, pageType) {
        if (!item || item.type !== "section") return -1;
        var metadata = normalizeMetadata(item.metadata);
        var intent = allowedIntent(pageType);
        if (metadata.page_types.indexOf(intent) === -1) return -1;
        return 100 + metadata.priority;
    }

    function allowedIntent(pageType) {
        var value = String(pageType || "general").trim().toLowerCase();
        return [
            "general", "home", "about", "product-list", "product-detail", "content-list",
            "case", "contact", "jobs", "service", "landing",
        ].indexOf(value) !== -1 ? value : "general";
    }

    function recommend(items, pageType) {
        return (Array.isArray(items) ? items : []).map(function (item, index) {
            return { item: item, index: index, score: recommendationScore(item, pageType) };
        }).filter(function (entry) {
            return entry.score >= 0;
        }).sort(function (a, b) {
            return a.score === b.score ? a.index - b.index : b.score - a.score;
        }).map(function (entry) {
            return entry.item;
        });
    }

    function isRecommended(item, pageType) {
        return recommendationScore(item, pageType) >= 0;
    }

    function resolve(endpoint, context, key, fallbackMessage, csrf) {
        var body = new URLSearchParams();
        body.set("action", "get");
        body.set("context", context);
        body.set("key", key);
        if (contentLanguage) body.set("lang", contentLanguage);
        body.set("_token", csrf || "");
        return fetch(endpoint, { method: "POST", body: body, cache: "no-store" })
            .then(function (response) { return responseData(response, fallbackMessage); })
            .then(function (data) {
                if (!data.template || !Array.isArray(data.template.sections)) {
                    throw new Error(fallbackMessage);
                }
                return data.template;
            });
    }

    function postInsertAction(endpoint, action, context, key, reviewId, extra, fallbackMessage, csrf) {
        var body = new URLSearchParams();
        body.set("action", action);
        body.set("context", context);
        body.set("key", key);
        if (reviewId) body.set("review_id", reviewId);
        if (contentLanguage) body.set("lang", contentLanguage);
        var options = extra && typeof extra === "object" ? extra : {};
        if (options.style_mode) body.set("style_mode", options.style_mode);
        ["tokens", "styles"].forEach(function (kind) {
            var map = options[kind] && typeof options[kind] === "object" ? options[kind] : {};
            Object.keys(map).forEach(function (from) {
                var to = map[from];
                if (to !== "" && to !== null && to !== undefined) {
                    body.set("design_" + kind + "[" + from + "]", to);
                }
            });
        });
        body.set("_token", csrf || "");
        return fetch(endpoint, { method: "POST", body: body, cache: "no-store" })
            .then(function (response) { return responseData(response, fallbackMessage); });
    }

    // 画布插入检查：远程/内置来源返回 review_id 与设计诊断；本地/插件 review_id 为空（走直接插入）。
    function prepareInsert(endpoint, context, key, fallbackMessage, csrf) {
        return postInsertAction(endpoint, "prepare_insert", context, key, "", null, fallbackMessage, csrf)
            .then(function (data) {
                if (!data.template) throw new Error(fallbackMessage);
                return data;
            });
    }

    // 画布插入确认：只提交 review_id 与映射选择，sections 由服务端按映射重新生成。
    function confirmInsert(endpoint, context, key, reviewId, options, fallbackMessage, csrf) {
        return postInsertAction(endpoint, "confirm_insert", context, key, reviewId, options, fallbackMessage, csrf)
            .then(function (data) {
                if (!data.template || !Array.isArray(data.template.sections)) {
                    throw new Error(fallbackMessage);
                }
                return data.template;
            });
    }
    function categoryValue(item) {
        var value = String(item && item.category || "").trim().toLowerCase();
        return value || String(item && item.type || "").trim().toLowerCase();
    }

    function purposeValue(item) {
        return String(normalizeMetadata(item && item.metadata).purpose || "general").trim().toLowerCase();
    }

    function dataSourceValue(item) {
        return String(normalizeMetadata(item && item.metadata).data_source || "static").trim().toLowerCase();
    }

    /** 虚拟分类「首页常用」：内置区块按 home_common 标记；远程区块按适用页面含首页判断 */
    var HOME_COMMON = "home-common";
    function isHomeCommon(item) {
        if (!item || item.type !== "section") return false;
        if (item.home_common === true) return true;
        return item.source !== "builtin" && normalizeMetadata(item.metadata).page_types.indexOf("home") !== -1;
    }

    function filter(items, query, type, source, category, purpose, dataSource) {
        var q = String(query || "").trim().toLowerCase();
        var wantedCategory = String(category || "all").trim().toLowerCase();
        var wantedPurpose = String(purpose || "all").trim().toLowerCase();
        var wantedDataSource = String(dataSource || "all").trim().toLowerCase();
        return (Array.isArray(items) ? items : []).filter(function (item) {
            if (type !== "all" && item.type !== type) return false;
            if (source && source !== "all" && item.source !== source) return false;
            if (wantedCategory === HOME_COMMON) {
                if (!isHomeCommon(item)) return false;
            } else if (wantedCategory !== "all" && categoryValue(item) !== wantedCategory) return false;
            if (wantedPurpose !== "all" && purposeValue(item) !== wantedPurpose) return false;
            if (wantedDataSource !== "all" && dataSourceValue(item) !== wantedDataSource) return false;
            if (!q) return true;
            var metadata = normalizeMetadata(item.metadata);
            return String(item.name || "").toLowerCase().indexOf(q) !== -1
                || String(item.provider || "").toLowerCase().indexOf(q) !== -1
                || String(item.description || "").toLowerCase().indexOf(q) !== -1
                || (Array.isArray(item.keywords) ? item.keywords.join(" ") : String(item.keywords || ""))
                    .toLowerCase().indexOf(q) !== -1
                || String(item.category || "").toLowerCase().indexOf(q) !== -1
                || String(metadata.purpose || "").toLowerCase().indexOf(q) !== -1
                || metadata.page_types.join(" ").toLowerCase().indexOf(q) !== -1;
        });
    }

    function categories(items) {
        var seen = {};
        var homeCommon = false;
        (Array.isArray(items) ? items : []).forEach(function (item) {
            var value = categoryValue(item);
            if (value) seen[value] = true;
            if (isHomeCommon(item)) homeCommon = true;
        });
        var list = Object.keys(seen).sort();
        return homeCommon ? [HOME_COMMON].concat(list) : list;
    }

    function categoryLabel(category, text) {
        var value = String(category || "").trim().toLowerCase();
        var key = "category" + value.split("-").map(function (part) {
            return part.charAt(0).toUpperCase() + part.slice(1);
        }).join("");
        return text && text[key] ? text[key] : value;
    }

    function purposes(items) {
        var seen = {};
        (Array.isArray(items) ? items : []).forEach(function (item) {
            if (item && item.type === "section") seen[purposeValue(item)] = true;
        });
        return Object.keys(seen).sort();
    }

    function dataSources(items) {
        var seen = {};
        (Array.isArray(items) ? items : []).forEach(function (item) {
            if (item && item.type === "section") seen[dataSourceValue(item)] = true;
        });
        return Object.keys(seen).sort();
    }

    function purposeLabel(purpose, text) {
        var value = String(purpose || "general").trim().toLowerCase();
        var key = "purpose" + value.split("-").map(function (part) {
            return part.charAt(0).toUpperCase() + part.slice(1);
        }).join("");
        return text && text[key] ? text[key] : value;
    }

    function scope(items, value) {
        var remote = value === "remote";
        return (Array.isArray(items) ? items : []).filter(function (item) {
            return remote ? item.source === "remote" : item.source !== "remote";
        });
    }

    function scopeCount(items, value) {
        return scope(items, value).length;
    }

    function upsertLocal(items, item) {
        var current = Array.isArray(items) ? items : [];
        if (!item || item.source !== "local" || !/^local:\d+$/.test(String(item.key || ""))
            || (item.type !== "section" && item.type !== "page")) {
            var unchanged = current.slice();
            unchanged.remoteError = String(current.remoteError || "");
            return unchanged;
        }
        var localItem = normalizeItem(item);
        localItem.locked = false;
        localItem.locked_reason = "";
        var merged = [localItem].concat(current.filter(function (entry) {
            return String(entry && entry.key || "") !== String(item.key);
        }));
        merged.remoteError = String(current.remoteError || "");
        return merged;
    }

    function providerLabel(item, text) {
        if (item && item.source === "plugin") return text.plugin + " / " + String(item.provider || "");
        if (item && item.source === "remote") return text.remote;
        return text.local;
    }

    function canEditLocal(item) {
        return !!item && item.source === "local" && /^local:\d+$/.test(String(item.key || ""));
    }

    function localEditUrl(item) {
        return canEditLocal(item) ? "/admin/blox_editor.php?template=" + String(item.key).slice(6) : "";
    }

    /**
     * 依赖缺口：服务端 items() 给出 unavailable，插入的真正拦截在 resolve()。
     * 这里只负责说清楚缺什么——凭空消失比说"缺个插件"难查得多。
     */
    function unavailableReasons(item) {
        var raw = item && item.unavailable;
        if (!raw || typeof raw !== "object") return { elements: [], plugins: [], invalid: false };
        return {
            elements: Array.isArray(raw.elements) ? raw.elements.map(String) : [],
            plugins: Array.isArray(raw.plugins) ? raw.plugins.map(String) : [],
            invalid: raw.invalid === true,
        };
    }

    function isUnavailable(item) {
        var reasons = unavailableReasons(item);
        return reasons.invalid || reasons.elements.length > 0 || reasons.plugins.length > 0;
    }

    function unavailableLabel(item, text) {
        var reasons = unavailableReasons(item);
        if (reasons.invalid) return (text && text.depsInvalid) || "";
        var parts = [];
        if (reasons.plugins.length) {
            parts.push(String((text && text.depsPlugins) || "").replace(":list", reasons.plugins.join("、")));
        }
        if (reasons.elements.length) {
            parts.push(String((text && text.depsElements) || "").replace(":list", reasons.elements.join("、")));
        }
        return parts.join(" ");
    }

    function lockLabel(item, text) {
        if (!item || !item.locked) return "";
        if (item.locked_reason === "license_expired") return text.lockedExpired;
        if (item.locked_reason === "module_missing") return text.lockedModule;
        if (item.locked_reason === "domain_mismatch") return text.lockedDomain;
        if (item.locked_reason === "disabled") return text.lockedDisabled;
        return text.lockedLicense;
    }

    /**
     * 精品区块入口的「一条统一说明」。
     *
     * 克制原则（ROUND-06 + 区块库任务书 §7）：不给每张卡叠锁、不反复弹购买；有权益的用户看不到任何提示。
     * 当所有付费条目因**同一个原因**被锁时，只在入口顶部说一次该怎么办，卡片不再重复锁定文案；
     * 原因不一致（例如个别款限额）时退回逐卡说明。
     * 已填授权码却仍是 license_required，视为「已购未激活」，引导去后台授权而不是再去购买。
     *
     * @return {{state:string}|null} purchase | activate | renew | domain | disabled | module | error | mixed | null
     */
    function premiumNotice(items, remoteError, hasLicenseKey) {
        if (remoteError) return { state: "error" };
        var paid = (Array.isArray(items) ? items : []).filter(function (item) {
            return item && item.source === "remote" && !!item.paid;
        });
        var locked = paid.filter(function (item) { return !!item.locked; });
        if (!locked.length) return null;
        var reasons = [];
        locked.forEach(function (item) {
            var reason = String(item.locked_reason || "license_required");
            if (reasons.indexOf(reason) === -1) reasons.push(reason);
        });
        if (reasons.length !== 1 || locked.length !== paid.length) return { state: "mixed" };
        switch (reasons[0]) {
            case "license_required": return { state: hasLicenseKey ? "activate" : "purchase" };
            case "license_expired": return { state: "renew" };
            case "domain_mismatch": return { state: "domain" };
            case "disabled": return { state: "disabled" };
            case "module_missing": return { state: "module" };
            default: return { state: "mixed" };
        }
    }

    /** 统一说明已经讲清楚时，卡片不再重复锁定文案。 */
    function showCardLock(item, notice) {
        return !!(item && item.locked) && (!notice || notice.state === "mixed");
    }

    /** 列表里全是精品时「精品」徽标是噪音；只有精品与免费混排时才用它区分。 */
    function showPremiumBadge(item, items) {
        if (!item || !item.paid) return false;
        return (Array.isArray(items) ? items : []).some(function (other) {
            return other && other.source === item.source && !other.paid;
        });
    }

    function hasLockedRemote(items) {
        return (Array.isArray(items) ? items : []).some(function (item) {
            return item.source === "remote" && !!item.locked;
        });
    }

    function applyPageSettings(current, template, mode, pageTarget) {
        if (!pageTarget || !template || template.type !== "page") return current;
        var result = Object.assign({}, current || {});
        var settings = template.settings && typeof template.settings === "object"
            && !Array.isArray(template.settings) ? template.settings : {};
        // Only page frame preferences may cross a full-page import; area and product settings stay local.
        ["page_header_hidden", "page_footer_hidden", "page_breadcrumb_hidden", "page_title_hidden", "page_sidebar_hidden"].forEach(function (key) {
            if (mode === "replace") delete result[key];
            if (Object.prototype.hasOwnProperty.call(settings, key)) {
                result[key] = settings[key] === true || settings[key] === 1 || settings[key] === "1";
            }
        });
        return result;
    }

    function freshSections(sections, uid) {
        return sections.map(function (section) {
            var copy = JSON.parse(JSON.stringify(section || {}));
            copy.id = uid("s");
            delete copy.library_id;
            delete copy.library_name;
            copy.type = copy.type || "section";
            copy.settings = copy.settings && typeof copy.settings === "object" ? copy.settings : {};
            copy.columns = Array.isArray(copy.columns) ? copy.columns : [];
            copy.columns.forEach(function (column) {
                column.id = uid("c");
                column.elements = Array.isArray(column.elements) ? column.elements : [];
                var freshElement = function (element) {
                    element.id = uid("e");
                    element.data = element.data && typeof element.data === "object" ? element.data : {};
                    if (Array.isArray(element.data.children)) element.data.children.forEach(freshElement);
                };
                column.elements.forEach(freshElement);
            });
            return copy;
        });
    }

    function canonicalDocumentValue(value) {
        if (Array.isArray(value)) return value.map(canonicalDocumentValue);
        if (!value || typeof value !== "object") return value;
        return Object.keys(value).sort().reduce(function (result, key) {
            if (key === "id" || key === "library_id" || key === "library_name" || key.charAt(0) === "_") {
                return result;
            }
            result[key] = canonicalDocumentValue(value[key]);
            return result;
        }, {});
    }

    function documentFingerprint(document) {
        var source = document && typeof document === "object" ? document : {};
        return JSON.stringify(canonicalDocumentValue({
            settings: source.settings && typeof source.settings === "object" ? source.settings : {},
            sections: Array.isArray(source.sections) ? source.sections : [],
        }));
    }

    function elementCounts(sections) {
        var counts = {};
        function visit(element) {
            if (!element || !element.type) return;
            counts[element.type] = (counts[element.type] || 0) + 1;
            var children = element.data && Array.isArray(element.data.children) ? element.data.children : [];
            children.forEach(visit);
        }
        (sections || []).forEach(function (section) {
            (section.columns || []).forEach(function (column) {
                (column.elements || []).forEach(visit);
            });
        });
        return counts;
    }

    function compareSections(current, candidate, label) {
        var before = elementCounts(current);
        var after = elementCounts(candidate);
        var types = Array.from(new Set(Object.keys(before).concat(Object.keys(after)))).sort();
        var added = [];
        var removed = [];
        types.forEach(function (type) {
            var delta = (after[type] || 0) - (before[type] || 0);
            if (delta > 0) added.push(label(type, delta));
            if (delta < 0) removed.push(label(type, -delta));
        });
        return { added: added, removed: removed, same: added.length === 0 && removed.length === 0 };
    }

    global.BloxTemplateLibrary = {
        setContentLanguage: setContentLanguage,
        elementCounts: elementCounts,
        compareSections: compareSections,
        list: list,
        resolve: resolve,
        prepareInsert: prepareInsert,
        confirmInsert: confirmInsert,
        normalizeMetadata: normalizeMetadata,
        recommend: recommend,
        isRecommended: isRecommended,
        filter: filter,
        categories: categories,
        categoryLabel: categoryLabel,
        purposes: purposes,
        dataSources: dataSources,
        purposeLabel: purposeLabel,
        scope: scope,
        scopeCount: scopeCount,
        upsertLocal: upsertLocal,
        providerLabel: providerLabel,
        canEditLocal: canEditLocal,
        localEditUrl: localEditUrl,
        lockLabel: lockLabel,
        isUnavailable: isUnavailable,
        unavailableLabel: unavailableLabel,
        hasLockedRemote: hasLockedRemote,
        premiumNotice: premiumNotice,
        showCardLock: showCardLock,
        showPremiumBadge: showPremiumBadge,
        freshSections: freshSections,
        applyPageSettings: applyPageSettings,
        documentFingerprint: documentFingerprint,
    };
})(window);
