<?php
/**
 * YikaiCMS - 栏目管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('*');

// 多语言翻译创建器：拦截 action=create_translation 的 POST，插入目标语言镜像行
$langSwitcher = [
    'table'         => 'channels',
    'model'         => channelModel(),
    'title_field'   => 'name',
    'summary_field' => 'description',
];
require_once ROOT_PATH . '/admin/includes/translate_action.php';

// 栏目类型
$channelTypes = [
    'list' => __('admin_article_list'),
    'page' => __('admin_page_static'),
    'product' => __('admin_product'),
    'case' => __('admin_case'),
    'download' => __('admin_download'),
    'job' => __('admin_job'),
    'album' => __('admin_album'),
    'link' => __('admin_link'),
];
// 追加已注册的自定义内容模型（type = model_key，内容进 contents 表）
foreach (contentModelModel()->allActive() as $_m) {
    $channelTypes[$_m['model_key']] = $_m['name'];
}

// 获取相册列表（用于相册类型栏目）
$albums = albumModel()->query("SELECT id, name FROM " . albumModel()->tableName() . " ORDER BY sort_order DESC, id ASC");

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'save') {
        $id = postInt('id');
        $data = [
            'parent_id' => postInt('parent_id'),
            'name' => post('name'),
            'slug' => post('slug'),
            'type' => post('type', 'list'),
            // 产品栏目（默认语言）slug 锁定为 product：产品分类页固定走
            // /product/{分类别名}.html，别名一致才有统一前缀。非默认语言行
            // 受全局 slug 唯一性约束，保持后缀形（product-en 等）可编辑。
            'album_id' => postInt('album_id'),
            'icon' => post('icon'),
            'image' => post('image'),
            'description' => post('description'),
            'content' => $_POST['content'] ?? '',
            'link_url' => post('link_url'),
            'link_target' => post('link_target', '_self'),
            'redirect_type' => post('redirect_type', 'auto'),
            'redirect_url' => post('redirect_url'),
            'seo_title' => post('seo_title'),
            'seo_keywords' => post('seo_keywords'),
            'seo_description' => post('seo_description'),
            'is_nav' => postInt('is_nav'),
            'is_home' => postInt('is_home'),
            'status' => postInt('status', 1),
            'sort_order' => postInt('sort_order'),
            'updated_at' => time(),
        ];

        // 列表显示元素（list_options 列由 20260726 迁移新增；未跑迁移的库跳过，避免保存整体失败）
        try {
            db()->fetchOne("SELECT list_options FROM " . DB_PREFIX . "channels LIMIT 1");
            $__lsAll = ['cover', 'summary', 'author', 'date', 'views', 'channel'];
            $__lsPicked = array_values(array_intersect($__lsAll, array_map('strval', (array) ($_POST['list_show'] ?? []))));
            // 全选 = 存空（默认全显示语义，升级前后行为一致）
            $data['list_options'] = count($__lsPicked) === count($__lsAll) ? '' : json_encode($__lsPicked, JSON_UNESCAPED_UNICODE);
        } catch (\Throwable $e) {
            // 列不存在：不写该字段
        }

        if (empty($data['name'])) {
            error(__('admin_category_name_required'));
        }

        // 判据用行语言而非页面视图变量（$_viewLang 在文件后段才定义，保存分支
        // 拿不到——Psalm 在 release 分支抓出的未定义变量）：编辑行取库里 lang，
        // 新建行取当前视图 lang（?lang= 参数），与默认语言一致才锁定。
        if ($data['type'] === 'product') {
            $__siteDefault = (string) config('site_lang', 'zh-CN');
            if ($id > 0) {
                $__row = channelModel()->find($id);
                $__rowLang = (string) ($__row['lang'] ?? '');
            } else {
                $__rowLang = (string) (get('lang') ?: $__siteDefault);
            }
            if ($__rowLang === '' || $__rowLang === $__siteDefault) {
                $data['slug'] = 'product';
            }
        }
        if (empty($data['slug'])) {
            $data['slug'] = $data['name'];
        }

        // 新建行显式带 lang：列表按 view-lang 过滤，而列默认值历史上是 'ja'，
        // 不写则新建栏目落到日语桶、中文站列表里查不到（smoke 新增的可见性回读抓出）。
        if ($id <= 0) {
            $data['lang'] = (string) (get('lang') ?: config('site_lang', 'zh-CN'));
        }

        // 检查 slug 唯一性
        if (!channelModel()->isSlugUnique($data['slug'], $id)) {
            error(__('admin_url_alias_exists'));
        }

        // 获取旧slug（用于更新页脚导航中的URL）
        $oldSlug = '';
        if ($id > 0) {
            $oldCh = channelModel()->find($id);
            $oldSlug = $oldCh ? $oldCh['slug'] : '';
        }

        if ($id > 0) {
            channelModel()->updateById($id, $data);
            adminLog('channel', 'update', __('admin_edit') . '：' . $data['name']);
        } else {
            $data['created_at'] = time();
            $id = channelModel()->create($data);
            adminLog('channel', 'create', __('admin_add') . '：' . $data['name']);
        }

        // 更新页脚导航
        $isFooterNav = postInt('is_footer_nav');
        $newUrl = '/' . $data['slug'] . '.html';
        $oldUrl = $oldSlug ? '/' . $oldSlug . '.html' : '';
        $footerNav = json_decode(config('footer_nav') ?: '[]', true) ?: [];

        // 移除旧URL（slug可能已变更）
        if ($oldUrl) {
            foreach ($footerNav as &$group) {
                $group['links'] = array_values(array_filter($group['links'] ?? [], function($link) use ($oldUrl) {
                    return ($link['url'] ?? '') !== $oldUrl;
                }));
            }
            unset($group);
        }

        if ($isFooterNav) {
            // 检查新URL是否已存在
            $exists = false;
            foreach ($footerNav as $group) {
                foreach (($group['links'] ?? []) as $link) {
                    if (($link['url'] ?? '') === $newUrl) {
                        $exists = true;
                        break 2;
                    }
                }
            }
            if (!$exists) {
                if (empty($footerNav)) {
                    $footerNav[] = ['title' => '', 'links' => []];
                }
                $footerNav[0]['links'][] = ['name' => $data['name'], 'url' => $newUrl, 'target' => '_self'];
            }
        }

        // 清理空分组
        $footerNav = array_values(array_filter($footerNav, function($g) {
            return !empty($g['links']);
        }));

        settingModel()->set('footer_nav', json_encode($footerNav, JSON_UNESCAPED_UNICODE));

        success(['id' => $id]);
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');

        if (!in_array($field, ['status', 'is_nav', 'is_home'])) {
            error(__('admin_invalid_operation'));
        }

        channelModel()->updateById($id, [$field => $value]);
        success();
    }

    if ($action === 'toggle_home_show') {
        // 切换"首页"菜单 per-lang 显示开关
        // 源语言用 nav_home_show，其它语言用 nav_home_show_{lang}
        $tLang = post('lang');
        $value = post('value') === '1' ? '1' : '0';
        $defaultLang = (string) config('site_lang', 'zh-CN');
        $key = $tLang === $defaultLang ? 'nav_home_show' : 'nav_home_show_' . $tLang;
        settingModel()->set($key, $value);
        adminLog('setting', 'nav_home_show', "切换 {$key} = {$value}");
        success([], $value === '1' ? __('ch_home_shown') : __('ch_home_hidden'));
    }

    if ($action === 'delete') {
        $id = postInt('id');
        $channel = channelModel()->find($id);
        if (!$channel) {
            error(__('admin_category_not_found'));
        }
        if (!empty($channel['is_system'])) {
            error(__('admin_category_system'));
        }
        if (channelModel()->hasChildren($id)) {
            error(__('admin_category_has_children'));
        }
        // 删除关联内容
        db()->execute('DELETE FROM ' . DB_PREFIX . 'contents WHERE channel_id = ?', [$id]);
        // 删除栏目
        channelModel()->deleteById($id);
        adminLog('channel', 'delete', __('admin_delete') . '：' . $channel['name']);
        success();
    }

    if ($action === 'sort') {
        $ids = $_POST['ids'] ?? [];
        $parentId = postInt('parent_id');
        channelModel()->updateSort($ids);
        success();
    }

    if ($action === 'sort_footer_nav') {
        $urls = $_POST['urls'] ?? [];
        $footerNav = json_decode(config('footer_nav') ?: '[]', true) ?: [];

        // 扁平化所有链接，建立 url => link 映射
        $allLinks = [];
        foreach ($footerNav as $group) {
            foreach (($group['links'] ?? []) as $link) {
                $allLinks[$link['url'] ?? ''] = $link;
            }
        }

        // 按新顺序重建
        $newLinks = [];
        foreach ($urls as $url) {
            if (isset($allLinks[$url])) {
                $newLinks[] = $allLinks[$url];
            }
        }

        $newFooterNav = empty($newLinks) ? [] : [['title' => '', 'links' => $newLinks]];
        settingModel()->set('footer_nav', json_encode($newFooterNav, JSON_UNESCAPED_UNICODE));
        success();
    }

    // 切换产品分类的导航显示
    if ($action === 'toggle_cat_nav') {
        $catId = postInt('cat_id');
        $value = postInt('value');
        db()->execute(
            'UPDATE ' . DB_PREFIX . 'product_categories SET is_nav = ? WHERE id = ?',
            [$value, $catId]
        );
        success();
    }

    exit;
}

// 视图语言：URL 参数 ?lang= 决定列哪个语言的频道
// 默认列源语言（site_lang）；切换到 en/ja 时显示对应语言的镜像行
$_lang        = adminLangView();
$_defaultLang = $_lang['default'];
$_viewLang    = $_lang['view'];
$_enabledList = $_lang['enabled'];
$_adminLang   = $_viewLang;
// 标签来自 availableLanguages()（扫 lang/*.php），新增语言只要丢文件即自动可用
$_langLabels  = availableLanguages();

/**
 * 渲染"显示/隐藏"切换按钮（睁眼/闭眼图标）。
 *
 *   $onclickJs: 完整 onclick 表达式（如 "toggleField(123, 'status', 0)"）
 *   $isShown:   当前是否显示状态
 *   $viewLabel: 用于 tooltip 中的"语言"上下文（可空）
 */
