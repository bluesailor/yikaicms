<?php
/**
 * Yikai CMS - 公共函数库
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 防止直接访问
if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// ============================================================
// 输入输出函数
// ============================================================

/**
 * XSS过滤
 */
function e(?string $str): string
{
    return htmlspecialchars($str ?? '', ENT_QUOTES, 'UTF-8');
}

/**
 * 获取GET参数
 */
function get(string $key, mixed $default = ''): mixed
{
    return isset($_GET[$key]) ? trim((string)$_GET[$key]) : $default;
}

/**
 * 获取POST参数
 */
function post(string $key, mixed $default = ''): mixed
{
    return isset($_POST[$key]) ? trim((string)$_POST[$key]) : $default;
}

/**
 * 获取请求参数（GET或POST）
 */
function input(string $key, mixed $default = ''): mixed
{
    return isset($_REQUEST[$key]) ? trim((string)$_REQUEST[$key]) : $default;
}

/**
 * 获取整数参数
 */
function getInt(string $key, int $default = 0): int
{
    return isset($_GET[$key]) ? (int)$_GET[$key] : $default;
}

/**
 * 获取POST整数参数
 */
function postInt(string $key, int $default = 0): int
{
    return isset($_POST[$key]) ? (int)$_POST[$key] : $default;
}

/**
 * 跳转URL
 */
function redirect(string $url): never
{
    header('Location: ' . $url);
    exit;
}

/**
 * JSON响应
 */
function json(mixed $data, int $code = 200): never
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

/**
 * 成功响应
 */
function success(mixed $data = [], string $msg = '操作成功'): never
{
    json(['code' => 0, 'msg' => $msg, 'data' => $data]);
}

/**
 * 错误响应
 */
function error(string $msg = '操作失败', int $code = 1): never
{
    json(['code' => $code, 'msg' => $msg, 'data' => null]);
}

// ============================================================
// 配置函数
// ============================================================

/**
 * 获取系统配置
 */
function config(string $key, mixed $default = ''): mixed
{
    return settingModel()->get($key, $default);
}

/**
 * 获取单个设置的出厂默认值
 */
function getDefault(string $key, mixed $fallback = ''): mixed
{
    static $defaults = null;
    if ($defaults === null) {
        $all = require ROOT_PATH . '/config/defaults.php';
        $defaults = [];
        foreach ($all as $group => $items) {
            foreach ($items as $k => $item) {
                $defaults[$k] = $item['value'];
            }
        }
    }
    return $defaults[$key] ?? $fallback;
}

/**
 * 获取默认设置（按分组或全部）
 */
function getDefaults(string $group = ''): array
{
    static $allDefaults = null;
    if ($allDefaults === null) {
        $allDefaults = require ROOT_PATH . '/config/defaults.php';
    }
    return $group ? ($allDefaults[$group] ?? []) : $allDefaults;
}

// ============================================================
// Slug 生成
// ============================================================

/**
 * 根据中文标题生成 slug（取前6个字的拼音）
 */
function generateSlug(string $title, int $maxChars = 6): string
{
    require_once ROOT_PATH . '/vendor/autoload.php';
    // 取前 N 个中文字符
    $short = mb_substr(trim($title), 0, $maxChars);
    if ($short === '') {
        return '';
    }
    $pinyin = new \Overtrue\Pinyin\Pinyin();
    $slug = $pinyin->permalink($short, '-');
    // 只保留字母数字和横杠
    $slug = preg_replace('/[^a-z0-9\-]/', '', $slug);
    // 去除首尾横杠
    return trim($slug, '-');
}

/**
 * 验证并生成 slug，处理空值和去重
 *
 * @param string $input    用户输入的 slug（可为空）
 * @param string $title    标题（用于自动生成）
 * @param string $table    数据库表名（不含前缀）
 * @param int    $excludeId 排除的记录ID（编辑时）
 */
function resolveSlug(string $input, string $title, string $table, int $excludeId = 0): string
{
    if (empty($input)) {
        $slug = generateSlug($title);
        if (empty($slug)) {
            $slug = 'item-' . time();
        }
    } else {
        $slug = preg_replace('/[^a-zA-Z0-9\-]/', '', $input);
        $slug = strtolower(trim($slug, '-'));
        if (empty($slug)) {
            $slug = 'item-' . time();
        }
    }

    // 检查重复
    $where = $excludeId > 0 ? 'slug = ? AND id != ?' : 'slug = ?';
    $params = $excludeId > 0 ? [$slug, $excludeId] : [$slug];
    $exists = db()->fetchOne('SELECT id FROM ' . DB_PREFIX . $table . ' WHERE ' . $where, $params);
    if ($exists) {
        $slug = $slug . '-' . time();
    }

    return $slug;
}

// ============================================================
// 多语言函数
// ============================================================

/**
 * 获取当前语言
 */
function getLang(): string
{
    return 'zh-CN';
}

/**
 * 初始化语言（固定中文）
 */
function initLang(): void
{
    // 固定使用中文
}

/**
 * 加载语言包
 */
function loadLangData(): array
{
    global $_LANG_DATA;

    if ($_LANG_DATA !== null) {
        return $_LANG_DATA;
    }

    $langFile = ROOT_PATH . '/lang/zh-CN.php';
    $_LANG_DATA = file_exists($langFile) ? require $langFile : [];

    return $_LANG_DATA;
}

/**
 * 翻译函数
 *
 * @param string $key 翻译键
 * @param array $params 替换参数 ['name' => 'value'] 将替换 :name
 * @return string
 */
function __(string $key, array $params = []): string
{
    $data = loadLangData();
    $text = $data[$key] ?? $key;

    // 替换参数
    foreach ($params as $param => $value) {
        $text = str_replace(':' . $param, (string)$value, $text);
    }

    return $text;
}

/**
 * 翻译并转义输出
 */
function _e(string $key, array $params = []): string
{
    return e(__($key, $params));
}

// ============================================================
// 文件函数
// ============================================================

/**
 * 格式化文件大小
 */
function formatFileSize(int $bytes): string
{
    if ($bytes < 1024) {
        return $bytes . ' B';
    } elseif ($bytes < 1048576) {
        return round($bytes / 1024, 2) . ' KB';
    } elseif ($bytes < 1073741824) {
        return round($bytes / 1048576, 2) . ' MB';
    } else {
        return round($bytes / 1073741824, 2) . ' GB';
    }
}

// ============================================================
// 栏目函数
// ============================================================

/**
 * 获取栏目列表
 */
function getChannels(int $parentId = 0, bool $isNav = true): array
{
    if ($parentId < 0) {
        $conds = ['status' => 1];
        if ($isNav) $conds['is_nav'] = 1;
        return channelModel()->where($conds);
    }
    return channelModel()->getByParent($parentId, true, $isNav);
}

/**
 * 获取导航栏目（带子栏目）
 */
function getNavChannels(): array
{
    return channelModel()->getNav();
}

/**
 * 获取栏目树
 */
function getChannelTree(int $parentId = 0): array
{
    return channelModel()->getTree($parentId);
}

