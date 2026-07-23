<?php
/**
 * 首页产品轮播插件 v1.1.0
 *
 * 通过核心的首页版块扩展钩子接入，无需改动核心：
 *   home_block_types      —— 注册「产品轮播」版块类型
 *   home_block_config_ui  —— 后台配置：批量勾选添加产品 + 上下排序 + 标题/每屏个数/自动播放
 *   home_settings_footer  —— 采集器 + 批量添加/排序/删除/搜索脚本
 *   home_block_render     —— 前台幻灯轮播（分页圆点 + 左右箭头 + 可自动播放，自包含 vanilla JS/CSS）
 *
 * 版块配置（存于 home_blocks_config 条目）：
 *   {"type":"product_carousel","enabled":true,"title":"精选产品","per_row":4,"autoplay":5,"product_ids":[12,8,25]}
 *   autoplay：自动播放间隔秒数，0 = 关闭
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// ── 1. 注册版块类型 ───────────────────────────────────────────
add_filter('home_block_types', function (array $blockMeta): array {
    $blockMeta['product_carousel'] = [
        'title'      => '产品轮播',
        'icon'       => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 12h16M4 18h7"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 15l3 3 3-3"></path>',
        'bg_default' => '#ffffff',
        'plugin'     => true,
        'keys'       => [],
    ];
    return $blockMeta;
});

// ── 2. 后台配置 UI ────────────────────────────────────────────
add_action('home_block_config_ui', function (string $type, array $block): void {
    if ($type !== 'product_carousel') {
        return;
    }
    $selectedIds = array_values(array_filter(array_map('intval', (array) ($block['product_ids'] ?? []))));
    $title    = (string) ($block['title'] ?? '');
    $perRow   = (int) ($block['per_row'] ?? 4);
    $autoplay = (int) ($block['autoplay'] ?? 0);   // 秒；0=关

    $products = productModel()->getList(0, 500, 0, []);
    $nameMap = [];
    foreach ($products as $p) {
        $nameMap[(int) $p['id']] = (string) $p['title'];
    }
    $rowBtns = '<button type="button" class="pc-up text-gray-400 hover:text-gray-700 px-1">↑</button>'
             . '<button type="button" class="pc-down text-gray-400 hover:text-gray-700 px-1">↓</button>'
             . '<button type="button" class="pc-del text-red-400 hover:text-red-600 px-1">✕</button>';
    ?>
    <div class="bg-gray-50 rounded-lg p-4 space-y-4" data-pc-config>
        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide">产品轮播</h4>

        <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
            <div>
                <label class="text-xs text-gray-500 block mb-1">标题</label>
                <input type="text" class="pc-title w-full border rounded px-2 py-1.5 text-xs" value="<?php echo e($title); ?>" placeholder="如：精选产品">
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1">每屏个数 <span class="text-gray-300">(1-6)</span></label>
                <input type="number" min="1" max="6" class="pc-perrow w-full border rounded px-2 py-1.5 text-xs" value="<?php echo $perRow ?: 4; ?>">
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1">自动播放</label>
                <label class="inline-flex items-center gap-1.5 text-xs text-gray-600 mt-1.5">
                    <input type="checkbox" class="pc-autoplay-on" <?php echo $autoplay > 0 ? 'checked' : ''; ?>> 开启
                </label>
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1">间隔（秒）</label>
                <input type="number" min="2" max="30" class="pc-autoplay-sec w-full border rounded px-2 py-1.5 text-xs" value="<?php echo $autoplay > 0 ? $autoplay : 5; ?>">
            </div>
        </div>

        <div>
            <label class="text-xs text-gray-500 block mb-1">已选产品（顺序即展示顺序，可上下移）</label>
            <div class="pc-list space-y-1">
                <?php foreach ($selectedIds as $sid): ?>
                <div class="pc-row flex items-center gap-1 bg-white border rounded px-2 py-1 text-xs" data-id="<?php echo $sid; ?>">
                    <span class="pc-name flex-1 truncate"><?php echo e($nameMap[$sid] ?? ('产品 #' . $sid)); ?></span>
                    <?php echo $rowBtns; ?>
                </div>
                <?php endforeach; ?>
            </div>
            <p class="pc-empty text-xs text-gray-400 mt-1 <?php echo $selectedIds ? 'hidden' : ''; ?>">尚未选择产品</p>
        </div>

        <div>
            <label class="text-xs text-gray-500 block mb-1">批量添加</label>
            <input type="text" class="pc-search w-full border rounded px-2 py-1.5 text-xs mb-2" placeholder="搜索产品名筛选…">
            <div class="pc-pool max-h-48 overflow-y-auto border rounded bg-white p-2 space-y-1">
                <?php foreach ($products as $p): ?>
                <label class="pc-pool-item flex items-center gap-2 text-xs cursor-pointer hover:bg-gray-50 rounded px-1 py-0.5"
                       data-name="<?php echo e(mb_strtolower((string) $p['title'])); ?>">
                    <input type="checkbox" class="pc-check" value="<?php echo (int) $p['id']; ?>">
                    <span class="truncate"><?php echo e($p['title']); ?></span>
                </label>
                <?php endforeach; ?>
                <?php if (empty($products)): ?><div class="text-xs text-gray-400">暂无已发布产品</div><?php endif; ?>
            </div>
            <button type="button" class="pc-batch-add mt-2 bg-primary hover:bg-secondary text-white text-xs px-3 py-1.5 rounded">添加勾选的产品</button>
            <template class="pc-row-tpl">
                <div class="pc-row flex items-center gap-1 bg-white border rounded px-2 py-1 text-xs" data-id="">
                    <span class="pc-name flex-1 truncate"></span>
                    <?php echo $rowBtns; ?>
                </div>
            </template>
        </div>
    </div>
    <?php
}, 10);

// ── 3. 后台 JS：采集器 + 批量添加 / 排序 / 删除 / 搜索 ─────────
add_action('home_settings_footer', function (): void {
    ?>
    <script>
    (function () {
        window.homeBlockCollectors = window.homeBlockCollectors || {};
        window.homeBlockCollectors['product_carousel'] = function (card, item) {
            var t = card.querySelector('.pc-title');
            var pr = card.querySelector('.pc-perrow');
            var on = card.querySelector('.pc-autoplay-on');
            var sec = card.querySelector('.pc-autoplay-sec');
            if (t) item.title = t.value.trim();
            if (pr) item.per_row = Math.min(6, Math.max(1, parseInt(pr.value) || 4));
            item.autoplay = (on && on.checked) ? Math.min(30, Math.max(2, parseInt(sec && sec.value) || 5)) : 0;
            item.product_ids = Array.prototype.slice.call(card.querySelectorAll('.pc-list .pc-row'))
                .map(function (r) { return parseInt(r.dataset.id); })
                .filter(function (v) { return v > 0; });
        };

        function wrapOf(el) { return el.closest ? el.closest('[data-pc-config]') : null; }
        function refreshEmpty(wrap) {
            var hint = wrap.querySelector('.pc-empty');
            if (hint) hint.classList.toggle('hidden', wrap.querySelectorAll('.pc-list .pc-row').length > 0);
        }

        // 批量添加：把勾选且尚未加入的产品追加为行
        document.addEventListener('click', function (e) {
            var btn = e.target.closest ? e.target.closest('.pc-batch-add') : null;
            if (btn) {
                var wrap = wrapOf(btn); if (!wrap) return;
                var list = wrap.querySelector('.pc-list');
                var tpl = wrap.querySelector('.pc-row-tpl');
                var existing = {};
                wrap.querySelectorAll('.pc-list .pc-row').forEach(function (r) { existing[r.dataset.id] = 1; });
                wrap.querySelectorAll('.pc-pool .pc-check:checked').forEach(function (chk) {
                    var id = chk.value;
                    if (existing[id]) { chk.checked = false; return; }
                    var node = tpl.content.cloneNode(true);
                    var row = node.querySelector('.pc-row');
                    row.dataset.id = id;
                    row.querySelector('.pc-name').textContent = chk.parentNode.querySelector('span').textContent;
                    list.appendChild(node);
                    existing[id] = 1;
                    chk.checked = false;
                });
                refreshEmpty(wrap);
                return;
            }
            // 上移 / 下移 / 删除
            var up = e.target.closest ? e.target.closest('.pc-up') : null;
            var down = e.target.closest ? e.target.closest('.pc-down') : null;
            var del = e.target.closest ? e.target.closest('.pc-del') : null;
            if (up) { var r = up.closest('.pc-row'); if (r && r.previousElementSibling) r.parentNode.insertBefore(r, r.previousElementSibling); }
            else if (down) { var r2 = down.closest('.pc-row'); if (r2 && r2.nextElementSibling) r2.parentNode.insertBefore(r2.nextElementSibling, r2); }
            else if (del) { var r3 = del.closest('.pc-row'); if (r3) { var w = wrapOf(r3); r3.remove(); if (w) refreshEmpty(w); } }
        });

        // 搜索过滤候选池
        document.addEventListener('input', function (e) {
            if (!e.target.classList || !e.target.classList.contains('pc-search')) return;
            var wrap = wrapOf(e.target); if (!wrap) return;
            var q = e.target.value.trim().toLowerCase();
            wrap.querySelectorAll('.pc-pool .pc-pool-item').forEach(function (it) {
                it.style.display = (!q || (it.dataset.name || '').indexOf(q) !== -1) ? '' : 'none';
            });
        });
    })();
    </script>
    <?php
}, 10);

// ── 4. 前台渲染：幻灯轮播（分页圆点 + 箭头 + 可自动播放）──────
add_filter('home_block_render', function (string $html, string $type, array $block): string {
    if ($type !== 'product_carousel') {
        return $html;
    }
    $ids = array_values(array_filter(array_map('intval', (array) ($block['product_ids'] ?? []))));
    if (empty($ids)) {
        return $html;
    }
    $products = [];
    foreach ($ids as $pid) {
        $p = productModel()->getPublished($pid);
        if ($p) { $products[] = $p; }
    }
    if (empty($products)) {
        return $html;
    }

    $title    = trim((string) ($block['title'] ?? ''));
    $perRow   = max(1, min(6, (int) ($block['per_row'] ?? 4)));
    $autoplay = max(0, (int) ($block['autoplay'] ?? 0)) * 1000;   // → 毫秒
    $bgColor  = (string) ($block['bg_color'] ?? '#ffffff');
    $primary  = (string) config('primary_color', '#3B82F6');

    ob_start();
    ?>
    <section class="yk-pc" data-per-row="<?php echo $perRow; ?>" data-autoplay="<?php echo $autoplay; ?>"
             style="padding:48px 0;background:<?php echo e($bgColor); ?>;">
        <div style="max-width:1200px;margin:0 auto;padding:0 16px;position:relative;">
            <?php if ($title !== ''): ?>
            <h2 style="text-align:center;font-size:1.75rem;font-weight:700;color:#1e293b;margin:0 0 32px;"><?php echo e($title); ?></h2>
            <?php endif; ?>

            <div class="yk-pc-viewport" style="overflow:hidden;">
                <div class="yk-pc-track" style="display:flex;transition:transform .5s ease;">
                    <?php foreach ($products as $p): ?>
                    <div class="yk-pc-item" style="flex:0 0 100%;box-sizing:border-box;padding:0 12px;">
                        <a href="<?php echo productUrl($p); ?>" class="yk-pc-card"
                           style="display:block;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);text-decoration:none;color:inherit;transition:box-shadow .3s;height:100%;">
                            <div style="aspect-ratio:4/3;overflow:hidden;background:#f3f4f6;">
                                <?php if (!empty($p['cover'])): ?>
                                <img loading="lazy" src="<?php echo e(thumbnail($p['cover'], 'medium')); ?>" alt="<?php echo e($p['title']); ?>"
                                     style="width:100%;height:100%;object-fit:cover;">
                                <?php endif; ?>
                            </div>
                            <div style="padding:14px 16px;">
                                <div style="font-weight:600;color:#1e293b;font-size:15px;line-height:1.4;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;"><?php echo e($p['title']); ?></div>
                                <?php if (!empty($p['price']) && (float) $p['price'] > 0): ?>
                                <div style="margin-top:8px;color:<?php echo e($primary); ?>;font-weight:700;">&yen;<?php echo number_format((float) $p['price'], 2); ?></div>
                                <?php endif; ?>
                            </div>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <button type="button" class="yk-pc-prev" aria-label="上一组">&#10094;</button>
            <button type="button" class="yk-pc-next" aria-label="下一组">&#10095;</button>
            <div class="yk-pc-dots" style="text-align:center;margin-top:24px;"></div>
        </div>

        <style>
            .yk-pc .yk-pc-prev, .yk-pc .yk-pc-next {
                position:absolute; top:50%; transform:translateY(-50%); z-index:2;
                width:40px; height:40px; border:none; border-radius:50%;
                background:rgba(255,255,255,.9); box-shadow:0 2px 8px rgba(0,0,0,.15);
                color:#334155; font-size:18px; cursor:pointer; display:flex; align-items:center; justify-content:center;
                transition:background .2s;
            }
            .yk-pc .yk-pc-prev:hover, .yk-pc .yk-pc-next:hover { background:#fff; }
            .yk-pc .yk-pc-prev { left:-4px; }
            .yk-pc .yk-pc-next { right:-4px; }
            .yk-pc .yk-pc-card:hover { box-shadow:0 8px 24px rgba(0,0,0,.12); }
            .yk-pc .yk-pc-dot {
                width:9px; height:9px; padding:0; margin:0 4px; border:none; border-radius:50%;
                background:rgba(0,0,0,.18); cursor:pointer; vertical-align:middle; transition:all .25s;
            }
            .yk-pc .yk-pc-dot.is-active { background:<?php echo e($primary); ?>; width:22px; border-radius:5px; }
            .yk-pc.is-static .yk-pc-prev, .yk-pc.is-static .yk-pc-next, .yk-pc.is-static .yk-pc-dots { display:none; }
        </style>
    </section>
    <script>
    (function () {
        function init(el) {
            if (el.dataset.pcReady) return; el.dataset.pcReady = '1';
            var track = el.querySelector('.yk-pc-track');
            var items = track.children;
            var dots  = el.querySelector('.yk-pc-dots');
            var perRow = parseInt(el.dataset.perRow) || 4;
            var autoplay = parseInt(el.dataset.autoplay) || 0;
            var page = 0, timer = null;
            function perView() { return window.innerWidth < 640 ? 1 : (window.innerWidth < 1024 ? Math.min(2, perRow) : perRow); }
            function pages() { return Math.max(1, Math.ceil(items.length / perView())); }
            function apply() {
                var pv = perView(), w = (100 / pv);
                for (var i = 0; i < items.length; i++) { items[i].style.flex = '0 0 ' + w + '%'; items[i].style.maxWidth = w + '%'; }
                el.classList.toggle('is-static', pages() <= 1);
                buildDots(); go(Math.min(page, pages() - 1));
            }
            function buildDots() {
                if (!dots) return; dots.innerHTML = '';
                for (var p = 0; p < pages(); p++) {
                    var d = document.createElement('button');
                    d.type = 'button'; d.className = 'yk-pc-dot';
                    (function (pp) { d.addEventListener('click', function () { go(pp); restart(); }); })(p);
                    dots.appendChild(d);
                }
            }
            function mark() {
                if (!dots) return; var ds = dots.children;
                for (var i = 0; i < ds.length; i++) ds[i].className = 'yk-pc-dot' + (i === page ? ' is-active' : '');
            }
            function go(p) { var n = pages(); page = (p + n) % n; track.style.transform = 'translateX(-' + (page * 100) + '%)'; mark(); }
            function restart() { if (!autoplay || pages() <= 1) return; clearInterval(timer); timer = setInterval(function () { go(page + 1); }, autoplay); }
            var prev = el.querySelector('.yk-pc-prev'), next = el.querySelector('.yk-pc-next');
            if (prev) prev.addEventListener('click', function () { go(page - 1); restart(); });
            if (next) next.addEventListener('click', function () { go(page + 1); restart(); });
            el.addEventListener('mouseenter', function () { clearInterval(timer); });
            el.addEventListener('mouseleave', restart);
            var rt; window.addEventListener('resize', function () { clearTimeout(rt); rt = setTimeout(apply, 200); });
            apply(); restart();
        }
        var els = document.querySelectorAll('.yk-pc');
        for (var i = 0; i < els.length; i++) init(els[i]);
    })();
    </script>
    <?php
    return (string) ob_get_clean();
}, 10);