function renderEyeToggle(string $onclickJs, bool $isShown, string $viewLabel = ''): string
{
    $cls = $isShown ? 'text-blue-600 hover:bg-blue-50' : 'text-gray-300 hover:bg-gray-100';
    $title = $isShown
        ? str_replace(':lang', $viewLabel ? '（' . $viewLabel . '）' : '', __('ch_now_shown'))
        : str_replace(':lang', $viewLabel ? '（' . $viewLabel . '）' : '', __('ch_now_hidden'));
    $icon = $isShown ? 'ti-eye' : 'ti-eye-off';
    return '<button onclick="' . htmlspecialchars($onclickJs, ENT_QUOTES) . '" '
        . 'class="cursor-pointer p-1 rounded transition ' . $cls . '" '
        . 'title="' . htmlspecialchars($title, ENT_QUOTES) . '">'
        . '<i class="ti ' . $icon . ' text-base"></i>'
        . '</button>';
}

// view-lang 视角的"首页"文本（per-lang 覆盖优先；否则回退到 lang/{lang}.php 的 nav_home）
$_viewHomeText = __('nav_home');
$_viewHomeShow = '1';
$_viewHomeKey = $_viewLang === $_defaultLang ? 'nav_home_text' : 'nav_home_text_' . $_viewLang;
$_viewHomeShowKey = $_viewLang === $_defaultLang ? 'nav_home_show' : 'nav_home_show_' . $_viewLang;
$_custom = (string) config($_viewHomeKey, '');
if ($_custom !== '') {
    $_viewHomeText = $_custom;
} elseif (is_file(ROOT_PATH . '/lang/' . $_viewLang . '.php')) {
    $_data = require ROOT_PATH . '/lang/' . $_viewLang . '.php';
    if (is_array($_data) && !empty($_data['nav_home'])) $_viewHomeText = (string) $_data['nav_home'];
}
$_v = config($_viewHomeShowKey, null);
$_viewHomeShow = $_v === null ? '1' : (string) $_v;

// 递归过滤函数：只保留 lang === default 的节点，并对 children 递归
$_filterByLang = function (array $nodes) use (&$_filterByLang, $_adminLang): array {
    $out = [];
    foreach ($nodes as $n) {
        if (($n['lang'] ?? $_adminLang) !== $_adminLang) continue;
        if (!empty($n['children']) && is_array($n['children'])) {
            $n['children'] = $_filterByLang($n['children']);
        }
        $out[] = $n;
    }
    return $out;
};

// 获取栏目列表（平铺，用于下拉选项；过滤掉非默认语言）
$channels = array_values(array_filter(
    channelModel()->getFlatList(),
    fn($c) => ($c['lang'] ?? $_adminLang) === $_adminLang
));

// 后台用：不过滤 status，所有栏目都显示；只保留默认语言的源行
$channelTree = $_filterByLang(channelModel()->getTreeAll());

/** Blox 是单页的统一可视化编辑入口，旧富文本数据由编辑器首次打开时导入。 */
$__pageEditUrl = static fn(int $channelId): string => '/admin/blox_editor.php?id=' . $channelId;

// 产品分类：始终用源语言（zh-CN）的树结构作为主干（id / parent_id 都从源行取），
// view-lang 不是源时，再把 name 用对应翻译行覆盖（没翻译则保留源 name + 徽标提示缺译）。
// 这样切到 en/ja 不会因为没有翻译就把整个分类树清空。
$_allProductCats = db()->fetchAll(
    'SELECT * FROM ' . DB_PREFIX . 'product_categories WHERE lang = ? ORDER BY sort_order ASC, id ASC',
    [$_defaultLang]
);

// view-lang 不是源时：按 translation_group_id 索引该 lang 的翻译行，覆盖 name
if ($_viewLang !== $_defaultLang) {
    $_viewCatRows = db()->fetchAll(
        'SELECT translation_group_id, name FROM ' . DB_PREFIX . 'product_categories WHERE lang = ?',
        [$_viewLang]
    );
    $_viewCatByGid = [];
    foreach ($_viewCatRows as $_r) {
        $_viewCatByGid[(int) $_r['translation_group_id']] = $_r['name'];
    }
    foreach ($_allProductCats as &$_c) {
        $_gid = (int) ($_c['translation_group_id'] ?: $_c['id']);
        if (isset($_viewCatByGid[$_gid])) {
            $_c['name'] = $_viewCatByGid[$_gid];
        }
    }
    unset($_c);
}

// 产品分类翻译状态徽标索引（en/ja 等）—— 用 trans_pills 的 loader
require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatusCats = loadTransStatus('product_categories');
// 递归扁平化：父在前、子紧跟、附 _level 缩进信息
$_buildProductCatTree = function (array $rows, int $parentId, int $level) use (&$_buildProductCatTree): array {
    $out = [];
    foreach ($rows as $r) {
        if ((int)$r['parent_id'] === $parentId) {
            $r['_level'] = $level;
            $out[] = $r;
            $out = array_merge($out, $_buildProductCatTree($rows, (int)$r['id'], $level + 1));
        }
    }
    return $out;
};
$productCats = $_buildProductCatTree($_allProductCats, 0, 0);
// 按产品栏目ID索引（找出所有 type=product 的顶级栏目）
$productChannelIds = [];
foreach ($channelTree as $ch) {
    if ($ch['type'] === 'product') {
        $productChannelIds[] = (int)$ch['id'];
    }
}
// 获取页脚导航中的URL列表（用于显示菜单位置）
$footerNavUrls = [];
$footerNavData = json_decode(config('footer_nav') ?: '[]', true) ?: [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $footerNavUrls[] = $link['url'] ?? '';
    }
}
$homeInFooterNav = in_array('/', $footerNavUrls);