/**
 * 获取单个栏目
 */
function getChannel(int $id): ?array
{
    return channelModel()->findWhere(['id' => $id, 'status' => 1]);
}

/**
 * 通过slug获取栏目
 */
function getChannelBySlug(string $slug): ?array
{
    return channelModel()->findBySlug($slug);
}

/**
 * 获取子栏目ID列表（包含自身）
 */
function getChildChannelIds(int $channelId): array
{
    return channelModel()->getChildIds($channelId);
}

/**
 * 获取栏目URL（SEO友好）
 */
function channelUrl(array $channel): string
{
    if ($channel['type'] === 'link') {
        return e($channel['link_url']);
    }

    $slug = $channel['slug'] ?? '';
    if (empty($slug)) {
        if ($channel['type'] === 'page') {
            return '/page/' . $channel['id'] . '.html';
        } else {
            return '/list/' . $channel['id'] . '.html';
        }
    }

    if ($channel['type'] === 'page') {
        if (!empty($channel['parent_id'])) {
            $parent = getChannel((int)$channel['parent_id']);
            if ($parent && !empty($parent['slug'])) {
                return '/' . $parent['slug'] . '/' . $slug . '.html';
            }
        }
        return '/' . $slug . '.html';
    } else {
        return '/' . $slug . '.html';
    }
}

// ============================================================
// 内容函数
// ============================================================

/**
 * 获取内容列表
 */
function getContents(int $channelId = 0, int $limit = 10, int $offset = 0, array $where = []): array
{
    return contentModel()->getList($channelId, $limit, $offset, $where);
}

/**
 * 获取内容总数
 */
function getContentsCount(int $channelId = 0, array $where = []): int
{
    return contentModel()->getCount($channelId, $where);
}

/**
 * 获取单条内容
 */
function getContent(int $id): ?array
{
    return contentModel()->getPublished($id);
}

/**
 * 增加内容浏览量
 */
function addContentViews(int $id): int
{
    return contentModel()->incrementViews($id);
}

/**
 * 增加下载次数
 */
function addDownloadCount(int $id): int
{
    return contentModel()->incrementDownloads($id);
}

/**
 * 获取内容URL（SEO友好）
 */
function contentUrl(array $content): string
{
    $slug = $content['slug'] ?? '';
    $channelSlug = $content['channel_slug'] ?? '';
    $channelType = $content['channel_type'] ?? '';

    // 下载类型使用 /download/detail/{id}.html
    if ($channelType === 'download') {
        return '/download/detail/' . $content['id'] . '.html';
    }

    // 案例类型使用 /case/{slug}.html 或 /case/{id}.html
    if ($channelType === 'case') {
        if (!empty($slug)) {
            return '/case/' . $slug . '.html';
        }
        return '/case/' . $content['id'] . '.html';
    }

    // 如果内容和栏目都有slug，使用友好URL
    if (!empty($slug) && !empty($channelSlug)) {
        return '/' . $channelSlug . '/' . $slug . '.html';
    }

    // 否则使用ID格式
    return '/detail/' . $content['id'] . '.html';
}

/**
 * 获取招聘职位URL
 */
function jobUrl(array $job): string
{
    return '/job/' . $job['id'] . '.html';
}

// ============================================================
// 产品管理
// ============================================================

/**
 * 获取产品分类的子分类ID列表（包含自身）
 */
function getChildProductCategoryIds(int $categoryId): array
{
    return productCategoryModel()->getChildIds($categoryId);
}

/**
 * 获取产品列表
 */
function getProducts(int $categoryId = 0, int $limit = 10, int $offset = 0, array $where = []): array
{
    return productModel()->getList($categoryId, $limit, $offset, $where);
}

/**
 * 获取产品数量
 */
function getProductsCount(int $categoryId = 0, array $where = []): int
{
    return productModel()->getCount($categoryId, $where);
}

/**
 * 获取单个产品
 */
function getProduct(int $id): ?array
{
    return productModel()->getPublished($id);
}

/**
 * 增加产品浏览量
 */
function addProductViews(int $id): int
{
    return productModel()->incrementViews($id);
}

/**
 * 获取产品URL（SEO友好）
 */
function productUrl(array $product): string
{
    $slug = $product['slug'] ?? '';
    $categorySlug = $product['category_slug'] ?? '';

    // 如果产品和分类都有slug，使用友好URL
    if (!empty($slug) && !empty($categorySlug)) {
        return '/product/' . $categorySlug . '/' . $slug . '.html';
    }

    // 否则使用ID格式
    return '/product/' . $product['id'] . '.html';
}

/**
 * 获取产品分类
 */
function getProductCategory(int $id): ?array
{
    return productCategoryModel()->findWhere(['id' => $id, 'status' => 1]);
}

/**
 * 获取产品分类列表
 */
function getProductCategories(int $parentId = 0): array
{
    return productCategoryModel()->where(['parent_id' => $parentId, 'status' => 1]);
}

/**
 * 通过slug获取产品分类
 */
function getProductCategoryBySlug(string $slug): ?array
{
    return productCategoryModel()->findBySlug($slug);
}

/**
 * 获取产品分类URL
 */
function productCategoryUrl(array $category): string
{
    $slug = $category['slug'] ?? '';
    if (!empty($slug)) {
        return '/product/' . $slug . '.html';
    }
    return '/product.html?cat=' . $category['id'];
}

// ============================================================
// 轮播图和链接
// ============================================================

/**
 * 获取轮播图
 */
function getBanners(string $position = 'home', int $limit = 5): array
{
    return bannerModel()->getByPosition($position, $limit);
}

/**
 * 获取轮播图分组配置
 */
function getBannerGroup(string $slug): ?array
{
    return bannerGroupModel()->findBySlug($slug);
}

/**
 * 获取合作伙伴
 */
function getLinks(int $limit = 10): array
{
    return linkModel()->getActive($limit);
}

/**
 * 优势区块图标库
 */
