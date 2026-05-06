<?php
/**
 * Yikai CMS - 产品详情页
 *
 * PHP 8.0+
 */

declare(strict_types=1);

require_once __DIR__ . '/includes/init.php';

HtmlCache::start(600);

$productId = getInt('id');
$slug = get('slug');

// 通过ID或slug获取产品
if ($slug) {
    $product = productModel()->findBySlug($slug);
} elseif ($productId > 0) {
    $product = productModel()->getPublished($productId);
} else {
    $product = null;
}

if (!$product) {
    header('HTTP/1.1 404 Not Found');
    exit(__('error_product_not_found'));
}

// 增加浏览量
addProductViews((int)$product['id']);

// 页面信息
$pageTitle = $product['title'];
$pageKeywords = $product['tags'] ?: config('site_keywords');
$pageDescription = $product['summary'] ?: cutStr(strip_tags($product['content']), 150);

// 获取产品分类
$productCategory = null;
if ($product['category_id'] > 0) {
    $productCategory = getProductCategory((int)$product['category_id']);
}

// 获取相关产品
$relatedProducts = [];
if ($product['category_id'] > 0) {
    $relatedProducts = productModel()->getRelated((int)$product['category_id'], (int)$product['id']);
}

// 获取上一个/下一个产品
$prevProduct = productModel()->getPrev((int)$product['category_id'], (int)$product['id']);
$nextProduct = productModel()->getNext((int)$product['category_id'], (int)$product['id']);

// 解析产品图片组（兼容JSON数组和换行分隔两种格式）
$productImages = [];
if ($product['images']) {
    $decoded = json_decode($product['images'], true);
    if (is_array($decoded)) {
        $productImages = $decoded;
    } else {
        // 换行分隔格式
        $productImages = array_filter(array_map('trim', explode("\n", $product['images'])));
    }
}
// 如果有封面图，添加到图片组开头
if ($product['cover'] && !in_array($product['cover'], $productImages)) {
    array_unshift($productImages, $product['cover']);
}

// 解析规格参数
$specs = [];
if ($product['specs']) {
    // 尝试JSON解析
    $specsData = json_decode($product['specs'], true);
    if ($specsData) {
        $specs = $specsData;
    } else {
        // 按行解析 key:value 格式
        $lines = explode("\n", $product['specs']);
        foreach ($lines as $line) {
            $line = trim($line);
            if (strpos($line, ':') !== false) {
                list($key, $value) = explode(':', $line, 2);
                $specs[] = ['name' => trim($key), 'value' => trim($value)];
            }
        }
    }
}

// 获取导航
$navChannels = getNavChannels();

// 获取产品中心栏目（用于面包屑）
$productChannel = getChannelBySlug('product');

// 当前菜单高亮
$currentSlug = 'product';

// SEO: OpenGraph & JSON-LD
$ogType = 'product';
$siteUrl = rtrim(config('site_url', SITE_URL), '/');
$canonicalUrl = $siteUrl . productUrl($product);
if (!empty($product['cover'])) {
    $ogImage = $product['cover'];
}
$jsonLd = [
    '@context' => 'https://schema.org',
    '@type' => 'Product',
    'name' => $product['title'],
    'description' => $pageDescription,
    'url' => $canonicalUrl,
];
if (!empty($product['cover'])) {
    $jsonLd['image'] = $siteUrl . $product['cover'];
}
if (!empty($product['price']) && $product['price'] > 0) {
    $jsonLd['offers'] = [
        '@type' => 'Offer',
        'price' => $product['price'],
        'priceCurrency' => 'CNY',
        'availability' => 'https://schema.org/InStock',
    ];
}

// 引入头部
require_once theme_path('layouts/header.php');
?>

<!-- 面包屑 -->
<div class="bg-gray-100 py-4">
    <div class="container mx-auto px-4">
        <div class="flex items-center gap-2 text-sm text-gray-600">
            <a href="/" class="hover:text-primary"><?php echo __('breadcrumb_home'); ?></a>
            <span>/</span>
            <?php if ($productChannel): ?>
            <a href="<?php echo channelUrl($productChannel); ?>" class="hover:text-primary">
                <?php echo e($productChannel['name']); ?>
            </a>
            <span>/</span>
            <?php endif; ?>
            <?php if ($productCategory): ?>
            <a href="<?php echo productCategoryUrl($productCategory); ?>" class="hover:text-primary">
                <?php echo e($productCategory['name']); ?>
            </a>
            <span>/</span>
            <?php endif; ?>
            <span class="text-primary"><?php echo e($product['title']); ?></span>
        </div>
    </div>