// 当前是非默认语言视图时，把当前行的 slug 映射回**源行 slug**——这样 footer_nav
// 里存的源 URL (/privacy.html) 能跟翻译行 (privacy-ja) 对得上。
// 否则切到 ja/en 视图，"底部导航"分类全部错乱（footer_nav 用源 slug 写入）。
$_srcSlugOf = function (array $ch) use ($_viewLang, $_defaultLang): string {
    if ($_viewLang === $_defaultLang) return (string) ($ch['slug'] ?? '');
    $groupId = (int) ($ch['translation_group_id'] ?: $ch['id']);
    static $cache = [];
    if (isset($cache[$groupId])) return $cache[$groupId];
    $src = db()->fetchOne(
        "SELECT slug FROM " . DB_PREFIX . "channels WHERE id = ? AND lang = ?",
        [$groupId, $_defaultLang]
    );
    return $cache[$groupId] = (string) ($src['slug'] ?? $ch['slug'] ?? '');
};

// 按 footer_nav JSON 顺序构建页脚栏目列表
$footerNavItems = [];
foreach ($footerNavData as $group) {
    foreach (($group['links'] ?? []) as $link) {
        $url = $link['url'] ?? '';
        if ($url === '/') {
            $footerNavItems[] = ['type' => 'home', 'link' => $link];
        } else {
            $matched = false;
            foreach ($channelTree as $ch) {
                if ('/' . $_srcSlugOf($ch) . '.html' === $url) {
                    // 已停用的栏目不占页脚列表（footer_nav JSON 保留，恢复显示即回来）
                    if (!empty($ch['status'])) {
                        $footerNavItems[] = ['type' => 'channel', 'channel' => $ch, 'link' => $link];
                    }
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                $footerNavItems[] = ['type' => 'external', 'link' => $link];
            }
        }
    }
}

// 四分法：主导航 / 页脚导航(由footerNavItems控制) / 未定义 / 已停用
// 已停用（status=0）的顶级栏目单独收进「已停用」页签，不再占用前三个导航列表
$mainNavChannels = [];
$undefinedChannels = [];
$hiddenChannels = [];
foreach ($channelTree as $ch) {
    if (empty($ch['status'])) {
        // 父级停用：整个子树收进「已停用」
        $hiddenChannels[] = $ch;
        continue;
    }
    // 父级启用：摘出停用的子栏目，单独收进「已停用」（带上级名标注）
    if (!empty($ch['children'])) {
        $visibleKids = [];
        foreach ($ch['children'] as $kid) {
            if (empty($kid['status'])) {
                $kid['_parent_name'] = $ch['name'];
                $hiddenChannels[] = $kid;
            } else {
                $visibleKids[] = $kid;
            }
        }
        $ch['children'] = $visibleKids;
    }
    $chUrl = '/' . $_srcSlugOf($ch) . '.html';
    if (!empty($ch['is_nav'])) {
        $mainNavChannels[] = $ch;
    } elseif (!in_array($chUrl, $footerNavUrls)) {
        $undefinedChannels[] = $ch;
    }
}

$activeTab = $_GET['tab'] ?? 'main';
if ($activeTab === 'hidden' && empty($hiddenChannels)) {
    $activeTab = 'main';
}


$editId = getInt('edit');
$editChannel = $editId > 0 ? channelModel()->find($editId) : null;

// 多语言：把当前编辑行 + URL 信息塞进早先设的 $langSwitcher，给 widget 用
$langSwitcher['item']       = $editChannel;
$langSwitcher['edit_url']   = '/admin/channel.php';
$langSwitcher['edit_param'] = 'edit';

// 列表页：每行翻译状态徽标
require_once ROOT_PATH . '/admin/includes/trans_pills.php';
$transStatus = loadTransStatus('channels');

$pageTitle = __('admin_channel');
$currentMenu = 'channel';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php if (count($_enabledList) > 1): ?>
<div class="bg-white rounded-lg shadow mb-4 px-5 py-3 flex items-center gap-3 flex-wrap text-sm">
    <span class="text-gray-500"><?php echo e(__('admin_view_lang')); ?></span>
    <?php
    $_langLabels = ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語'];
    foreach ($_enabledList as $_lc):
        $_label = $_langLabels[$_lc] ?? $_lc;
        $_isCurrent = ($_lc === $_viewLang);
        $_isDefault = ($_lc === $_defaultLang);
    ?>
    <a href="?lang=<?php echo e($_lc); ?>&tab=<?php echo e($activeTab); ?>"
       class="px-3 py-1 rounded-full transition <?php echo $_isCurrent ? 'bg-primary text-white' : 'bg-gray-100 text-gray-600 hover:bg-gray-200'; ?>">
        <?php echo e($_label); ?>
        <?php if ($_isDefault): ?><span class="ml-1 text-[10px] opacity-70">(<?php echo e(__('lang_source')); ?>)</span><?php endif; ?>
    </a>
    <?php endforeach; ?>
    <?php if ($_viewLang !== $_defaultLang): ?>
    <span class="ml-auto text-xs text-amber-600"><?php echo str_replace(':lang', e($_langLabels[$_defaultLang] ?? $_defaultLang), e(__('ch_source_lang_tip'))); ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>


<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <!-- 栏目列表 -->
    <div class="lg:col-span-2">
        <div class="bg-white rounded-lg shadow" x-data="{ tab: '<?php echo e($activeTab); ?>' }">
            <!-- Tab 导航 -->
            <div class="px-6 py-3 border-b flex items-center gap-1 flex-wrap">
                <button @click="tab='main'" :class="tab==='main' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?= __('admin_main_nav') ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($mainNavChannels) + 1; ?>)</span>
                </button>
                <button @click="tab='footer'" :class="tab==='footer' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?= __('admin_footer_nav') ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($footerNavItems); ?>)</span>
                </button>
                <button @click="tab='none'" :class="tab==='none' ? 'text-primary border-primary' : 'text-gray-500 border-transparent hover:text-gray-700'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer">
                    <?php echo __('admin_channel_unassigned'); ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($undefinedChannels); ?>)</span>
                </button>
                <?php if (!empty($hiddenChannels)): ?>
                <button @click="tab='hidden'" :class="tab==='hidden' ? 'text-primary border-primary' : 'text-gray-400 border-transparent hover:text-gray-600'"
                        class="px-3 py-2 text-sm font-medium border-b-2 transition cursor-pointer inline-flex items-center gap-1">
                    <i class="ti ti-eye-off text-base"></i><?php echo __('admin_channel_hidden_tab'); ?><span class="ml-1 text-xs text-gray-400">(<?php echo count($hiddenChannels); ?>)</span>
                </button>
                <?php endif; ?>
                <div class="flex-1"></div>
                <a href="/admin/channel_batch.php" class="border border-gray-300 text-gray-600 hover:border-primary hover:text-primary px-4 py-2 rounded text-sm transition inline-flex items-center gap-1 mr-2" title="<?php echo e(__('chbatch_entry_hint')); ?>">
                    <i class="ti ti-align-left text-base"></i>
                    <?php echo e(__('chbatch_title')); ?>
                </a>
                <a href="?edit=0&tab=<?php echo e($activeTab); ?>" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded text-sm transition inline-flex items-center gap-1">
                    <i class="ti ti-plus text-base"></i>
                    <?php echo __('admin_channel_add'); ?>
                </a>
            </div>

            <!-- Tab 1: 主导航栏目 -->
            <div x-show="tab==='main'" x-cloak>
                <!-- Home (固定) -->
                <div class="px-4 pt-4">
                    <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 rounded-lg border border-blue-200">
                        <span class="text-blue-300">
                            <i class="ti ti-home text-lg"></i>
                        </span>
                        <span class="font-medium text-gray-800 flex-1"><?php echo e($_viewHomeText); ?></span>
                        <span class="text-xs text-gray-400"><?php echo __('admin_label_fixed'); ?></span>
                        <?php echo renderEyeToggle("toggleHomeShow('{$_viewLang}', " . ($_viewHomeShow === '1' ? 0 : 1) . ")", $_viewHomeShow === '1', ($_langLabels[$_viewLang] ?? $_viewLang) . '「首页」'); ?>
                        <a href="/admin/setting_home.php" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                        <?php if (bloxPageEditorEnabled()): ?>
                        <button type="button" data-home-editor-trigger class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 text-sm" title="<?php echo e(__('page_mode_blox')); ?>"><i class="ti ti-stack-2 text-base"></i><span>Blox</span></button>
                        <?php endif; ?>
                    </div>
                </div>

                <?php if (!empty($mainNavChannels)): ?>
                <div class="p-4 pt-2">
                    <div id="sortable-root" class="space-y-2" data-parent="0">
                        <?php foreach ($mainNavChannels as $ch): ?>
                        <div class="channel-item" data-id="<?php echo $ch['id']; ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm group">
                                <span class="drag-handle-root cursor-grab text-gray-300 hover:text-gray-500">
                                    <i class="ti ti-menu-2 text-lg"></i>
                                </span>
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=main" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <?php echo renderTransPills((int)$ch['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <?php if (in_array('/' . $_srcSlugOf($ch) . '.html', $footerNavUrls)): ?>
                                <span class="text-xs px-2 py-0.5 rounded bg-indigo-100 text-indigo-600"><?php echo __('admin_footer_nav_badge'); ?></span>
                                <?php endif; ?>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=main" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="<?php echo $__pageEditUrl((int) $ch['id']); ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php echo renderEyeToggle("toggleField({$ch['id']}, 'status', " . ($ch['status'] ? 0 : 1) . ")", (bool)$ch['status'], $_langLabels[$_viewLang] ?? $_viewLang); ?>
                            </div>
                            <?php if (!empty($ch['children'])): ?>
                            <div class="sortable-children ml-8 mt-2 space-y-2" data-parent="<?php echo $ch['id']; ?>">
                                <?php foreach ($ch['children'] as $child): ?>
                                <div class="channel-item" data-id="<?php echo $child['id']; ?>">
                                    <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm group">
                                        <span class="drag-handle cursor-grab text-gray-300 hover:text-gray-500">
                                            <i class="ti ti-menu-2 text-base"></i>
                                        </span>
                                        <span class="text-gray-300 text-xs">└</span>
                                        <span class="text-gray-700 flex-1">
                                            <a href="?edit=<?php echo $child['id']; ?>&tab=main" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                        </span>
                                        <?php echo renderTransPills((int)$child['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                        <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                        <a href="?edit=<?php echo $child['id']; ?>&tab=main" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                        <?php if (($child['type'] ?? '') === 'page'): ?>
                                        <?php if (($child['slug'] ?? '') === 'contact'): ?>
                                        <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                        <?php else: ?>
                                        <a href="<?php echo $__pageEditUrl((int) $child['id']); ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                        <?php endif; ?>
                                        <?php endif; ?>
                                        <?php echo renderEyeToggle("toggleField({$child['id']}, 'status', " . ($child['status'] ? 0 : 1) . ")", (bool)$child['status'], $_langLabels[$_viewLang] ?? $_viewLang); ?>
                                    </div>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>

                            <?php if ($ch['type'] === 'product' && !empty($productCats)): ?>
                            <div class="ml-8 mt-2 space-y-2">
                                <div class="flex items-center gap-2 px-4 py-1.5">
                                    <span class="text-xs text-gray-400"><?= __('admin_product_category_auto') ?></span>
                                    <a href="/admin/product_category.php" class="text-xs text-primary hover:underline"><?= __('admin_category_manage') ?></a>
                                </div>
                                <?php foreach ($productCats as $cat):
                                    $level = (int)($cat['_level'] ?? 0);
                                ?>
                                <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm"
                                     style="margin-left: <?php echo $level * 24; ?>px;">
                                    <span class="text-amber-300 text-xs">
                                        <?php if ($level === 0): ?>
                                        <i class="ti ti-tag text-base"></i>
                                        <?php else: ?>
                                        <i class="ti ti-tag text-sm text-gray-300"></i>
                                        <?php endif; ?>
                                    </span>
                                    <span class="text-gray-300 text-xs">└</span>
                                    <span class="text-gray-700 flex-1"><?php echo e($cat['name']); ?></span>
                                    <?php echo renderTransPills((int)$cat['id'], $transStatusCats, '/admin/product_category.php', 'edit'); ?>
                                    <?php if ($level === 0): ?>
                                    <span class="text-xs text-amber-500"><?= __('admin_product_category') ?></span>
                                    <?php else: ?>
                                    <span class="text-xs text-gray-400">L<?php echo $level + 1; ?></span>
                                    <?php endif; ?>
                                    <button onclick="toggleCatNav(<?php echo $cat['id']; ?>, <?php echo !empty($cat['is_nav']) ? 0 : 1; ?>)"
                                            class="text-xs px-2 py-0.5 rounded <?php echo !empty($cat['is_nav']) ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-400'; ?>">
                                        <?= __('admin_nav_on') ?> <?php echo !empty($cat['is_nav']) ? 'ON' : 'OFF'; ?>
                                    </button>
                                    <a href="/admin/product_category.php?edit=<?php echo $cat['id']; ?>" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_main_nav_empty') ?></div>
                <?php endif; ?>
                <div class="px-4 pb-2">
                    <p class="text-xs text-gray-400"><?= __('admin_nav_sort_tip') ?></p>
                </div>
            </div>

            <!-- Tab 2: 页脚导航栏目 -->
            <div x-show="tab==='footer'" x-cloak>
                <?php if (!empty($footerNavItems)): ?>
                <div class="p-4">
                    <div id="sortable-footer" class="space-y-2">
                        <?php foreach ($footerNavItems as $fi): ?>
                        <?php if ($fi['type'] === 'home'): ?>
                        <div class="footer-nav-item" data-url="/">
                            <div class="flex items-center gap-3 px-4 py-3 bg-blue-50 rounded-lg border border-blue-200">
                                <span class="drag-handle-footer cursor-grab text-blue-300 hover:text-blue-500">
                                    <i class="ti ti-menu-2 text-lg"></i>
                                </span>
                                <span class="text-blue-300">
                                    <i class="ti ti-home text-lg"></i>
                                </span>
                                <span class="font-medium text-gray-800 flex-1"><?php echo e($fi['link']['name'] ?? __('admin_home')); ?></span>
                                <span class="text-xs text-gray-400">/</span>
                                <a href="/admin/setting_home.php" class="text-primary hover:underline text-sm"><?php echo __('admin_edit'); ?></a>
                                <?php if (bloxPageEditorEnabled()): ?>
                        <button type="button" data-home-editor-trigger class="inline-flex items-center gap-1 text-blue-600 hover:text-blue-800 text-sm" title="<?php echo e(__('page_mode_blox')); ?>"><i class="ti ti-stack-2 text-base"></i><span>Blox</span></button>
                        <?php endif; ?>
                            </div>
                        </div>
                        <?php elseif ($fi['type'] === 'channel'): ?>
                        <?php $ch = $fi['channel']; ?>
                        <div class="footer-nav-item" data-url="<?php echo e($fi['link']['url']); ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="drag-handle-footer cursor-grab text-gray-300 hover:text-gray-500">
                                    <i class="ti ti-menu-2 text-lg"></i>
                                </span>
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=footer" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <?php echo renderTransPills((int)$ch['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <?php if (!empty($ch['is_nav'])): ?>
                                <span class="text-xs px-2 py-0.5 rounded bg-green-100 text-green-600"><?= __('admin_main_nav') ?></span>
                                <?php endif; ?>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=footer" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="<?php echo $__pageEditUrl((int) $ch['id']); ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php echo renderEyeToggle("toggleField({$ch['id']}, 'status', " . ($ch['status'] ? 0 : 1) . ")", (bool)$ch['status'], $_langLabels[$_viewLang] ?? $_viewLang); ?>
                            </div>
                        </div>
                        <?php else: ?>
                        <div class="footer-nav-item" data-url="<?php echo e($fi['link']['url']); ?>">
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="drag-handle-footer cursor-grab text-gray-300 hover:text-gray-500">
                                    <i class="ti ti-menu-2 text-lg"></i>
                                </span>
                                <span class="font-medium text-gray-800 flex-1"><?php echo e($fi['link']['name'] ?? ''); ?></span>
                                <span class="text-xs text-gray-400"><?php echo e($fi['link']['url'] ?? ''); ?></span>
                                <span class="text-xs px-2 py-0.5 rounded bg-yellow-100 text-yellow-600"><?php echo __('admin_external_link'); ?></span>
                            </div>
                        </div>
                        <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_footer_nav_empty') ?></div>
                <?php endif; ?>
                <div class="px-4 pb-2">
                    <p class="text-xs text-gray-400"><?= __('admin_footer_nav_tip') ?> <a href="/admin/setting.php?tab=footer<?php echo $_lang['qsAmp'] ?? ''; ?>" class="text-primary hover:underline"><?php echo __('admin_system_setting_footer'); ?></a></p>
                </div>
            </div>

            <!-- Tab 3: 未定义位置栏目 -->
            <div x-show="tab==='none'" x-cloak>
                <?php if (!empty($undefinedChannels)): ?>
                <div class="p-4">
                    <div class="space-y-2">
                        <?php foreach ($undefinedChannels as $ch): ?>
                        <div>
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50 rounded-lg border hover:shadow-sm">
                                <span class="font-medium text-gray-800 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=none" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <?php echo renderTransPills((int)$ch['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=none" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                <?php if (($ch['type'] ?? '') === 'page'): ?>
                                <?php if (($ch['slug'] ?? '') === 'contact'): ?>
                                <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                <?php else: ?>
                                <a href="<?php echo $__pageEditUrl((int) $ch['id']); ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                <?php endif; ?>
                                <?php endif; ?>
                                <?php echo renderEyeToggle("toggleField({$ch['id']}, 'status', " . ($ch['status'] ? 0 : 1) . ")", (bool)$ch['status'], $_langLabels[$_viewLang] ?? $_viewLang); ?>
                            </div>
                            <?php if (!empty($ch['children'])): ?>
                            <div class="ml-8 mt-2 space-y-2">
                                <?php foreach ($ch['children'] as $child): ?>
                                <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border hover:shadow-sm">
                                    <span class="text-gray-300 text-xs">└</span>
                                    <span class="text-gray-700 flex-1">
                                        <a href="?edit=<?php echo $child['id']; ?>&tab=none" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                    </span>
                                    <?php echo renderTransPills((int)$child['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                    <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                    <a href="?edit=<?php echo $child['id']; ?>&tab=none" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                    <?php if (($child['type'] ?? '') === 'page'): ?>
                                    <?php if (($child['slug'] ?? '') === 'contact'): ?>
                                    <a href="/admin/setting_contact.php" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_setting_contact'); ?></a>
                                    <?php else: ?>
                                    <a href="<?php echo $__pageEditUrl((int) $child['id']); ?>" class="text-gray-500 hover:text-primary text-sm"><?php echo __('admin_content_edit'); ?></a>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                    <?php echo renderEyeToggle("toggleField({$child['id']}, 'status', " . ($child['status'] ? 0 : 1) . ")", (bool)$child['status'], $_langLabels[$_viewLang] ?? $_viewLang); ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php else: ?>
                <div class="px-6 py-8 text-center text-gray-400 text-sm"><?= __('admin_all_placed') ?></div>
                <?php endif; ?>
            </div>

            <!-- Tab 4: 已停用栏目（status=0 的顶级栏目，不占前三个导航列表） -->
            <?php if (!empty($hiddenChannels)): ?>
            <div x-show="tab==='hidden'" x-cloak>
                <div class="p-4">
                    <div class="space-y-2">
                        <?php foreach ($hiddenChannels as $ch): ?>
                        <div>
                            <div class="flex items-center gap-3 px-4 py-3 bg-gray-50/70 rounded-lg border border-dashed hover:shadow-sm">
                                <span class="text-gray-300"><i class="ti ti-eye-off text-base"></i></span>
                                <span class="font-medium text-gray-400 flex-1">
                                    <a href="?edit=<?php echo $ch['id']; ?>&tab=hidden" class="hover:text-primary"><?php echo e($ch['name']); ?></a>
                                </span>
                                <?php if (!empty($ch['_parent_name'])): ?>
                                <span class="text-xs px-2 py-0.5 rounded bg-gray-100 text-gray-400"><?php echo __('admin_parent_category'); ?>：<?php echo e($ch['_parent_name']); ?></span>
                                <?php endif; ?>
                                <?php echo renderTransPills((int)$ch['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                <span class="text-xs text-gray-400"><?php echo $channelTypes[$ch['type']] ?? $ch['type']; ?></span>
                                <a href="?edit=<?php echo $ch['id']; ?>&tab=hidden" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                <button onclick="toggleField(<?php echo $ch['id']; ?>, 'status', 1)"
                                        class="text-sm px-3 py-1 rounded border border-green-500 text-green-600 hover:bg-green-500 hover:text-white transition cursor-pointer inline-flex items-center gap-1 whitespace-nowrap">
                                    <i class="ti ti-eye text-base"></i><?php echo __('admin_channel_restore'); ?>
                                </button>
                                <?php if (empty($ch['is_system'])): ?>
                                <button onclick="deleteChannel(<?php echo $ch['id']; ?>, '<?php echo e($ch['name']); ?>')"
                                        class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><i class="ti ti-trash text-base"></i></button>
                                <?php endif; ?>
                            </div>
                            <?php if (!empty($ch['children'])): ?>
                            <div class="ml-8 mt-2 space-y-2">
                                <?php foreach ($ch['children'] as $child): ?>
                                <div class="flex items-center gap-3 px-4 py-2.5 bg-white rounded-lg border border-dashed hover:shadow-sm">
                                    <span class="text-gray-300 text-xs">└</span>
                                    <span class="text-gray-400 flex-1">
                                        <a href="?edit=<?php echo $child['id']; ?>&tab=hidden" class="hover:text-primary"><?php echo e($child['name']); ?></a>
                                    </span>
                                    <?php echo renderTransPills((int)$child['id'], $transStatus, '/admin/channel.php', 'edit'); ?>
                                    <span class="text-xs text-gray-400"><?php echo $channelTypes[$child['type']] ?? $child['type']; ?></span>
                                    <a href="?edit=<?php echo $child['id']; ?>&tab=hidden" class="text-primary hover:underline text-sm"><?php echo __('admin_channel_settings'); ?></a>
                                    <?php if (empty($child['status'])): ?>
                                    <button onclick="toggleField(<?php echo $child['id']; ?>, 'status', 1)"
                                            class="text-sm px-3 py-1 rounded border border-green-500 text-green-600 hover:bg-green-500 hover:text-white transition cursor-pointer inline-flex items-center gap-1 whitespace-nowrap">
                                        <i class="ti ti-eye text-base"></i><?php echo __('admin_channel_restore'); ?>
                                    </button>
                                    <?php else: ?>
                                    <?php echo renderEyeToggle("toggleField({$child['id']}, 'status', 0)", true, $_langLabels[$_viewLang] ?? $_viewLang); ?>
                                    <?php endif; ?>
                                    <?php if (empty($child['is_system'])): ?>
                                    <button onclick="deleteChannel(<?php echo $child['id']; ?>, '<?php echo e($child['name']); ?>')"
                                            class="text-red-500 hover:text-red-700" title="<?php echo __('admin_delete'); ?>"><i class="ti ti-trash text-base"></i></button>
                                    <?php endif; ?>
                                </div>
                                <?php endforeach; ?>
                            </div>
                            <?php endif; ?>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <div class="px-4 pb-3">
                    <p class="text-xs text-gray-400"><?php echo __('admin_channel_hidden_tip'); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- 编辑表单 -->
    <div class="lg:col-span-1">
        <?php require ROOT_PATH . '/admin/includes/lang_switcher_edit.php'; ?>
        <div class="bg-white rounded-lg shadow sticky top-20">
            <div class="px-6 py-4 border-b">
                <h2 class="font-bold text-gray-800"><?php echo $editChannel ? __('admin_channel_edit') : __('admin_channel_add'); ?></h2>
            </div>
            <form id="channelForm" class="p-6 space-y-4">
                <input type="hidden" name="action" value="save">
                <input type="hidden" name="id" value="<?php echo $editChannel['id'] ?? 0; ?>">

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_parent_category') ?></label>
                    <select name="parent_id" class="w-full border rounded px-3 py-2">
                        <option value="0"><?php echo __('admin_top_level'); ?></option>
                        <?php foreach ($channels as $ch): ?>
                        <option value="<?php echo $ch['id']; ?>"
                                <?php echo ($editChannel['parent_id'] ?? 0) == $ch['id'] ? 'selected' : ''; ?>
                                <?php echo ($editChannel['id'] ?? 0) == $ch['id'] ? 'disabled' : ''; ?>>
                            <?php echo str_repeat('　', $ch['_level']); ?><?php echo e($ch['name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_category_name') ?> <span class="text-red-500">*</span></label>
                    <input type="text" name="name" value="<?php echo e($editChannel['name'] ?? ''); ?>" required
                           class="w-full border rounded px-3 py-2">
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_url_alias') ?></label>
                    <?php $__slugLocked = ($editChannel['type'] ?? '') === 'product' && $_viewLang === $_defaultLang; ?>
                    <input type="text" name="slug" id="channelSlug" value="<?php echo e($__slugLocked ? 'product' : ($editChannel['slug'] ?? '')); ?>"
                           class="w-full border rounded px-3 py-2 <?php echo $__slugLocked ? 'bg-gray-100 text-gray-500' : ''; ?>"
                           placeholder="<?php echo __('admin_slug_auto_placeholder'); ?>"
                           <?php echo $__slugLocked ? 'readonly title="' . e(__('admin_product_slug_locked')) . '"' : ''; ?>>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_type') ?></label>
                    <select name="type" id="channelType" onchange="ykSyncProductSlugLock()" class="w-full border rounded px-3 py-2">
                        <?php foreach ($channelTypes as $key => $label): ?>
                        <option value="<?php echo $key; ?>" <?php echo ($editChannel['type'] ?? 'list') === $key ? 'selected' : ''; ?>>
                            <?php echo $label; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div id="linkFields" class="hidden space-y-4">
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_link_url'); ?></label>
                        <input type="text" name="link_url" value="<?php echo e($editChannel['link_url'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="https://">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_link_target'); ?></label>
                        <select name="link_target" class="w-full border rounded px-3 py-2">
                            <option value="_self" <?php echo ($editChannel['link_target'] ?? '') === '_self' ? 'selected' : ''; ?>><?php echo __('admin_link_target_self'); ?></option>
                            <option value="_blank" <?php echo ($editChannel['link_target'] ?? '') === '_blank' ? 'selected' : ''; ?>><?php echo __('admin_link_target_blank'); ?></option>
                        </select>
                    </div>
                </div>

                <?php
                // 「自动跳转」的实际目标 = 第一个子栏目（与前台 page.php 一致）
                $autoChild = null;
                if ($editChannel && !empty($editChannel['id'])) {
                    $_kids = channelModel()->getByParent((int) $editChannel['id'], true);
                    $autoChild = $_kids[0] ?? null;
                }
                ?>
                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_page_redirect'); ?></label>
                    <select name="redirect_type" id="redirectType" class="w-full border rounded px-3 py-2">
                        <option value="auto" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'auto' ? 'selected' : ''; ?>><?= __('admin_redirect_auto') ?></option>
                        <option value="none" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'none' ? 'selected' : ''; ?>><?php echo __('admin_redirect_none_detail'); ?></option>
                        <option value="url" <?php echo ($editChannel['redirect_type'] ?? 'auto') === 'url' ? 'selected' : ''; ?>><?php echo __('admin_redirect_url_option'); ?></option>
                    </select>
                    <p class="text-xs text-gray-400 mt-1"><?= __('admin_redirect_type') ?></p>
                    <p id="autoRedirectHint" class="text-xs mt-1 hidden <?php echo $autoChild ? 'text-blue-600' : 'text-amber-600'; ?>">
                        <?php echo $autoChild
                            ? sprintf(__('admin_redirect_auto_target'), '<strong>' . e($autoChild['name']) . '</strong>')
                            : __('admin_redirect_auto_none'); ?>
                    </p>
                </div>

                <div id="redirectUrlField" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_redirect_url_label'); ?></label>
                    <input type="text" name="redirect_url" value="<?php echo e($editChannel['redirect_url'] ?? ''); ?>"
                           class="w-full border rounded px-3 py-2" placeholder="/about/company.html">
                    <p class="text-xs text-gray-400 mt-1"><?php echo __('admin_redirect_url_hint'); ?></p>
                </div>

                <div id="albumFields" class="hidden">
                    <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_related_album'); ?></label>
                    <div class="flex gap-2">
                        <select name="album_id" id="albumSelect" class="flex-1 border rounded px-3 py-2">
                            <option value="0"><?php echo __('admin_select_album'); ?></option>
                            <?php foreach ($albums as $alb): ?>
                            <option value="<?php echo $alb['id']; ?>" <?php echo ($editChannel['album_id'] ?? 0) == $alb['id'] ? 'selected' : ''; ?>>
                                <?php echo e($alb['name']); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <a id="albumPhotosLink"
                           href="/admin/album_photos.php?id=<?php echo (int)($editChannel['album_id'] ?? 0); ?>"
                           target="_blank"
                           class="inline-flex items-center gap-1 px-3 py-2 border border-primary text-primary hover:bg-primary hover:text-white rounded text-sm whitespace-nowrap transition <?php echo ((int)($editChannel['album_id'] ?? 0)) > 0 ? '' : 'hidden'; ?>"
                           title="<?php echo e(__('ch_manage_photos')); ?>">
                            <i class="ti ti-photo text-base"></i>
                            <?php echo e(__('ch_manage_images')); ?>
                        </a>
                    </div>
                    <p class="text-xs text-gray-400 mt-1"><?= __('admin_album') ?><?php echo e(__('ch_album_hint')); ?></p>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_description') ?></label>
                    <textarea name="description" rows="2" class="w-full border rounded px-3 py-2"><?php echo e($editChannel['description'] ?? ''); ?></textarea>
                </div>

                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_sort_order'); ?></label>
                        <input type="number" name="sort_order" value="<?php echo $editChannel['sort_order'] ?? 0; ?>"
                               class="w-full border rounded px-3 py-2">
                    </div>
                    <div>
                        <label class="block text-gray-700 text-sm mb-1"><?php echo __('admin_status'); ?></label>
                        <?php $chStatus = (int)($editChannel['status'] ?? 1); ?>
                        <div class="grid grid-cols-2 gap-2">
                            <label class="cursor-pointer">
                                <input type="radio" name="status" value="1" class="peer sr-only" <?php echo $chStatus === 1 ? 'checked' : ''; ?>>
                                <div class="flex items-center justify-center gap-1.5 py-2 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition peer-checked:bg-green-500 peer-checked:text-white peer-checked:border-green-500">
                                    <i class="ti ti-eye text-base"></i><?php echo __('admin_show'); ?>
                                </div>
                            </label>
                            <label class="cursor-pointer">
                                <input type="radio" name="status" value="0" class="peer sr-only" <?php echo $chStatus === 0 ? 'checked' : ''; ?>>
                                <div class="flex items-center justify-center gap-1.5 py-2 rounded-lg border text-sm text-gray-600 hover:bg-gray-50 transition peer-checked:bg-gray-600 peer-checked:text-white peer-checked:border-gray-600">
                                    <i class="ti ti-eye-off text-base"></i><?php echo e(__('pg_disable')); ?>
                                </div>
                            </label>
                        </div>
                        <p class="text-xs text-gray-400 mt-1"><?php echo str_replace(':tab', e(__('admin_channel_hidden_tab')), e(__('ch_disable_hint'))); ?></p>
                    </div>
                </div>

                <div>
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_menu_position') ?></label>
                    <div class="flex gap-4">
                        <label class="flex items-center">
                            <input type="checkbox" name="is_nav" value="1" <?php echo ($editChannel['is_nav'] ?? 1) ? 'checked' : ''; ?> class="mr-2">
                            <?= __('admin_main_menu') ?>
                        </label>
                        <label class="flex items-center" title="<?= e(__('admin_home_display_tip')) ?>">
                            <input type="checkbox" name="is_home" value="1" <?php echo ($editChannel['is_home'] ?? 1) ? 'checked' : ''; ?> class="mr-2">
                            <?= __('admin_home_display') ?>
                        </label>
                        <label class="flex items-center">
                            <?php
                            $editInFooterNav = false;
                            if ($editChannel) {
                                $editChannelUrl = '/' . ($editChannel['slug'] ?? '') . '.html';
                                $editInFooterNav = in_array($editChannelUrl, $footerNavUrls);
                            }
                            ?>
                            <input type="checkbox" name="is_footer_nav" value="1" <?php echo $editInFooterNav ? 'checked' : ''; ?> class="mr-2">
                            <?= __('admin_footer_nav') ?>
                        </label>
                    </div>
                </div>

                <!-- 列表显示元素（文章列表类栏目；随类型选择显隐） -->
                <?php
                $__lsOpts = channelListOptions($editChannel ?? []);
                $__lsDefs = [
                    'cover'   => __('admin_ls_cover'),
                    'summary' => __('admin_ls_summary'),
                    'author'  => __('admin_ls_author'),
                    'date'    => __('admin_ls_date'),
                    'views'   => __('admin_ls_views'),
                    'channel' => __('admin_ls_channel'),
                ];
                ?>
                <div id="listOptFields">
                    <label class="block text-gray-700 text-sm mb-1"><?= __('admin_list_show') ?>
                        <span class="text-xs text-gray-400 font-normal ml-1"><?= __('admin_list_show_tip') ?></span>
                    </label>
                    <div class="flex flex-wrap gap-x-4 gap-y-2">
                        <?php foreach ($__lsDefs as $__lk => $__ll): ?>
                        <label class="flex items-center text-sm text-gray-600">
                            <input type="checkbox" name="list_show[]" value="<?php echo $__lk; ?>" <?php echo listShowEl($__lsOpts, $__lk) ? 'checked' : ''; ?> class="mr-1.5">
                            <?php echo e($__ll); ?>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="border-t pt-4">
                    <p class="text-gray-500 text-sm mb-2"><?php echo __('admin_seo_settings'); ?></p>
                    <div class="space-y-3">
                        <input type="text" name="seo_title" value="<?php echo e($editChannel['seo_title'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="<?php echo __('admin_seo_title'); ?>">
                        <input type="text" name="seo_keywords" value="<?php echo e($editChannel['seo_keywords'] ?? ''); ?>"
                               class="w-full border rounded px-3 py-2" placeholder="<?php echo __('admin_seo_keywords'); ?>">
                        <textarea name="seo_description" rows="2" class="w-full border rounded px-3 py-2"
                                  placeholder="<?php echo __('admin_seo_description'); ?>"><?php echo e($editChannel['seo_description'] ?? ''); ?></textarea>
                    </div>
                </div>

                <?php if ($editChannel && ($editChannel['type'] ?? '') === 'page'): ?>
                <?php if (($editChannel['slug'] ?? '') === 'contact'): ?>
                <a href="/admin/setting_contact.php"
                   class="block w-full text-center bg-gray-700 hover:bg-gray-800 text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <i class="ti ti-settings text-base"></i>
                    <?php echo __('admin_setting_contact'); ?>
                </a>
                <?php else: ?>
                <a href="<?php echo $__pageEditUrl((int) $editChannel['id']); ?>"
                   class="block w-full text-center bg-gray-700 hover:bg-gray-800 text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                    <i class="ti ti-pencil text-base"></i>
                    <?php echo __('admin_content_edit'); ?>
                </a>
                <?php endif; ?>
                <?php endif; ?>

                <div class="flex gap-2">
                    <button type="submit" class="flex-1 bg-primary hover:bg-secondary text-white py-2 rounded transition inline-flex items-center justify-center gap-1">
                        <i class="ti ti-check text-base"></i>
                        <?php echo __('admin_save'); ?>
                    </button>
                    <?php if ($editChannel): ?>
                    <a href="?" class="px-4 py-2 border rounded hover:bg-gray-100 transition inline-flex items-center gap-1">
                        <i class="ti ti-x text-base"></i>
                        <?php echo __('admin_cancel'); ?></a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="/assets/sortable/Sortable.min.js"></script>
<script>
// 显示/隐藏类型相关字段
document.getElementById('channelType').addEventListener('change', function() {
    document.getElementById('linkFields').classList.toggle('hidden', this.value !== 'link');
    document.getElementById('albumFields').classList.toggle('hidden', this.value !== 'album');
    // 列表显示元素：仅文章列表类（list + 自定义模型，用文章卡片渲染的）显示
    var __listCardTypes = <?php echo json_encode(array_values(array_diff(array_keys($channelTypes), ['page', 'link', 'album', 'product', 'case', 'download', 'job']))); ?>;
    document.getElementById('listOptFields').classList.toggle('hidden', __listCardTypes.indexOf(this.value) === -1);
});
document.getElementById('channelType').dispatchEvent(new Event('change'));

// 相册选择变化时同步「管理图片」链接的目标 + 显隐
(function () {
    const sel  = document.getElementById('albumSelect');
    const link = document.getElementById('albumPhotosLink');
    if (!sel || !link) return;
    sel.addEventListener('change', function () {
        const id = parseInt(this.value || '0', 10);
        if (id > 0) {
            link.href = '/admin/album_photos.php?id=' + id;
            link.classList.remove('hidden');
        } else {
            link.classList.add('hidden');
        }
    });
})();

// 跳转类型联动
document.getElementById('redirectType').addEventListener('change', function() {
    document.getElementById('redirectUrlField').classList.toggle('hidden', this.value !== 'url');
    var hint = document.getElementById('autoRedirectHint');
    if (hint) hint.classList.toggle('hidden', this.value !== 'auto');
});
document.getElementById('redirectType').dispatchEvent(new Event('change'));

// 当前 tab
var currentTab = new URLSearchParams(location.search).get('tab') || 'main';

// 表单提交
document.getElementById('channelForm').addEventListener('submit', async function(e) {
    e.preventDefault();
    const formData = new FormData(this);

    try {
        const response = await fetch('', { method: 'POST', body: formData });
        const data = await safeJson(response);

        if (data.code === 0) {
            showMessage('<?php echo __('admin_saved'); ?>');
            setTimeout(function() { location.href = '?tab=' + currentTab; }, 1000);
        } else {
            showMessage(data.msg, 'error');
        }
    } catch (err) {
        showMessage(<?php echo json_encode(__('admin_request_failed')); ?>, 'error');
    }
});

// 删除栏目
async function deleteChannel(id, name) {
    if (!confirm('<?= __('admin_confirm_delete') ?>')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('<?php echo __('admin_deleted'); ?>');
        setTimeout(function() { location.reload(); }, 800);
    } else {
        showMessage(data.msg, 'error');
    }
}

// 切换产品分类导航显示
async function toggleCatNav(catId, value) {
    const formData = new FormData();
    formData.append('action', 'toggle_cat_nav');
    formData.append('cat_id', catId);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg, 'error');
    }
}

// 切换字段
async function toggleField(id, field, value) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);

    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);

    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg, 'error');
    }
}

// 切换当前 view-lang 下"首页"菜单的显示/隐藏（per-lang nav_home_show）
async function toggleHomeShow(lang, value) {
    const formData = new FormData();
    formData.append('action', 'toggle_home_show');
    formData.append('lang', lang);
    formData.append('value', String(value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        location.reload();
    } else {
        showMessage(data.msg || <?php echo json_encode(__('admin_action_failed'), JSON_UNESCAPED_UNICODE); ?>, 'error');
    }
}

// 保存排序
async function saveSort(container) {
    var parentId = container.dataset.parent;
    var items = container.querySelectorAll(':scope > .channel-item');
    var formData = new FormData();
    formData.append('action', 'sort');
    formData.append('parent_id', parentId);
    for (var i = 0; i < items.length; i++) {
        formData.append('ids[]', items[i].dataset.id);
    }

    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage(<?php echo json_encode(__('admin_sort_saved')); ?>);
        }
    } catch (err) {}
}

// 初始化拖放排序
// 顶级栏目排序（主导航）
var root = document.getElementById('sortable-root');
if (root) {
    new Sortable(root, {
        handle: '.drag-handle-root',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveSort(root); }
    });
}