function getAdvantageIcons(): array
{
    return [
        'check-circle'  => ['label' => '勾选', 'svg' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm3.707-9.293a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>'],
        'shield-check'   => ['label' => '安全', 'svg' => '<path fill-rule="evenodd" d="M2.166 4.999A11.954 11.954 0 0010 1.944 11.954 11.954 0 0017.834 5c.11.65.166 1.32.166 2.001 0 5.225-3.34 9.67-8 11.317C5.34 16.67 2 12.225 2 7c0-.682.057-1.35.166-2.001zm11.541 3.708a1 1 0 00-1.414-1.414L9 10.586 7.707 9.293a1 1 0 00-1.414 1.414l2 2a1 1 0 001.414 0l4-4z" clip-rule="evenodd"/>'],
        'academic-cap'   => ['label' => '学术', 'svg' => '<path d="M10.394 2.08a1 1 0 00-.788 0l-7 3a1 1 0 000 1.84L5.25 8.051a.999.999 0 01.356-.257l4-1.714a1 1 0 11.788 1.838L7.667 9.088l1.94.831a1 1 0 00.787 0l7-3a1 1 0 000-1.838l-7-3zM3.31 9.397L5 10.12v4.102a8.969 8.969 0 00-1.05-.174 1 1 0 01-.89-.89 11.115 11.115 0 01.25-3.762z"/>'],
        'briefcase'      => ['label' => '商务', 'svg' => '<path fill-rule="evenodd" d="M6 6V5a3 3 0 013-3h2a3 3 0 013 3v1h2a2 2 0 012 2v3.57A22.952 22.952 0 0110 13a22.95 22.95 0 01-8-1.43V8a2 2 0 012-2h2zm2-1a1 1 0 011-1h2a1 1 0 011 1v1H8V5zm1 5a1 1 0 011-1h.01a1 1 0 110 2H10a1 1 0 01-1-1z" clip-rule="evenodd"/><path d="M2 13.692V16a2 2 0 002 2h12a2 2 0 002-2v-2.308A24.974 24.974 0 0110 15c-2.796 0-5.487-.46-8-1.308z"/>'],
        'users'          => ['label' => '团队', 'svg' => '<path d="M13 6a3 3 0 11-6 0 3 3 0 016 0zM18 8a2 2 0 11-4 0 2 2 0 014 0zM14 15a4 4 0 00-8 0v3h8v-3zM6 8a2 2 0 11-4 0 2 2 0 014 0zM16 18v-3a5.972 5.972 0 00-.75-2.906A3.005 3.005 0 0119 15v3h-3zM4.75 12.094A5.973 5.973 0 004 15v3H1v-3a3 3 0 013.75-2.906z"/>'],
        'star'           => ['label' => '星标', 'svg' => '<path d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z"/>'],
        'heart'          => ['label' => '爱心', 'svg' => '<path fill-rule="evenodd" d="M3.172 5.172a4 4 0 015.656 0L10 6.343l1.172-1.171a4 4 0 115.656 5.656L10 17.657l-6.828-6.829a4 4 0 010-5.656z" clip-rule="evenodd"/>'],
        'globe'          => ['label' => '全球', 'svg' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zM4.332 8.027a6.012 6.012 0 011.912-2.706C6.512 5.73 6.974 6 7.5 6A1.5 1.5 0 019 7.5V8a2 2 0 004 0 2 2 0 011.523-1.943A5.977 5.977 0 0116 10c0 .34-.028.675-.083 1H15a2 2 0 00-2 2v2.197A5.973 5.973 0 0110 16v-2a2 2 0 00-2-2 2 2 0 01-2-2 2 2 0 00-1.668-1.973z" clip-rule="evenodd"/>'],
        'clock'          => ['label' => '时钟', 'svg' => '<path fill-rule="evenodd" d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z" clip-rule="evenodd"/>'],
        'cog'            => ['label' => '齿轮', 'svg' => '<path fill-rule="evenodd" d="M11.49 3.17c-.38-1.56-2.6-1.56-2.98 0a1.532 1.532 0 01-2.286.948c-1.372-.836-2.942.734-2.106 2.106.54.886.061 2.042-.947 2.287-1.561.379-1.561 2.6 0 2.978a1.532 1.532 0 01.947 2.287c-.836 1.372.734 2.942 2.106 2.106a1.532 1.532 0 012.287.947c.379 1.561 2.6 1.561 2.978 0a1.533 1.533 0 012.287-.947c1.372.836 2.942-.734 2.106-2.106a1.533 1.533 0 01.947-2.287c1.561-.379 1.561-2.6 0-2.978a1.532 1.532 0 01-.947-2.287c.836-1.372-.734-2.942-2.106-2.106a1.532 1.532 0 01-2.287-.947zM10 13a3 3 0 100-6 3 3 0 000 6z" clip-rule="evenodd"/>'],
        'chart-bar'      => ['label' => '图表', 'svg' => '<path d="M2 11a1 1 0 011-1h2a1 1 0 011 1v5a1 1 0 01-1 1H3a1 1 0 01-1-1v-5zM8 7a1 1 0 011-1h2a1 1 0 011 1v9a1 1 0 01-1 1H9a1 1 0 01-1-1V7zM14 4a1 1 0 011-1h2a1 1 0 011 1v12a1 1 0 01-1 1h-2a1 1 0 01-1-1V4z"/>'],
        'thumb-up'       => ['label' => '点赞', 'svg' => '<path d="M2 10.5a1.5 1.5 0 113 0v6a1.5 1.5 0 01-3 0v-6zM6 10.333v5.43a2 2 0 001.106 1.79l.05.025A4 4 0 008.943 18h5.416a2 2 0 001.962-1.608l1.2-6A2 2 0 0015.56 8H12V4a2 2 0 00-2-2 1 1 0 00-1 1v.667a4 4 0 01-.8 2.4L6.8 7.933a4 4 0 00-.8 2.4z"/>'],
        'phone'          => ['label' => '电话', 'svg' => '<path d="M2 3a1 1 0 011-1h2.153a1 1 0 01.986.836l.74 4.435a1 1 0 01-.54 1.06l-1.548.773a11.037 11.037 0 006.105 6.105l.774-1.548a1 1 0 011.059-.54l4.435.74a1 1 0 01.836.986V17a1 1 0 01-1 1h-2C7.82 18 2 12.18 2 5V3z"/>'],
        'bolt'           => ['label' => '闪电', 'svg' => '<path fill-rule="evenodd" d="M11.3 1.046A1 1 0 0112 2v5h4a1 1 0 01.82 1.573l-7 10A1 1 0 018 18v-5H4a1 1 0 01-.82-1.573l7-10a1 1 0 011.12-.38z" clip-rule="evenodd"/>'],
        'sparkles'       => ['label' => '闪烁', 'svg' => '<path fill-rule="evenodd" d="M5 2a1 1 0 011 1v1h1a1 1 0 010 2H6v1a1 1 0 01-2 0V6H3a1 1 0 010-2h1V3a1 1 0 011-1zm0 10a1 1 0 011 1v1h1a1 1 0 110 2H6v1a1 1 0 11-2 0v-1H3a1 1 0 110-2h1v-1a1 1 0 011-1zM12 2a1 1 0 01.967.744l.311 1.242 1.243.311a1 1 0 010 1.406l-1.243.311-.311 1.243a1 1 0 01-1.934 0l-.311-1.243-1.243-.311a1 1 0 010-1.406l1.243-.311.311-1.242A1 1 0 0112 2z" clip-rule="evenodd"/>'],
        'truck'          => ['label' => '物流', 'svg' => '<path d="M8 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0zM15 16.5a1.5 1.5 0 11-3 0 1.5 1.5 0 013 0z"/><path d="M3 4a1 1 0 00-1 1v10a1 1 0 001 1h1.05a2.5 2.5 0 014.9 0H10a1 1 0 001-1V5a1 1 0 00-1-1H3zM14 7a1 1 0 00-1 1v6.05A2.5 2.5 0 0115.95 16H17a1 1 0 001-1v-5a1 1 0 00-.293-.707l-2-2A1 1 0 0015 7h-1z"/>'],
    ];
}

/**
 * 获取区块背景样式
 */
function getBlockBg(array $block, string $defaultClass = ''): array
{
    $bgImage = $block['bg_image'] ?? '';
    $bgColor = $block['bg_color'] ?? '';
    $bgOpacity = (int)($block['bg_opacity'] ?? 100);
    $layout = $block['layout'] ?? 'container';
    $container = $layout === 'full' ? 'w-full px-4 md:px-8' : 'container mx-auto px-4';

    if (!$bgImage && !$bgColor) {
        return ['class' => $defaultClass, 'style' => '', 'overlay' => '', 'content' => '', 'container' => $container];
    }

    $style = '';
    $overlay = '';

    if ($bgImage) {
        $style = "background:url('" . e($bgImage) . "') center/cover no-repeat;";
    }

    if ($bgColor && ($bgImage || $bgOpacity < 100)) {
        $opacity = $bgOpacity / 100;
        $overlay = '<div class="absolute inset-0" style="background:' . e($bgColor) . ';opacity:' . $opacity . '"></div>';
    } elseif ($bgColor) {
        $style = "background:" . e($bgColor) . ";";
    } elseif ($bgImage && $bgOpacity < 100) {
        $overlayOpacity = 1 - $bgOpacity / 100;
        $overlay = '<div class="absolute inset-0 bg-black" style="opacity:' . $overlayOpacity . '"></div>';
    }

    $class = $overlay ? 'relative' : '';
    if (!empty($block['text_light'])) {
        $class .= ($class ? ' ' : '') . 'text-white';
    }

    return [
        'class'     => $class,
        'style'     => $style ? 'style="' . $style . '"' : '',
        'overlay'   => $overlay,
        'content'   => $overlay ? 'relative z-10' : '',
        'container' => $container,
    ];
}

// ============================================================
// 导航和面包屑
// ============================================================

/**
 * 生成面包屑导航
 */
function getBreadcrumb(int $channelId): array
{
    $breadcrumb = [];

    while ($channelId > 0) {
        $channel = getChannel($channelId);
        if (!$channel) break;

        array_unshift($breadcrumb, [
            'id' => $channel['id'],
            'name' => $channel['name'],
            'slug' => $channel['slug'],
            'type' => $channel['type']
        ]);

        $channelId = (int)$channel['parent_id'];
    }

    return $breadcrumb;
}

// ============================================================
// 分页
// ============================================================

/**
 * 生成分页HTML
 */
function paginate(int $total, int $perPage, int $currentPage, string $baseUrl): string
{
    $totalPages = (int)ceil($total / $perPage);
    if ($totalPages <= 1) return '';

    $separator = str_contains($baseUrl, '?') ? '&' : '?';
    $url = fn(int $page) => $baseUrl . $separator . 'page=' . $page;

    $html = '<nav class="flex justify-center"><ul class="flex gap-1">';

    // 上一页
    if ($currentPage > 1) {
        $html .= '<li><a href="' . $url($currentPage - 1) . '" class="px-3 py-2 border rounded hover:bg-gray-100">上一页</a></li>';
    }

    // 页码
    $start = max(1, $currentPage - 2);
    $end = min($totalPages, $currentPage + 2);

    if ($start > 1) {
        $html .= '<li><a href="' . $url(1) . '" class="px-3 py-2 border rounded hover:bg-gray-100">1</a></li>';
        if ($start > 2) $html .= '<li><span class="px-3 py-2">...</span></li>';
    }

    for ($i = $start; $i <= $end; $i++) {
        if ($i === $currentPage) {
            $html .= '<li><span class="px-3 py-2 border rounded bg-primary text-white">' . $i . '</span></li>';
        } else {
            $html .= '<li><a href="' . $url($i) . '" class="px-3 py-2 border rounded hover:bg-gray-100">' . $i . '</a></li>';
        }
    }

    if ($end < $totalPages) {
        if ($end < $totalPages - 1) $html .= '<li><span class="px-3 py-2">...</span></li>';
        $html .= '<li><a href="' . $url($totalPages) . '" class="px-3 py-2 border rounded hover:bg-gray-100">' . $totalPages . '</a></li>';
    }

    // 下一页
    if ($currentPage < $totalPages) {
        $html .= '<li><a href="' . $url($currentPage + 1) . '" class="px-3 py-2 border rounded hover:bg-gray-100">下一页</a></li>';
    }

    $html .= '</ul></nav>';

    return $html;
}

// ============================================================
// 字符串工具
// ============================================================

/**
 * 截取字符串
 */
function cutStr(string $str, int $length, string $suffix = '...'): string
{
    $str = strip_tags($str);
    if (mb_strlen($str, 'UTF-8') <= $length) {
        return $str;
    }
    return mb_substr($str, 0, $length, 'UTF-8') . $suffix;
}

/**
 * 格式化时间
 */
function formatTime(int|string $time, string $format = 'Y-m-d'): string
{
    if (is_string($time)) {
        $time = strtotime($time);
    }
    return date($format, $time);
}

/**
 * 友好时间显示
 */
function friendlyTime(int $time): string
{
    $diff = time() - $time;

    return match (true) {
        $diff < 60 => '刚刚',
        $diff < 3600 => floor($diff / 60) . '分钟前',
        $diff < 86400 => floor($diff / 3600) . '小时前',
        $diff < 604800 => floor($diff / 86400) . '天前',
        default => date('Y-m-d', $time)
    };
}

// ============================================================
// 安全函数
// ============================================================

/**
 * 获取客户端IP
 */
function getClientIp(): string
{
    $ip = match (true) {
        !empty($_SERVER['HTTP_X_FORWARDED_FOR']) => explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0],
        !empty($_SERVER['HTTP_CLIENT_IP']) => $_SERVER['HTTP_CLIENT_IP'],
        default => $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0'
    };
    return trim($ip);
}

/**
 * 生成CSRF Token
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * 验证CSRF Token
 */
function verifyCsrf(): bool
{
    // 支持从 POST 字段或 X-CSRF-TOKEN 请求头获取
    $token = $_POST[CSRF_TOKEN_NAME] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    if (empty($token) || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $token)) {
        error('非法请求', 403);
    }
    return true;
}