</div>

<!-- 产品详情 -->
<section class="py-12">
    <div class="container mx-auto px-4">
        <div class="bg-white rounded-lg shadow overflow-hidden">
            <!-- 产品主体 -->
            <div class="flex flex-col lg:flex-row">
                <!-- 左侧图片 -->
                <div class="lg:w-1/2 p-6">
                    <?php if (!empty($productImages)): ?>
                    <!-- 主图（点击打开 lightbox） -->
                    <div class="aspect-square overflow-hidden rounded-lg bg-gray-100 mb-4 relative group cursor-zoom-in"
                         onclick="openLightbox(0)">
                        <img loading="lazy" src="<?php echo e($productImages[0]); ?>" alt="<?php echo e($product['title']); ?>"
                             id="mainImage" class="w-full h-full object-contain transition-transform group-hover:scale-105">
                        <!-- 放大镜图标 -->
                        <div class="absolute top-3 right-3 bg-black/50 text-white rounded-full p-2 opacity-0 group-hover:opacity-100 transition">
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 3h6m0 0v6m0-6L14 10M9 21H3m0 0v-6m0 6l7-7"/>
                            </svg>
                        </div>
                        <?php if (count($productImages) > 1): ?>
                        <div class="absolute bottom-3 right-3 bg-black/60 text-white text-xs px-2 py-1 rounded-full">
                            1 / <?php echo count($productImages); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <!-- 缩略图 -->
                    <?php if (count($productImages) > 1): ?>
                    <div class="flex gap-2 overflow-x-auto">
                        <?php foreach ($productImages as $i => $img): ?>
                        <button type="button" onclick="changeImage(<?php echo $i; ?>)"
                                data-idx="<?php echo $i; ?>"
                                class="thumb-btn flex-shrink-0 w-20 h-20 border-2 rounded overflow-hidden hover:border-primary transition <?php echo $i === 0 ? 'border-primary' : 'border-gray-200'; ?>">
                            <img loading="lazy" src="<?php echo e($img); ?>" alt="" class="w-full h-full object-cover">
                        </button>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>
                    <?php else: ?>
                    <div class="aspect-square bg-gray-200 rounded-lg flex items-center justify-center text-gray-400">
                        <?php echo __('no_image'); ?>
                    </div>
                    <?php endif; ?>
                </div>

                <!-- 右侧信息 -->
                <div class="lg:w-1/2 p-6 lg:border-l">
                    <h1 class="text-2xl font-bold text-dark mb-2"><?php echo e($product['title']); ?></h1>

                    <?php if ($product['subtitle']): ?>
                    <p class="text-gray-500 mb-4"><?php echo e($product['subtitle']); ?></p>
                    <?php endif; ?>

                    <?php if ($product['model']): ?>
                    <p class="text-sm text-gray-500 mb-4">
                        <?php echo __('product_model'); ?>: <span class="text-dark"><?php echo e($product['model']); ?></span>
                    </p>
                    <?php endif; ?>

                    <?php if (config('show_price', '0') === '1' && $product['price'] > 0): ?>
                    <div class="mb-6">
                        <span class="text-3xl font-bold text-primary">&yen;<?php echo number_format((float)$product['price'], 2); ?></span>
                        <?php if ($product['market_price'] > $product['price']): ?>
                        <span class="text-gray-400 line-through ml-2">&yen;<?php echo number_format((float)$product['market_price'], 2); ?></span>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>

                    <?php if ($product['summary']): ?>
                    <div class="text-gray-600 mb-6 leading-relaxed">
                        <?php echo nl2br(e($product['summary'])); ?>
                    </div>
                    <?php endif; ?>

                    <!-- 标签 -->
                    <?php if ($product['tags']): ?>
                    <div class="flex flex-wrap gap-2 mb-6">
                        <?php foreach (explode(',', $product['tags']) as $tag): ?>
                        <span class="px-3 py-1 bg-gray-100 text-gray-600 text-sm rounded-full">
                            <?php echo e(trim($tag)); ?>
                        </span>
                        <?php endforeach; ?>
                    </div>
                    <?php endif; ?>

                    <!-- 咨询信息 -->
                    <div class="border-t pt-5 mt-2 space-y-3">
                        <?php if (config('contact_phone')): ?>
                        <div class="flex items-center gap-3 text-sm text-gray-600">
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 5a2 2 0 012-2h3.28a1 1 0 01.948.684l1.498 4.493a1 1 0 01-.502 1.21l-2.257 1.13a11.042 11.042 0 005.516 5.516l1.13-2.257a1 1 0 011.21-.502l4.493 1.498a1 1 0 01.684.949V19a2 2 0 01-2 2h-1C9.716 21 3 14.284 3 6V5z"></path></svg>
                            <span><?php echo __('product_hotline'); ?>：<a href="tel:<?php echo e(config('contact_phone')); ?>" class="text-dark font-medium hover:text-primary"><?php echo e(config('contact_phone')); ?></a></span>
                        </div>
                        <?php endif; ?>
                        <?php if (config('contact_email')): ?>
                        <div class="flex items-center gap-3 text-sm text-gray-600">
                            <svg class="w-4 h-4 text-gray-400 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z"></path></svg>
                            <span><?php echo __('product_email'); ?>：<a href="mailto:<?php echo e(config('contact_email')); ?>" class="text-dark hover:text-primary"><?php echo e(config('contact_email')); ?></a></span>
                        </div>
                        <?php endif; ?>
                        <a href="/contact.php" class="inline-flex items-center gap-2 text-sm text-primary hover:text-secondary font-medium mt-1">
                            <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12h.01M12 12h.01M16 12h.01M21 12c0 4.418-4.03 8-9 8a9.863 9.863 0 01-4.255-.949L3 20l1.395-3.72C3.512 15.042 3 13.574 3 12c0-4.418 4.03-8 9-8s9 3.582 9 8z"></path></svg>
                            <?php echo __('product_online_inquiry'); ?>
                            <svg class="w-3 h-3" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path></svg>
                        </a>
                    </div>

                    <!-- 产品询盘表单 -->
                    <div class="border-t pt-5 mt-4">
                        <h3 class="text-sm font-bold text-dark mb-3 flex items-center gap-2">
                            <svg class="w-4 h-4 text-primary" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z"></path></svg>
                            <?php echo __('product_inquiry'); ?>
                        </h3>
                        <form id="inquiryForm" class="space-y-3">
                            <input type="hidden" name="form_slug" value="product-inquiry">
                            <input type="hidden" name="product_id" value="<?php echo (int)$product['id']; ?>">
                            <input type="hidden" name="product_title" value="<?php echo e($product['title']); ?>">
                            <div class="grid grid-cols-2 gap-3">
                                <input type="text" name="name" required placeholder="<?php echo __('product_field_name_ph'); ?>"
                                       class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none">
                                <input type="tel" name="phone" required placeholder="<?php echo __('product_field_phone_ph'); ?>"
                                       class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none">
                            </div>
                            <div class="grid grid-cols-2 gap-3">
                                <input type="email" name="email" placeholder="<?php echo __('product_field_email_ph'); ?>"
                                       class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none">
                                <input type="text" name="company" placeholder="<?php echo __('product_field_company_ph'); ?>"
                                       class="px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none">
                            </div>
                            <textarea name="content" required rows="3" placeholder="<?php echo __('product_field_msg_ph'); ?>"
                                      class="w-full px-3 py-2 border border-gray-300 rounded text-sm focus:ring-2 focus:ring-primary/30 focus:border-primary outline-none resize-y"><?php echo e(sprintf(__('product_default_inq_msg'), $product['title'])); ?></textarea>
                            <button type="submit" id="inquiryBtn"
                                    class="w-full bg-primary hover:bg-secondary text-white py-2.5 rounded text-sm font-medium transition">
                                <?php echo __('product_btn_submit_inq'); ?>
                            </button>
                            <p id="inquiryMsg" class="text-sm text-center hidden"></p>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Tab 切换区 -->
            <?php
            $hasSpecs = !empty($specs);
            $hasContent = !empty($product['content']);
            $tabCount = ($hasSpecs ? 1 : 0) + ($hasContent ? 1 : 0);
            ?>
            <?php if ($tabCount > 0): ?>
            <div class="border-t">
                <!-- Tab 导航 -->
                <div class="flex border-b bg-gray-50" id="productTabs">
                    <?php if ($hasContent): ?>
                    <button type="button" class="product-tab px-6 py-4 font-bold text-primary border-b-2 border-primary" data-tab="detail">
                        <?php echo __('product_tab_detail'); ?>
                    </button>
                    <?php endif; ?>
                    <?php if ($hasSpecs): ?>
                    <button type="button" class="product-tab px-6 py-4 font-bold text-gray-500 hover:text-primary border-b-2 border-transparent" data-tab="specs">
                        规格参数
                    </button>
                    <?php endif; ?>
                </div>

                <!-- Tab 内容 -->
                <?php if ($hasContent): ?>
                <div class="tab-panel p-6 prose prose-lg max-w-none" id="tab-detail">
                    <?php echo sanitizeHtml($product['content']); ?>
                </div>
                <?php endif; ?>

                <?php if ($hasSpecs): ?>
                <div class="tab-panel p-6 hidden" id="tab-specs">
                    <table class="w-full">
                        <tbody class="divide-y">
                            <?php foreach ($specs as $spec): ?>
                            <tr>
                                <td class="py-3 w-1/4 text-gray-500 font-medium"><?php echo e($spec['name'] ?? ''); ?></td>
                                <td class="py-3 text-dark"><?php echo e($spec['value'] ?? ''); ?></td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>

        <!-- 上一个/下一个产品 -->
        <?php if ($prevProduct || $nextProduct): ?>
        <div class="mt-8 grid grid-cols-1 md:grid-cols-2 gap-4">
            <?php if ($prevProduct): ?>
            <a href="<?php echo productUrl($prevProduct); ?>" class="flex items-center gap-4 bg-white rounded-lg shadow p-4 hover:shadow-lg transition group">
                <svg class="w-5 h-5 text-gray-400 group-hover:text-primary flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"></path>
                </svg>
                <?php if ($prevProduct['cover']): ?>
                <img loading="lazy" src="<?php echo e($prevProduct['cover']); ?>" alt="" class="w-16 h-16 object-cover rounded flex-shrink-0">
                <?php endif; ?>
                <div class="min-w-0">
                    <div class="text-xs text-gray-400 mb-1">上一个产品</div>
                    <div class="font-medium text-dark group-hover:text-primary transition truncate"><?php echo e($prevProduct['title']); ?></div>
                </div>
            </a>
            <?php else: ?>
            <div></div>
            <?php endif; ?>

            <?php if ($nextProduct): ?>
            <a href="<?php echo productUrl($nextProduct); ?>" class="flex items-center gap-4 bg-white rounded-lg shadow p-4 hover:shadow-lg transition group justify-end text-right">
                <div class="min-w-0">
                    <div class="text-xs text-gray-400 mb-1">下一个产品</div>
                    <div class="font-medium text-dark group-hover:text-primary transition truncate"><?php echo e($nextProduct['title']); ?></div>
                </div>
                <?php if ($nextProduct['cover']): ?>
                <img loading="lazy" src="<?php echo e($nextProduct['cover']); ?>" alt="" class="w-16 h-16 object-cover rounded flex-shrink-0">
                <?php endif; ?>
                <svg class="w-5 h-5 text-gray-400 group-hover:text-primary flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"></path>
                </svg>
            </a>
            <?php endif; ?>
        </div>
        <?php endif; ?>

        <!-- 相关产品 -->
        <?php if (!empty($relatedProducts)): ?>
        <div class="mt-12">
            <h2 class="text-2xl font-bold text-dark mb-6">相关产品</h2>
            <div class="grid grid-cols-2 md:grid-cols-4 gap-6">
                <?php foreach ($relatedProducts as $item): ?>
                <a href="<?php echo productUrl($item); ?>" class="group bg-white rounded-lg overflow-hidden shadow hover:shadow-lg transition">
                    <div class="aspect-[4/3] overflow-hidden">
                        <?php if ($item['cover']): ?>
                        <img loading="lazy" src="<?php echo e(thumbnail($item['cover'], 'medium')); ?>" alt="<?php echo e($item['title']); ?>"
                             class="w-full h-full object-cover group-hover:scale-110 transition duration-500">
                        <?php else: ?>
                        <div class="w-full h-full bg-gray-200 flex items-center justify-center text-gray-400">
                            <?php echo __('no_image'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <div class="p-4">
                        <h3 class="font-medium text-dark group-hover:text-primary transition line-clamp-2">
                            <?php echo e($item['title']); ?>
                        </h3>
                        <?php if (config('show_price', '0') === '1' && $item['price'] > 0): ?>
                        <div class="mt-2 text-primary font-bold">&yen;<?php echo number_format((float)$item['price'], 2); ?></div>
                        <?php endif; ?>
                    </div>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</section>

<?php if (!empty($productImages)): ?>
<!-- Lightbox 画廊 -->
<div id="product-lightbox" class="hidden fixed inset-0 z-[9999] bg-black/90 items-center justify-center" onclick="if(event.target === this) closeLightbox()">
    <!-- 关闭 -->
    <button type="button" onclick="closeLightbox()" class="absolute top-4 right-4 text-white/80 hover:text-white p-2" aria-label="<?php echo __('lightbox_close'); ?>">
        <svg class="w-8 h-8" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"/>
        </svg>
    </button>
    <!-- 计数 -->
    <div id="lightbox-counter" class="absolute top-5 left-5 text-white/80 text-sm font-medium">1 / <?php echo count($productImages); ?></div>

    <?php if (count($productImages) > 1): ?>
    <!-- 上一张 -->
    <button type="button" onclick="lightboxPrev()" class="absolute left-4 top-1/2 -translate-y-1/2 text-white/80 hover:text-white bg-black/40 hover:bg-black/60 rounded-full p-3" aria-label="上一张">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 19l-7-7 7-7"/>
        </svg>
    </button>
    <!-- 下一张 -->
    <button type="button" onclick="lightboxNext()" class="absolute right-4 top-1/2 -translate-y-1/2 text-white/80 hover:text-white bg-black/40 hover:bg-black/60 rounded-full p-3" aria-label="下一张">
        <svg class="w-6 h-6" fill="none" stroke="currentColor" viewBox="0 0 24 24">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
        </svg>
    </button>
    <?php endif; ?>

    <!-- 主图 -->
    <img id="lightbox-img" src="<?php echo e($productImages[0]); ?>" alt="<?php echo e($product['title']); ?>"
         class="max-w-[90vw] max-h-[80vh] object-contain select-none">

    <?php if (count($productImages) > 1): ?>
    <!-- 底部缩略图 -->
    <div class="absolute bottom-4 left-1/2 -translate-x-1/2 flex gap-2 max-w-[90vw] overflow-x-auto px-4 pb-1">
        <?php foreach ($productImages as $i => $img): ?>
        <button type="button" onclick="event.stopPropagation(); currentImageIdx=<?php echo $i; ?>; renderLightbox(); changeImage(<?php echo $i; ?>);"
                class="lb-thumb flex-shrink-0 w-16 h-16 rounded overflow-hidden transition <?php echo $i === 0 ? 'ring-2 ring-white' : 'opacity-50'; ?>">
            <img src="<?php echo e($img); ?>" alt="" class="w-full h-full object-cover">
        </button>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
<?php endif; ?>

<script>
// 产品图片数组
var productImages = <?php echo json_encode(array_values($productImages), JSON_UNESCAPED_SLASHES); ?>;
var currentImageIdx = 0;

function changeImage(idx) {
    // 兼容旧签名（字符串 src）
    if (typeof idx === 'string') {
        idx = productImages.indexOf(idx);
        if (idx < 0) return;
    }
    if (idx < 0 || idx >= productImages.length) return;
    currentImageIdx = idx;
    var main = document.getElementById('mainImage');
    if (main) main.src = productImages[idx];
    document.querySelectorAll('.thumb-btn').forEach(function(btn) {
        var i = parseInt(btn.dataset.idx, 10);
        if (i === idx) {
            btn.classList.remove('border-gray-200');
            btn.classList.add('border-primary');
        } else {
            btn.classList.remove('border-primary');
            btn.classList.add('border-gray-200');
        }
    });
    // 同步主图右下角的"1 / N"
    var counter = document.querySelector('#mainImage').parentElement.querySelector('.absolute.bottom-3');
    if (counter) counter.textContent = (idx + 1) + ' / ' + productImages.length;
}

// ============ Lightbox 画廊 ============
function openLightbox(idx) {
    if (!productImages.length) return;
    currentImageIdx = idx || 0;
    var lb = document.getElementById('product-lightbox');
    if (!lb) return;
    lb.classList.remove('hidden');
    lb.classList.add('flex');
    renderLightbox();
    document.body.style.overflow = 'hidden';
}
function closeLightbox() {
    var lb = document.getElementById('product-lightbox');
    if (!lb) return;
    lb.classList.add('hidden');
    lb.classList.remove('flex');
    document.body.style.overflow = '';
}
function lightboxPrev() {
    currentImageIdx = (currentImageIdx - 1 + productImages.length) % productImages.length;
    renderLightbox();
    changeImage(currentImageIdx);
}
function lightboxNext() {
    currentImageIdx = (currentImageIdx + 1) % productImages.length;
    renderLightbox();
    changeImage(currentImageIdx);
}
function renderLightbox() {
    var img = document.getElementById('lightbox-img');
    var cnt = document.getElementById('lightbox-counter');
    if (img) img.src = productImages[currentImageIdx];
    if (cnt) cnt.textContent = (currentImageIdx + 1) + ' / ' + productImages.length;
    // 底部缩略图高亮
    document.querySelectorAll('.lb-thumb').forEach(function(t, i) {
        t.classList.toggle('ring-2', i === currentImageIdx);
        t.classList.toggle('ring-white', i === currentImageIdx);
        t.classList.toggle('opacity-50', i !== currentImageIdx);
    });
}
// 键盘操作
document.addEventListener('keydown', function(e) {
    var lb = document.getElementById('product-lightbox');
    if (!lb || lb.classList.contains('hidden')) return;
    if (e.key === 'Escape') closeLightbox();
    else if (e.key === 'ArrowLeft') lightboxPrev();
    else if (e.key === 'ArrowRight') lightboxNext();
});
// 触摸滑动
(function() {
    var lb = document.getElementById('product-lightbox');
    if (!lb) return;
    var sx = 0;
    lb.addEventListener('touchstart', function(e) { sx = e.touches[0].clientX; }, { passive: true });
    lb.addEventListener('touchend', function(e) {
        var dx = e.changedTouches[0].clientX - sx;
        if (Math.abs(dx) > 40) dx > 0 ? lightboxPrev() : lightboxNext();
    });
})();

// Tab 切换
document.querySelectorAll('.product-tab').forEach(function(tab) {
    tab.addEventListener('click', function() {
        var target = this.dataset.tab;
        document.querySelectorAll('.product-tab').forEach(function(t) {
            t.classList.remove('text-primary', 'border-primary');
            t.classList.add('text-gray-500', 'border-transparent');
        });
        this.classList.remove('text-gray-500', 'border-transparent');
        this.classList.add('text-primary', 'border-primary');
        document.querySelectorAll('.tab-panel').forEach(function(p) {
            p.classList.add('hidden');
        });
        document.getElementById('tab-' + target).classList.remove('hidden');
    });
});

// 产品询盘表单提交
document.getElementById('inquiryForm').addEventListener('submit', function(e) {
    e.preventDefault();
    var btn = document.getElementById('inquiryBtn');
    var msg = document.getElementById('inquiryMsg');
    btn.disabled = true;
    btn.textContent = '<?php echo __("product_submitting"); ?>';
    msg.classList.add('hidden');

    var formData = new FormData(this);
    fetch('/form_submit.php', { method: 'POST', body: formData })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            msg.classList.remove('hidden');
            if (data.code === 0) {
                msg.className = 'text-sm text-center text-green-600';
                msg.textContent = data.msg;
                document.getElementById('inquiryForm').reset();
            } else {
                msg.className = 'text-sm text-center text-red-600';
                msg.textContent = data.msg;
            }
            btn.disabled = false;
            btn.textContent = '<?php echo __("product_btn_submit_inq"); ?>';
        })
        .catch(function(err) {
            msg.classList.remove('hidden');
            msg.className = 'text-sm text-center text-red-600';
            msg.textContent = '<?php echo __("product_network_error"); ?>';
            btn.disabled = false;
            btn.textContent = '<?php echo __("product_btn_submit_inq"); ?>';
        });
});
</script>

<?php require_once theme_path('layouts/footer.php'); ?>
<?php HtmlCache::end(); ?>