// 子栏目排序
document.querySelectorAll('.sortable-children').forEach(function(el) {
    new Sortable(el, {
        handle: '.drag-handle',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveSort(el); }
    });
});

// 页脚导航排序
var footerSortable = document.getElementById('sortable-footer');
if (footerSortable) {
    new Sortable(footerSortable, {
        handle: '.drag-handle-footer',
        animation: 200,
        ghostClass: 'opacity-30',
        chosenClass: 'shadow-lg',
        onEnd: function() { saveFooterSort(footerSortable); }
    });
}

async function saveFooterSort(container) {
    var items = container.querySelectorAll(':scope > .footer-nav-item');
    var formData = new FormData();
    formData.append('action', 'sort_footer_nav');
    items.forEach(function(item) {
        formData.append('urls[]', item.dataset.url);
    });
    try {
        var response = await fetch('', { method: 'POST', body: formData });
        var data = await safeJson(response);
        if (data.code === 0) {
            showMessage(<?php echo json_encode(__('admin_sort_saved')); ?>);
        }
    } catch (err) {}
}
</script>

<?php if (bloxPageEditorEnabled()): ?>
<div id="homeEditorModal" class="fixed inset-0 hidden items-center justify-center bg-black/40 p-4" style="z-index: 1100" role="dialog" aria-modal="true" aria-labelledby="homeEditorModalTitle">
    <div class="w-full max-w-md rounded-xl bg-white shadow-2xl border border-gray-200" data-home-editor-dialog>
        <div class="flex items-start justify-between gap-4 px-5 py-4 border-b border-gray-100">
            <div>
                <h3 id="homeEditorModalTitle" class="font-semibold text-gray-800"><?php echo e(__('admin_setting_home')); ?></h3>
                <p class="mt-1 text-xs leading-5 text-gray-500"><?php echo e(__('page_mode_blox_tip')); ?></p>
            </div>
            <button type="button" data-home-editor-close class="w-8 h-8 rounded-lg inline-flex items-center justify-center text-gray-400 hover:text-gray-700 hover:bg-gray-100" title="<?php echo e(__('admin_cancel')); ?>" aria-label="<?php echo e(__('admin_cancel')); ?>">
                <i class="ti ti-x text-lg"></i>
            </button>
        </div>
        <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 p-5">
            <a href="/admin/setting_home.php" class="group rounded-lg border border-gray-200 p-4 hover:border-gray-300 hover:bg-gray-50 transition">
                <i class="ti ti-adjustments text-xl text-gray-500 group-hover:text-gray-700"></i>
                <span class="mt-2 block text-sm font-medium text-gray-800"><?php echo e(__('admin_setting_home')); ?></span>
                <span class="mt-1 block text-xs text-gray-500"><?php echo e(__('home_editor_hint')); ?></span>
            </a>
            <a href="/admin/blox_editor.php?home=1" class="group rounded-lg border border-blue-200 bg-blue-50/60 p-4 hover:border-blue-400 hover:bg-blue-50 transition">
                <i class="ti ti-stack-2 text-xl text-blue-600"></i>
                <span class="mt-2 block text-sm font-medium text-blue-800"><?php echo e(__('page_mode_blox')); ?></span>
                <span class="mt-1 block text-xs text-blue-700/70"><?php echo e(__('page_mode_blox_tip')); ?></span>
            </a>
        </div>
        <div class="flex justify-end px-5 pb-4">
            <button type="button" data-home-editor-close class="px-3 py-1.5 rounded text-sm text-gray-600 hover:bg-gray-100"><?php echo e(__('admin_cancel')); ?></button>
        </div>
    </div>