/**
 * 生成CSRF Token隐藏字段
 */
function csrfField(): string
{
    return '<input type="hidden" name="' . CSRF_TOKEN_NAME . '" value="' . csrfToken() . '">';
}

// ============================================================
// 验证函数
// ============================================================

/**
 * 验证手机号（中国）
 */
function isPhone(string $phone): bool
{
    return (bool)preg_match('/^1[3-9]\d{9}$/', $phone);
}

/**
 * 验证邮箱
 */
function isEmail(string $email): bool
{
    return (bool)filter_var($email, FILTER_VALIDATE_EMAIL);
}

// ============================================================
// 日志和上传
// ============================================================

/**
 * 记录操作日志
 */
function adminLog(string $module, string $action, string $description = ''): int|string|false
{
    if (empty($_SESSION['admin_id'])) return false;

    return adminLogModel()->log([
        'admin_id'     => $_SESSION['admin_id'],
        'admin_name'   => $_SESSION['admin_username'] ?? '',
        'module'       => $module,
        'action'       => $action,
        'description'  => $description,
        'url'          => $_SERVER['REQUEST_URI'] ?? '',
        'method'       => $_SERVER['REQUEST_METHOD'] ?? '',
        'request_data' => json_encode($_POST, JSON_UNESCAPED_UNICODE),
        'ip'           => getClientIp(),
        'user_agent'   => $_SERVER['HTTP_USER_AGENT'] ?? '',
        'created_at'   => time(),
    ]);
}

