(function (global) {
    "use strict";

    function create(id, csrf, kind) {
        return {
            expanded: false, keyword: "", items: [], page: 1, hasMore: false,
            loading: false, failed: false, requestId: 0, emptyState: "", resultKeyword: "",
            requestPage: 1, requestKeyword: "",
            toggle() {
                this.expanded = !this.expanded;
                if (this.expanded) this.load(this.requestPage, this.requestKeyword);
            },
            destroy() { this.requestId++; },
            editUrl(item) {
                return ["product", "article"].includes(kind) && Number.isSafeInteger(item.id) && item.id > 0
                    ? "/admin/" + kind + "_edit.php?id=" + item.id : "";
            },
            async load(page, keyword = this.keyword.trim()) {
                var requestId = ++this.requestId;
                this.requestPage = page;
                this.requestKeyword = keyword;
                this.loading = true;
                this.failed = false;
                this.emptyState = "";
                this.items = [];
                this.hasMore = false;
                try {
                    var body = new URLSearchParams({ action: "catalog_items", id: id, _token: csrf,
                        keyword: keyword, page: page });
                    var response = await fetch("/admin/blox_page_api.php", { method: "POST", body: body,
                        credentials: "same-origin", headers: { "X-Requested-With": "XMLHttpRequest" } });
                    if (!response.ok) throw new Error("catalog-request-failed");
                    var result = await response.json();
                    if (!result || ![0, "0"].includes(result.code) || !result.data || !Array.isArray(result.data.items)) {
                        throw new Error("catalog-response-invalid");
                    }
                    if (requestId !== this.requestId) return;
                    this.items = result.data.items.filter(item => this.editUrl(item));
                    if (result.data.items.length > 0 && this.items.length === 0) {
                        throw new Error("catalog-items-invalid");
                    }
                    this.page = Number(result.data.page) || page;
                    this.hasMore = result.data.has_more === true;
                    // Describe the completed query, not unsubmitted edits in the search field.
                    this.resultKeyword = keyword;
                    this.emptyState = this.items.length ? "" : this.page > 1 ? "page" : keyword ? "search" : "unpublished";
                } catch (_) {
                    if (requestId === this.requestId) this.failed = true;
                } finally {
                    if (requestId === this.requestId) this.loading = false;
                }
            },
        };
    }

    global.BloxCatalogSource = { create: create };
    if (typeof module !== "undefined" && module.exports) module.exports = global.BloxCatalogSource;
})(typeof window !== "undefined" ? window : globalThis);