</div>

<script>
(function () {
    var modal = document.getElementById('homeEditorModal');
    if (!modal) return;
    var triggers = document.querySelectorAll('[data-home-editor-trigger]');
    var closes = modal.querySelectorAll('[data-home-editor-close]');
    function openModal() {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
        document.body.classList.add('overflow-hidden');
        var close = modal.querySelector('[data-home-editor-close]');
        if (close) close.focus();
    }
    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
        document.body.classList.remove('overflow-hidden');
    }
    triggers.forEach(function (trigger) { trigger.addEventListener('click', openModal); });
    closes.forEach(function (close) { close.addEventListener('click', closeModal); });
    modal.addEventListener('click', function (event) {
        if (event.target === modal) closeModal();
    });
    document.addEventListener('keydown', function (event) {
        if (event.key === 'Escape' && !modal.classList.contains('hidden')) closeModal();
    });
})();
</script>
<?php endif; ?>
<script>
// 产品栏目（默认语言）slug 锁定为 product——服务端为准，这里只是即时反馈
var YK_SLUG_LOCK_DEFAULT_VIEW = <?php echo json_encode($_viewLang === $_defaultLang); ?>;
function ykSyncProductSlugLock() {
    var typeSel = document.getElementById('channelType');
    var slugInp = document.getElementById('channelSlug');
    if (!typeSel || !slugInp || !YK_SLUG_LOCK_DEFAULT_VIEW) return;
    var lock = typeSel.value === 'product';
    slugInp.readOnly = lock;
    slugInp.classList.toggle('bg-gray-100', lock);
    slugInp.classList.toggle('text-gray-500', lock);
    if (lock) { slugInp.value = 'product'; slugInp.title = <?php echo json_encode(__('admin_product_slug_locked'), JSON_UNESCAPED_UNICODE); ?>; }
    else { slugInp.title = ''; }
}
ykSyncProductSlugLock();
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