/**
 * 发送邮件
 */
function sendMail(string $to, string $subject, string $body, array $attachments = []): bool|string
{
    $smtpHost = config('smtp_host');
    $smtpPort = (int)config('smtp_port', 465);
    $smtpUser = config('smtp_user');
    $smtpPass = config('smtp_pass');
    $smtpSecure = config('smtp_secure', 'ssl');
    $mailFrom = config('mail_from', $smtpUser);
    $mailName = config('mail_from_name', config('site_name', 'Yikai CMS'));

    if (!$smtpHost || !$smtpUser || !$smtpPass) {
        return '邮件服务器未配置';
    }

    // 使用fsockopen发送邮件（无需PHPMailer）
    $errno = 0;
    $errstr = '';

    if ($smtpSecure === 'ssl') {
        $socket = @fsockopen('ssl://' . $smtpHost, $smtpPort, $errno, $errstr, 30);
    } else {
        $socket = @fsockopen($smtpHost, $smtpPort, $errno, $errstr, 30);
    }

    if (!$socket) {
        return "连接SMTP服务器失败: $errstr ($errno)";
    }

    // 读取服务器响应
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '220') {
        fclose($socket);
        return "SMTP连接错误: $response";
    }

    // EHLO
    fputs($socket, "EHLO " . gethostname() . "\r\n");
    $response = '';
    while ($line = fgets($socket, 515)) {
        $response .= $line;
        if (substr($line, 3, 1) === ' ') break;
    }

    // STARTTLS for TLS
    if ($smtpSecure === 'tls') {
        fputs($socket, "STARTTLS\r\n");
        $response = fgets($socket, 515);
        if (substr($response, 0, 3) !== '220') {
            fclose($socket);
            return "STARTTLS失败: $response";
        }
        stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);

        fputs($socket, "EHLO " . gethostname() . "\r\n");
        $response = '';
        while ($line = fgets($socket, 515)) {
            $response .= $line;
            if (substr($line, 3, 1) === ' ') break;
        }
    }

    // AUTH LOGIN
    fputs($socket, "AUTH LOGIN\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return "AUTH失败: $response";
    }

    fputs($socket, base64_encode($smtpUser) . "\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '334') {
        fclose($socket);
        return "用户名验证失败: $response";
    }

    fputs($socket, base64_encode($smtpPass) . "\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '235') {
        fclose($socket);
        return "密码验证失败: $response";
    }

    // MAIL FROM
    fputs($socket, "MAIL FROM:<$mailFrom>\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "MAIL FROM失败: $response";
    }

    // RCPT TO
    fputs($socket, "RCPT TO:<$to>\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "RCPT TO失败: $response";
    }

    // DATA
    fputs($socket, "DATA\r\n");
    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '354') {
        fclose($socket);
        return "DATA失败: $response";
    }

    // 邮件内容
    $boundary = md5(uniqid());
    $headers = "From: =?UTF-8?B?" . base64_encode($mailName) . "?= <$mailFrom>\r\n";
    $headers .= "To: <$to>\r\n";
    $headers .= "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n";
    $headers .= "MIME-Version: 1.0\r\n";
    $headers .= "Content-Type: text/html; charset=UTF-8\r\n";
    $headers .= "Content-Transfer-Encoding: base64\r\n";

    $message = $headers . "\r\n" . chunk_split(base64_encode($body)) . "\r\n.\r\n";
    fputs($socket, $message);

    $response = fgets($socket, 515);
    if (substr($response, 0, 3) !== '250') {
        fclose($socket);
        return "发送失败: $response";
    }

    // QUIT
    fputs($socket, "QUIT\r\n");
    fclose($socket);

    return true;
}

/**
 * 上传文件
 */
function uploadFile(array $file, string $type = 'images'): array
{
    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['error' => '上传失败：' . $file['error']];
    }

    // 检查文件大小
    if ($file['size'] > UPLOAD_MAX_SIZE) {
        return ['error' => '文件大小超过限制'];
    }

    // 检查文件类型
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    $allowedTypes = match ($type) {
        'images' => UPLOAD_IMAGE_TYPES,
        'files' => UPLOAD_FILE_TYPES,
        default => [...UPLOAD_IMAGE_TYPES, ...UPLOAD_FILE_TYPES]
    };

    if (!in_array($ext, $allowedTypes)) {
        return ['error' => '不支持的文件类型'];
    }

    // MIME 类型验证（防止伪造扩展名）
    $mimeMap = [
        'jpg'  => ['image/jpeg'],
        'jpeg' => ['image/jpeg'],
        'png'  => ['image/png'],
        'gif'  => ['image/gif'],
        'webp' => ['image/webp'],
        'svg'  => ['image/svg+xml', 'text/xml', 'application/xml'],
        'pdf'  => ['application/pdf'],
        'doc'  => ['application/msword'],
        'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
        'xls'  => ['application/vnd.ms-excel'],
        'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
        'ppt'  => ['application/vnd.ms-powerpoint'],
        'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
        'zip'  => ['application/zip', 'application/x-zip-compressed'],
        'rar'  => ['application/x-rar-compressed', 'application/vnd.rar'],
        '7z'   => ['application/x-7z-compressed'],
    ];
    if (isset($mimeMap[$ext])) {
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = finfo_file($finfo, $file['tmp_name']);
        finfo_close($finfo);
        if (!in_array($detectedMime, $mimeMap[$ext])) {
            return ['error' => '文件内容与扩展名不匹配'];
        }
    }

    // 图片文件必须通过 getimagesize 验证（SVG 除外）
    $imageExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (in_array($ext, $imageExts)) {
        $check = @getimagesize($file['tmp_name']);
        if ($check === false) {
            return ['error' => '无效的图片文件'];
        }
    }

    // 生成存储路径
    $dir = UPLOADS_PATH . $type . '/' . date('Ym') . '/';
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    // 生成文件名
    $filename = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $filepath = $dir . $filename;

    // 移动文件
    if (!move_uploaded_file($file['tmp_name'], $filepath)) {
        return ['error' => '保存文件失败'];
    }

    // 获取图片尺寸（已在上传前验证，此处仅取元数据）
    $width = 0;
    $height = 0;
    if (in_array($ext, $imageExts)) {
        $imageInfo = @getimagesize($filepath);
        if ($imageInfo) {
            $width = $imageInfo[0];
            $height = $imageInfo[1];
        }
    }

    // 生成缩略图
    $thumbs = [];
    if (in_array($ext, $imageExts)) {
        $thumbs = generateThumbnails($filepath, $ext);
    }

    // 返回信息
    $url = '/uploads/' . $type . '/' . date('Ym') . '/' . $filename;
    return [
        'name' => $file['name'],
        'path' => $filepath,
        'url' => $url,
        'ext' => $ext,
        'size' => $file['size'],
        'width' => $width,
        'height' => $height,
        'md5' => md5_file($filepath),
        'thumbnails' => $thumbs,
    ];
}

