(function () {
    'use strict';

    var charts = new WeakMap();

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function parseData(root) {
        var source = root.querySelector('[data-org-chart-data]');
        if (!source) return null;
        try {
            var data = JSON.parse(source.textContent || '{}');
            if (!data || !Array.isArray(data.nodes) || !data.nodes.length) return null;
            var byId = {};
            data.nodes.forEach(function (item) { byId[item.id] = item; });
            function depthOf(item, trail) {
                if (!item || !item.parent_id || !byId[item.parent_id]) return 0;
                if (trail[item.id]) return 0;
                var nextTrail = Object.assign({}, trail);
                nextTrail[item.id] = true;
                return Math.min(12, depthOf(byId[item.parent_id], nextTrail) + 1);
            }
            data.nodes.forEach(function (item) { item._depth = depthOf(item, {}); });
            return data;
        } catch (error) {
            return null;
        }
    }

    function nodeContent(node) {
        var item = node && node.data ? node.data : {};
        var title = item.title ? '<span>' + escapeHtml(item.title) + '</span>' : '';
        var depthClass = Number(item._depth) >= 2 ? ' is-level-3' : '';
        return '<div class="yk-org-card' + (!item.parent_id ? ' is-root' : '') + depthClass + '">'
            + '<strong>' + escapeHtml(item.name) + '</strong>' + title + '</div>';
    }

    function enhance(root) {
        if (!root || charts.has(root) || !window.d3 || typeof window.d3.OrgChart !== 'function') return;
        var data = parseData(root);
        var stage = root.querySelector('.yk-org-chart-stage');
        if (!data || !stage) return;

        try {
            var height = Math.max(360, Math.min(760, 280 + data.nodes.length * 18));
            var chart = new window.d3.OrgChart()
                .container(stage)
                .data(data.nodes)
                .nodeId(function (item) { return item.id; })
                .parentNodeId(function (item) { return item.parent_id || null; })
                .layout(data.layout === 'left' ? 'left' : 'top')
                .compact(!!data.compact)
                .initialExpandLevel(Math.max(1, Math.min(8, Number(data.initial_depth) || 4)))
                .nodeWidth(function (node) { return Number((node.data || {})._depth) >= 2 ? 72 : 210; })
                .nodeHeight(function (node) { return Number((node.data || {})._depth) >= 2 ? 150 : 74; })
                .childrenMargin(function () { return 54; })
                .siblingsMargin(function () { return 34; })
                .compactMarginBetween(function () { return 24; })
                .compactMarginPair(function () { return 48; })
                .svgHeight(height)
                .nodeContent(nodeContent)
                .render();
            charts.set(root, chart);
            root.classList.add('is-enhanced');
            window.setTimeout(function () { if (chart && chart.fit) chart.fit(); }, 60);

            root.addEventListener('click', function (event) {
                var button = event.target.closest('[data-org-action]');
                if (!button || !root.contains(button)) return;
                var action = button.getAttribute('data-org-action');
                if (action === 'zoom-in' && chart.zoomIn) chart.zoomIn();
                else if (action === 'zoom-out' && chart.zoomOut) chart.zoomOut();
                else if (action === 'fit' && chart.fit) chart.fit();
            });
        } catch (error) {
            stage.textContent = '';
            root.classList.remove('is-enhanced');
        }
    }

    function scan(scope) {
        var root = scope && scope.querySelectorAll ? scope : document;
        if (root.matches && root.matches('[data-blox-org-chart]')) enhance(root);
        root.querySelectorAll('[data-blox-org-chart]').forEach(enhance);
    }

    function boot() {
        scan(document);
        new MutationObserver(function (records) {
            records.forEach(function (record) {
                record.addedNodes.forEach(function (node) {
                    if (node.nodeType === 1) scan(node);
                });
            });
        }).observe(document.body, { childList: true, subtree: true });
    }

    if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot);
    else boot();
}());
