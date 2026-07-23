<?php
/**
 * 首页产品轮播插件
 *
 * 通过核心的首页版块扩展钩子接入，无需改动核心：
 *   home_block_types      —— 注册「产品轮播」版块类型（出现在首页设置里）
 *   home_block_config_ui  —— 后台配置 UI：手动挑选产品 + 标题 + 每屏个数
 *   home_settings_footer  —— 注入采集器（把选中的 product_ids 收进 home_blocks_config）+ 增删行脚本
 *   home_block_render     —— 前台渲染：横向 scroll-snap 轮播（自包含内联样式，不依赖主题 CSS）
 *
 * 版块配置存于 home_blocks_config 的条目：
 *   {"type":"product_carousel","enabled":true,"title":"精选产品","per_row":4,"product_ids":[12,8,25]}
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
        'plugin'     => true,   // 让核心把它作为「禁用」卡片注入首页设置，管理员开启即用
        'keys'       => [],
    ];
    return $blockMeta;
});

// ── 2. 后台配置 UI ────────────────────────────────────────────
add_action('home_block_config_ui', function (string $type, array $block, ?array $meta): void {
    if ($type !== 'product_carousel') {
        return;
    }
    $selectedIds = array_values(array_filter(array_map('intval', (array) ($block['product_ids'] ?? []))));
    $title  = (string) ($block['title'] ?? '');
    $perRow = (int) ($block['per_row'] ?? 4);

    // 供选择的产品（已发布、未删除；上限 500，超出请用推荐/分类版块）
    $products = productModel()->getList(0, 500, 0, []);
    $optionsHtml = '<option value="">' . '— 选择产品 —' . '</option>';
    foreach ($products as $p) {
        $optionsHtml .= '<option value="' . (int) $p['id'] . '">' . e($p['title']) . '</option>';
    }
    ?>
    <div class="bg-gray-50 rounded-lg p-4 space-y-4" data-pc-config>
        <h4 class="text-xs font-medium text-gray-400 uppercase tracking-wide"><?php echo '产品轮播'; ?></h4>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="text-xs text-gray-500 block mb-1"><?php echo '标题'; ?></label>
                <input type="text" class="pc-title w-full border rounded px-2 py-1.5 text-xs" value="<?php echo e($title); ?>"
                       placeholder="<?php echo '标题'; ?>">
            </div>
            <div>
                <label class="text-xs text-gray-500 block mb-1"><?php echo '每屏个数'; ?> <span class="text-gray-300">(1-6)</span></label>
                <input type="number" min="1" max="6" class="pc-perrow w-full border rounded px-2 py-1.5 text-xs" value="<?php echo $perRow ?: 4; ?>">
            </div>
        </div>
        <div>
            <label class="text-xs text-gray-500 block mb-2"><?php echo '选择产品（顺序即展示顺序）'; ?></label>
            <div class="pc-list space-y-2">
                <?php foreach ($selectedIds as $sid): ?>
                <div class="pc-row flex gap-2 items-center">
                    <select class="pc-select flex-1 border rounded px-2 py-1.5 text-xs bg-white">
                        <?php
                        // 逐行渲染选项并选中当前值
                        echo str_replace('value="' . $sid . '"', 'value="' . $sid . '" selected', $optionsHtml);
                        ?>
                    </select>
                    <button type="button" class="pc-del text-red-400 hover:text-red-600 text-xs px-2 py-1">✕</button>
                </div>
                <?php endforeach; ?>
            </div>
            <button type="button" class="pc-add mt-2 text-primary hover:underline text-sm">+ <?php echo '添加产品'; ?></button>
            <!-- 供 JS 克隆的空行模板 -->
            <template class="pc-tpl">
                <div class="pc-row flex gap-2 items-center">
                    <select class="pc-select flex-1 border rounded px-2 py-1.5 text-xs bg-white"><?php echo $optionsHtml; ?></select>
                    <button type="button" class="pc-del text-red-400 hover:text-red-600 text-xs px-2 py-1">✕</button>
                </div>
            </template>
        </div>
    </div>
    <?php
}, 10);

// ── 3. 后台 JS：采集器 + 增删行 ───────────────────────────────
add_action('home_settings_footer', function (): void {
    ?>
    <script>
    (function () {
        // 采集器：核心 collectBlocksConfig 会按 type 调用它，把配置写回 home_blocks_config
        window.homeBlockCollectors = window.homeBlockCollectors || {};
        window.homeBlockCollectors['product_carousel'] = function (card, item) {
            var t = card.querySelector('.pc-title');
            var pr = card.querySelector('.pc-perrow');
            if (t) item.title = t.value.trim();
            if (pr) item.per_row = parseInt(pr.value) || 4;
            item.product_ids = Array.prototype.slice.call(card.querySelectorAll('.pc-select'))
                .map(function (s) { return parseInt(s.value); })
                .filter(function (v) { return v > 0; });
        };
        // 增 / 删 产品行（事件委托）
        document.addEventListener('click', function (e) {
            var add = e.target.closest ? e.target.closest('.pc-add') : null;
            if (add) {
                var wrap = add.closest('[data-pc-config]');
                var tpl = wrap && wrap.querySelector('.pc-tpl');
                var list = wrap && wrap.querySelector('.pc-list');
                if (tpl && list) { list.appendChild(tpl.content.cloneNode(true)); }
                return;
            }
            var del = e.target.closest ? e.target.closest('.pc-del') : null;
            if (del) { var row = del.closest('.pc-row'); if (row) row.remove(); }
        });
    })();
    </script>
    <?php
}, 10);

// ── 4. 前台渲染：横向 scroll-snap 轮播（自包含样式）───────────
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
        $p = productModel()->getPublished($pid);   // 仅已发布、未删除；按选择顺序
        if ($p) { $products[] = $p; }
    }
    if (empty($products)) {
        return $html;
    }

    $title   = trim((string) ($block['title'] ?? ''));
    $perRow  = max(1, min(6, (int) ($block['per_row'] ?? 4)));
    $bgColor = (string) ($block['bg_color'] ?? '#ffffff');
    // 每屏个数 → 卡片宽度（桌面）；移动端固定露出 ~1.3 张引导横滑
    $cardW   = 'calc((100% - ' . (($perRow - 1) * 24) . 'px) / ' . $perRow . ')';
    $primary = (string) config('primary_color', '#3B82F6');

    ob_start();
    ?>
    <section class="yk-pc" style="padding:48px 0;background:<?php echo e($bgColor); ?>;">
        <div style="max-width:1200px;margin:0 auto;padding:0 16px;">
            <?php if ($title !== ''): ?>
            <h2 style="text-align:center;font-size:1.75rem;font-weight:700;color:#1e293b;margin:0 0 32px;"><?php echo e($title); ?></h2>
            <?php endif; ?>
            <div class="yk-pc-track" style="display:flex;gap:24px;overflow-x:auto;scroll-snap-type:x mandatory;padding-bottom:8px;-webkit-overflow-scrolling:touch;">
                <?php foreach ($products as $p): ?>
                <a href="<?php echo productUrl($p); ?>" class="yk-pc-card"
                   style="scroll-snap-align:start;flex:0 0 var(--yk-pc-w);max-width:var(--yk-pc-w);display:block;background:#fff;border-radius:12px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);text-decoration:none;color:inherit;transition:box-shadow .3s;">
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
                <?php endforeach; ?>
            </div>
        </div>
        <style>
            .yk-pc .yk-pc-card { --yk-pc-w: 78%; }
            @media (min-width: 768px) { .yk-pc .yk-pc-card { --yk-pc-w: <?php echo $cardW; ?>; } }
            .yk-pc .yk-pc-card:hover { box-shadow: 0 8px 24px rgba(0,0,0,.12); }
            .yk-pc-track::-webkit-scrollbar { height: 6px; }
            .yk-pc-track::-webkit-scrollbar-thumb { background: rgba(0,0,0,.15); border-radius: 3px; }
        </style>
    </section>
    <?php
    return ob_get_clean();
}, 10);