// ============================================================
// 缩略图系统
// ============================================================

/**
 * 缩略图尺寸配置
 * thumb: 300x300 裁剪居中（用于列表卡片）
 * medium: 800x600 等比缩放（用于详情页侧栏）
 */
define('THUMBNAIL_SIZES', [
    'thumb'  => ['width' => 300, 'height' => 300, 'crop' => true],
    'medium' => ['width' => 800, 'height' => 600, 'crop' => false],
]);

/**
 * 为上传的图片生成缩略图
 */
function generateThumbnails(string $filepath, string $ext): array
{
    $supportedExts = ['jpg', 'jpeg', 'png', 'gif', 'webp'];
    if (!in_array($ext, $supportedExts) || !function_exists('imagecreatetruecolor')) {
        return [];
    }

    $srcImage = match ($ext) {
        'jpg', 'jpeg' => @imagecreatefromjpeg($filepath),
        'png'         => @imagecreatefrompng($filepath),
        'gif'         => @imagecreatefromgif($filepath),
        'webp'        => @imagecreatefromwebp($filepath),
        default       => false,
    };

    if (!$srcImage) return [];

    $srcW = imagesx($srcImage);
    $srcH = imagesy($srcImage);
    $thumbs = [];

    foreach (THUMBNAIL_SIZES as $sizeName => $sizeConf) {
        $maxW = $sizeConf['width'];
        $maxH = $sizeConf['height'];
        $crop = $sizeConf['crop'];

        // 跳过比缩略图还小的原图
        if ($srcW <= $maxW && $srcH <= $maxH) {
            continue;
        }

        if ($crop) {
            // 裁剪模式：从中心裁剪
            $ratio = max($maxW / $srcW, $maxH / $srcH);
            $resW = (int)ceil($srcW * $ratio);
            $resH = (int)ceil($srcH * $ratio);
            $offsetX = (int)(($resW - $maxW) / 2 / $ratio);
            $offsetY = (int)(($resH - $maxH) / 2 / $ratio);
            $cropW = (int)($maxW / $ratio);
            $cropH = (int)($maxH / $ratio);

            $dstImage = imagecreatetruecolor($maxW, $maxH);
            _preserveTransparency($dstImage, $ext);
            imagecopyresampled($dstImage, $srcImage, 0, 0, $offsetX, $offsetY, $maxW, $maxH, $cropW, $cropH);
        } else {
            // 等比缩放
            $ratio = min($maxW / $srcW, $maxH / $srcH);
            $newW = (int)round($srcW * $ratio);
            $newH = (int)round($srcH * $ratio);

            $dstImage = imagecreatetruecolor($newW, $newH);
            _preserveTransparency($dstImage, $ext);
            imagecopyresampled($dstImage, $srcImage, 0, 0, 0, 0, $newW, $newH, $srcW, $srcH);
        }

        // 生成缩略图文件路径
        $thumbPath = _thumbnailPath($filepath, $sizeName);
        _saveImage($dstImage, $thumbPath, $ext);
        imagedestroy($dstImage);

        $thumbs[$sizeName] = $thumbPath;
    }

    imagedestroy($srcImage);
    return $thumbs;
}

/**
 * 保持 PNG/GIF/WebP 透明度
 */
function _preserveTransparency($image, string $ext): void
{
    if (in_array($ext, ['png', 'gif', 'webp'])) {
        imagealphablending($image, false);
        imagesavealpha($image, true);
        $transparent = imagecolorallocatealpha($image, 0, 0, 0, 127);
        imagefill($image, 0, 0, $transparent);
    }
}

/**
 * 保存图片到文件
 */
function _saveImage($image, string $path, string $ext): void
{
    match ($ext) {
        'jpg', 'jpeg' => imagejpeg($image, $path, 85),
        'png'         => imagepng($image, $path, 6),
        'gif'         => imagegif($image, $path),
        'webp'        => imagewebp($image, $path, 85),
    };
}

/**
 * 生成缩略图文件路径
 * 例: /uploads/images/202602/img_abc123.jpg → /uploads/images/202602/img_abc123_thumb.jpg
 */
function _thumbnailPath(string $filepath, string $sizeName): string
{
    $info = pathinfo($filepath);
    return $info['dirname'] . '/' . $info['filename'] . '_' . $sizeName . '.' . $info['extension'];
}

/**
 * 获取缩略图URL
 * 用法: thumbnail('/uploads/images/202602/img.jpg', 'thumb')
 * 若缩略图不存在则返回原图URL
 */
function thumbnail(?string $url, string $size = 'thumb'): string
{
    if (empty($url)) return '';
    if (!isset(THUMBNAIL_SIZES[$size])) return $url;

    $info = pathinfo($url);
    $thumbUrl = $info['dirname'] . '/' . $info['filename'] . '_' . $size . '.' . ($info['extension'] ?? 'jpg');

    // 检查文件是否存在
    $thumbPath = ROOT_PATH . $thumbUrl;
    if (file_exists($thumbPath)) {
        return $thumbUrl;
    }

    return $url;
}

// ============================================================
// 短码系统
// ============================================================

/**
 * 解析内容中的短码 [form-slug] [banner-slug]
 */
function parseShortcodes(string $content): string
{
    // 表单短码
    $content = preg_replace_callback('/\[form-([a-zA-Z0-9_-]+)\]/', function ($matches) {
        return renderFormTemplate($matches[1]);
    }, $content);

    // 轮播图短码
    $content = preg_replace_callback('/\[banner-([a-zA-Z0-9_-]+)\]/', function ($matches) {
        return renderBannerShortcode($matches[1]);
    }, $content);

    return $content;
}

/**
 * 渲染轮播图短码为 Swiper 轮播
 */
