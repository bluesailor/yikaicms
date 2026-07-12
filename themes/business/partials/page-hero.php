<?php
/**
 * Business 主题 — 内页顶部 banner（两段式）
 *
 *   @var array $channel          ['name','description','image','parent_id']
 *   @var array $breadcrumbItems  面包屑数据
 *
 * 结构：
 *   ┌──────────────────────────────────┐
 *   │ Breadcrumb 行（灰色带，对比明显）   │  ← bg-slate-100
 *   ├──────────────────────────────────┤
 *   │ 标题 + 描述（白底/封面图）          │  ← bg-white 或图片
 *   └──────────────────────────────────┘
 *
 * 之前用整段 hero 渐变，看不出来；现在 breadcrumb 单独一条灰色带，
 * 跟下方标题白底有清晰分隔。
 */
?>
<?php
// 头部背景：栏目自带 image 优先；否则用全局默认头图（后台设置 page_hero_default_bg）。首页不走本 partial。
$heroBg = ($channel['image'] ?? '') ?: (string) config('page_hero_default_bg', '');
?>
<?php if (!empty($heroBg)): ?>

<!-- 有封面图时：breadcrumb 仍用浅色带（在图上方），标题压在图上 -->
<div class="bg-slate-100 border-b border-slate-200">
    <div class="container mx-auto px-4 py-3">
        <?php $style = 'default'; require theme_path('partials/breadcrumb.php'); ?>
    </div>
</div>
<section class="relative py-20 bg-cover bg-center" style="background-image: url('<?php echo e($heroBg); ?>')">
    <div class="absolute inset-0 bg-slate-900/70"></div>
    <div aria-hidden class="absolute inset-x-0 bottom-0 h-1 bg-gradient-to-r from-primary via-secondary to-primary"></div>

    <div class="container mx-auto px-4 relative">
        <div class="max-w-3xl">
            <h1 class="text-4xl md:text-5xl font-bold text-white leading-tight tracking-tight">
                <?php echo e($channel['name']); ?>
            </h1>
            <?php if (!empty($channel['description'])): ?>
            <p class="mt-4 text-slate-200 text-base md:text-lg max-w-2xl leading-relaxed">
                <?php echo e($channel['description']); ?>
            </p>
            <?php endif; ?>
        </div>
    </div>
</section>

<?php else: ?>

<!-- 无封面图：breadcrumb 灰色带（slate-100，比 body 的 gray-50 明显深一档） -->
<div class="bg-slate-100 border-b border-slate-200">
    <div class="container mx-auto px-4 py-3">
        <?php $style = 'default'; require theme_path('partials/breadcrumb.php'); ?>
    </div>
</div>

<!-- 标题区：白底，padding 收紧（之前 py-16 上下 128px 实测留白太空） -->
<section class="relative py-6 md:py-8 bg-white border-b border-slate-200 overflow-hidden">
    <!-- 右上角主色装饰圆（高度也跟着缩，避免在低高度区块里抢戏） -->
    <div aria-hidden class="absolute -top-24 -right-24 w-64 h-64 rounded-full bg-primary/8 blur-3xl"></div>

    <div class="container mx-auto px-4 relative">
        <div class="flex items-center gap-5 max-w-4xl">
            <!-- 主色竖条作为视觉引导（高度跟标题字号匹配） -->
            <div class="hidden sm:block w-1 self-stretch min-h-[3rem] bg-gradient-to-b from-primary to-secondary flex-shrink-0"></div>
            <div class="flex-1 min-w-0">
                <h1 class="text-2xl md:text-4xl font-bold text-slate-900 leading-tight tracking-tight">
                    <?php echo e($channel['name']); ?>
                </h1>
                <?php if (!empty($channel['description'])): ?>
                <p class="mt-1.5 text-slate-500 text-sm md:text-base max-w-2xl leading-relaxed">
                    <?php echo e($channel['description']); ?>
                </p>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- 底部主色细分隔线（贯穿整宽） -->
    <div aria-hidden class="absolute inset-x-0 bottom-0 h-px bg-gradient-to-r from-transparent via-primary/40 to-transparent"></div>
</section>
<?php endif; ?>