function renderBannerShortcode(string $slug): string
{
    $group = getBannerGroup($slug);
    if (!$group) {
        return '<!-- banner group not found: ' . e($slug) . ' -->';
    }

    $banners = getBanners($slug, 20);
    if (empty($banners)) {
        return '<!-- banner empty: ' . e($slug) . ' -->';
    }

    $heightPc = (int)($group['height_pc'] ?: 500);
    $heightMobile = (int)($group['height_mobile'] ?: 250);
    $autoplayDelay = (int)($group['autoplay_delay'] ?? 5000);
    $uid = 'banner-sc-' . $slug . '-' . mt_rand(1000, 9999);

    $html = '';

    // Swiper CSS（浏览器自动去重相同 href 的 link）
    $html .= '<link rel="stylesheet" href="/assets/swiper/swiper-bundle.min.css">';

    // 内联样式：该实例的高度
    $html .= '<style>.' . $uid . '{height:' . $heightMobile . 'px;position:relative}';
    $html .= '@media(min-width:768px){.' . $uid . '{height:' . $heightPc . 'px}}</style>';

    // Swiper 容器
    $html .= '<div class="swiper ' . $uid . '">';
    $html .= '<div class="swiper-wrapper">';

    foreach ($banners as $b) {
        $html .= '<div class="swiper-slide">';
        if ($b['link_url']) {
            $html .= '<a href="' . e($b['link_url']) . '" target="' . e($b['link_target']) . '" class="block w-full h-full">';
            $html .= '<img src="' . e($b['image']) . '" alt="' . e($b['title']) . '" class="w-full h-full object-cover">';
            $html .= '</a>';
        } else {
            $html .= '<img src="' . e($b['image']) . '" alt="' . e($b['title']) . '" class="w-full h-full object-cover">';
        }
        if ($b['title']) {
            $html .= '<div class="absolute inset-0 flex items-center justify-center bg-black/30 pointer-events-none">';
            $html .= '<div class="text-center text-white px-4 w-full max-w-4xl">';
            $html .= '<h2 class="text-2xl md:text-4xl font-bold mb-3">' . e($b['title']) . '</h2>';
            if ($b['subtitle']) {
                $html .= '<p class="text-base md:text-xl">' . e($b['subtitle']) . '</p>';
            }
            if (!empty($b['btn1_text']) || !empty($b['btn2_text'])) {
                $html .= '<div class="flex flex-wrap justify-center gap-4 mt-5 pointer-events-auto">';
                if (!empty($b['btn1_text'])) {
                    $html .= '<a href="' . e($b['btn1_url'] ?: '#') . '" class="bg-white text-gray-800 hover:bg-gray-100 px-6 py-2.5 rounded-full font-semibold transition">' . e($b['btn1_text']) . '</a>';
                }
                if (!empty($b['btn2_text'])) {
                    $html .= '<a href="' . e($b['btn2_url'] ?: '#') . '" class="border-2 border-white text-white hover:bg-white/20 px-6 py-2.5 rounded-full font-semibold transition">' . e($b['btn2_text']) . '</a>';
                }
                $html .= '</div>';
            }
            $html .= '</div></div>';
        }
        $html .= '</div>';
    }

    $html .= '</div>';
    $html .= '<div class="swiper-pagination"></div>';
    if (count($banners) > 1) {
        $html .= '<div class="swiper-button-prev"></div>';
        $html .= '<div class="swiper-button-next"></div>';
    }
    $html .= '</div>';

    // 自包含 JS：加载 Swiper 并初始化
    $autoplayCfg = $autoplayDelay > 0
        ? 'autoplay:{delay:' . $autoplayDelay . ',disableOnInteraction:false},'
        : '';

    $html .= '<script>(function(){';
    $html .= 'function init(){new Swiper(".' . $uid . '",{loop:true,' . $autoplayCfg . 'effect:"fade",fadeEffect:{crossFade:true},pagination:{el:".' . $uid . ' .swiper-pagination",clickable:true},navigation:{nextEl:".' . $uid . ' .swiper-button-next",prevEl:".' . $uid . ' .swiper-button-prev"}});}';
    $html .= 'if(window.Swiper){init();}';
    $html .= 'else if(!document.querySelector("script[src*=swiper-bundle]")){';
    $html .= 'var s=document.createElement("script");s.src="/assets/swiper/swiper-bundle.min.js";s.onload=init;document.body.appendChild(s);';
    $html .= '}else{var si=setInterval(function(){if(window.Swiper){clearInterval(si);init();}},100);}';
    $html .= '})();</script>';

    return $html;
}

/**
 * 检测 fields 内容是否为旧版 JSON 格式
 */
function isJsonFields(string $fieldsRaw): bool
{
    $trimmed = ltrim($fieldsRaw);
    if ($trimmed === '' || $trimmed[0] !== '[') {
        return false;
    }
    $decoded = json_decode($trimmed, true);
    return is_array($decoded) && !empty($decoded) && isset($decoded[0]['key']);
}

/**
 * 将旧版 JSON 字段数组转换为 CF7 风格模板文本
 */
function jsonFieldsToTemplate(array $fields): string
{
    $lines = [];
    $inGrid = false;

    foreach ($fields as $field) {
        $key = $field['key'] ?? '';
        $label = $field['label'] ?? $key;
        $type = $field['type'] ?? 'text';
        $required = !empty($field['required']);
        $placeholder = $field['placeholder'] ?? '';
        $reqMark = $required ? ' <span class="text-red-500">*</span>' : '';
        $reqStar = $required ? '*' : '';
        $phPart = $placeholder !== '' ? ' "' . $placeholder . '"' : '';

        // textarea 独占一行，先关闭 grid
        if ($type === 'textarea') {
            if ($inGrid) {
                $lines[] = '</div>';
                $lines[] = '';
                $inGrid = false;
            }
            $lines[] = '<div class="mt-4">';
            $lines[] = '    <label>' . $label . $reqMark . '</label>';
            $lines[] = '    [textarea' . $reqStar . ' ' . $key . $phPart . ']';
            $lines[] = '</div>';
            continue;
        }

        // 其他类型放入 grid
        if (!$inGrid) {
            $lines[] = '<div class="grid grid-cols-1 md:grid-cols-2 gap-4">';
            $inGrid = true;
        }

        if ($type === 'select') {
            $options = array_map('trim', explode(',', $field['options'] ?? ''));
            $optParts = '';
            foreach ($options as $opt) {
                if ($opt !== '') {
                    $optParts .= ' "' . $opt . '"';
                }
            }
            $lines[] = '<div>';
            $lines[] = '    <label>' . $label . $reqMark . '</label>';
            $lines[] = '    [select' . $reqStar . ' ' . $key . ' "' . $placeholder . '"' . $optParts . ']';
            $lines[] = '</div>';
            continue;
        }

        $lines[] = '<div>';
        $lines[] = '    <label>' . $label . $reqMark . '</label>';
        $lines[] = '    [' . $type . $reqStar . ' ' . $key . $phPart . ']';
        $lines[] = '</div>';
    }

    if ($inGrid) {
        $lines[] = '</div>';
    }

    $lines[] = '';
    $lines[] = '<div class="mt-4">';
    $lines[] = '    [submit "提交"]';
    $lines[] = '</div>';

    return implode("\n", $lines);
}

/**
 * 解析 CF7 风格模板中的所有字段标签
 * 返回 [{type, name, required, placeholder, options}, ...]
 * 不包含 submit 标签
 */
function parseFormTags(string $template): array
{
    $tags = [];
    // 匹配 [type(*) name "..." "..." ...]
    preg_match_all('/\[(text|email|tel|textarea|number|date|url|select|radio|checkbox)(\*?)\s+([a-zA-Z0-9_-]+)((?:\s+"[^"]*")*)\]/i', $template, $matches, PREG_SET_ORDER);

    foreach ($matches as $m) {
        $type = strtolower($m[1]);
        $required = $m[2] === '*';
        $name = $m[3];
        $quotedStr = trim($m[4] ?? '');

        // 提取所有引号字符串
        $quoted = [];
        if ($quotedStr !== '') {
            preg_match_all('/"([^"]*)"/', $quotedStr, $qm);
            $quoted = $qm[1];
        }

        $tag = [
            'type'        => $type,
            'name'        => $name,
            'required'    => $required,
            'placeholder' => '',
            'options'     => [],
        ];

        if (in_array($type, ['select', 'radio', 'checkbox'])) {
            $tag['options'] = $quoted;
        } else {
            $tag['placeholder'] = $quoted[0] ?? '';
        }

        $tags[] = $tag;
    }

    return $tags;
}

/**
 * 将单个 CF7 标签渲染为 HTML 表单元素
 */
function renderFormTagHtml(array $tag): string
{
    $name = e($tag['name'] ?? '');
    $req = !empty($tag['required']) ? ' required' : '';
    $ph = e($tag['placeholder'] ?? '');
    $cls = 'w-full border border-gray-300 rounded-lg px-4 py-2.5 focus:outline-none focus:ring-2 focus:ring-primary/30 focus:border-primary';

    switch ($tag['type']) {
        case 'textarea':
            return '<textarea name="' . $name . '" rows="4" class="' . $cls . '" placeholder="' . $ph . '"' . $req . '></textarea>';

        case 'select':
            $html = '<select name="' . $name . '" class="' . $cls . '"' . $req . '>';
            foreach ($tag['options'] as $i => $opt) {
                $eopt = e($opt);
                if ($i === 0) {
                    $html .= '<option value="">' . $eopt . '</option>';
                } else {
                    $html .= '<option value="' . $eopt . '">' . $eopt . '</option>';
                }
            }
            $html .= '</select>';
            return $html;

        case 'radio':
            $html = '<div class="flex flex-wrap gap-4">';
            foreach ($tag['options'] as $opt) {
                $eopt = e($opt);
                $html .= '<label class="inline-flex items-center gap-2 cursor-pointer">';
                $html .= '<input type="radio" name="' . $name . '" value="' . $eopt . '" class="accent-primary"' . $req . '>';
                $html .= '<span>' . $eopt . '</span></label>';
            }
            $html .= '</div>';
            return $html;

        case 'checkbox':
            $html = '<div class="flex flex-wrap gap-4">';
            foreach ($tag['options'] as $opt) {
                $eopt = e($opt);
                $html .= '<label class="inline-flex items-center gap-2 cursor-pointer">';
                $html .= '<input type="checkbox" name="' . $name . '[]" value="' . $eopt . '" class="accent-primary">';
                $html .= '<span>' . $eopt . '</span></label>';
            }
            $html .= '</div>';
            return $html;

        case 'submit':
            $text = e($tag['text'] ?? '提交');
            return '<button type="submit" class="bg-primary hover:bg-secondary text-white px-6 py-2.5 rounded-lg transition">' . $text . '</button>';

        default:
            $inputType = in_array($tag['type'], ['email', 'tel', 'number', 'url', 'date']) ? $tag['type'] : 'text';
            return '<input type="' . e($inputType) . '" name="' . $name . '" class="' . $cls . '" placeholder="' . $ph . '"' . $req . '>';
    }
}

/**
 * 渲染表单模板为HTML（支持 CF7 风格模板和旧版 JSON）
 */
function renderFormTemplate(string $slug): string
{
    $template = formTemplateModel()->findBySlug($slug);
    if (!$template) {
        return '<!-- 表单不存在: ' . e($slug) . ' -->';
    }

    $fieldsRaw = $template['fields'] ?? '';
    if (empty(trim($fieldsRaw))) {
        return '';
    }

    // 向后兼容：旧 JSON 格式自动转换
    if (isJsonFields($fieldsRaw)) {
        $jsonFields = json_decode($fieldsRaw, true);
        $fieldsRaw = jsonFieldsToTemplate($jsonFields);
    }

    $formId = 'shortcode-form-' . $slug;

    // 替换模板中的标签为 HTML
    $renderedBody = preg_replace_callback(
        '/\[(text|email|tel|textarea|number|date|url|select|radio|checkbox)(\*?)\s+([a-zA-Z0-9_-]+)((?:\s+"[^"]*")*)\]/i',
        function ($m) {
            $type = strtolower($m[1]);
            $required = $m[2] === '*';
            $name = $m[3];
            $quotedStr = trim($m[4] ?? '');
            $quoted = [];
            if ($quotedStr !== '') {
                preg_match_all('/"([^"]*)"/', $quotedStr, $qm);
                $quoted = $qm[1];
            }
            $tag = ['type' => $type, 'name' => $name, 'required' => $required, 'placeholder' => '', 'options' => []];
            if (in_array($type, ['select', 'radio', 'checkbox'])) {
                $tag['options'] = $quoted;
            } else {
                $tag['placeholder'] = $quoted[0] ?? '';
            }
            return renderFormTagHtml($tag);
        },
        $fieldsRaw
    );

    // 替换 submit 标签
    $renderedBody = preg_replace_callback(
        '/\[submit(?:\s+"([^"]*)")?\]/',
        function ($m) {
            $text = $m[1] ?? '提交';
            return renderFormTagHtml(['type' => 'submit', 'text' => $text]);
        },
        $renderedBody
    );

    // 组装完整表单
    $html = '<div class="shortcode-form" id="' . e($formId) . '-wrap">';
    $html .= '<form id="' . e($formId) . '" onsubmit="return submitShortcodeForm(event, \'' . e($slug) . '\')">';
    $html .= '<input type="hidden" name="form_slug" value="' . e($slug) . '">';
    $html .= $renderedBody;
    $html .= '</form>';
    $html .= '<div id="' . e($formId) . '-msg" class="hidden mt-4 p-4 rounded-lg text-sm"></div>';
    $html .= '</div>';

    // 内联 JS（只注入一次）
    $html .= '<script>';
    $html .= 'if(!window._shortcodeFormInit){window._shortcodeFormInit=true;';
    $html .= 'window.submitShortcodeForm=function(e,slug){';
    $html .= 'e.preventDefault();var form=e.target;var btn=form.querySelector("button[type=submit]");';
    $html .= 'btn.disabled=true;btn.textContent="提交中...";';
    $html .= 'var fd=new FormData(form);';
    $html .= 'fetch("/form_submit.php",{method:"POST",body:fd}).then(r=>r.json()).then(function(data){';
    $html .= 'var msgEl=document.getElementById("shortcode-form-"+slug+"-msg");';
    $html .= 'msgEl.classList.remove("hidden","bg-green-50","text-green-600","bg-red-50","text-red-600");';
    $html .= 'if(data.code===0){msgEl.className+=" bg-green-50 text-green-600";msgEl.textContent=data.msg;form.reset();}';
    $html .= 'else{msgEl.className+=" bg-red-50 text-red-600";msgEl.textContent=data.msg;}';
    $html .= 'msgEl.classList.remove("hidden");btn.disabled=false;btn.textContent="提交";';
    $html .= '}).catch(function(){btn.disabled=false;btn.textContent="提交";});return false;};';
    $html .= '}</script>';

    return $html;
}
