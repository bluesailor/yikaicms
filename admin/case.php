<?php
/**
 * Yikai CMS - 案例管理
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

checkLogin();
requirePermission('content');

$contentType = 'case';

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = post('action');

    if ($action === 'delete') {
        $id = postInt('id');
        contentModel()->deleteById($id);
        adminLog('case', 'delete', '删除案例ID：' . $id);
        success();
    }

    if ($action === 'batch_delete') {
        $ids = $_POST['ids'] ?? [];
        if (!empty($ids)) {
            contentModel()->deleteByIds($ids);
            adminLog('case', 'batch_delete', '批量删除：' . implode(',', $ids));
        }
        success();
    }

    if ($action === 'toggle') {
        $id = postInt('id');
        $field = post('field');
        $value = postInt('value');
        if (in_array($field, ['status', 'is_top', 'is_recommend', 'is_hot'])) {
            contentModel()->updateById($id, [$field => $value]);
        }
        success();
    }

    if ($action === 'quick_translate') {
        $srcId = postInt('src_id');
        $toLang = post('to_lang');
        $src = contentModel()->find($srcId);
        if (!$src) error('源内容不存在');

        $groupId = (int)($src['translation_group_id'] ?: $srcId);
        if (!$src['translation_group_id']) {
            contentModel()->updateById($srcId, ['translation_group_id' => $srcId]);
        }

        // 检查是否已有翻译
        $existing = contentModel()->queryOne(
            "SELECT id FROM " . contentModel()->tableName() . " WHERE translation_group_id = ? AND lang = ?",
            [$groupId, $toLang]
        );
        if ($existing) {
            success(['id' => (int)$existing['id'], 'exists' => true], '翻译已存在');
        }

        // AI 翻译标题和摘要
        $langName = availableLanguages()[$toLang] ?? $toLang;
        $title = $src['title'];
        $summary = $src['summary'] ?? '';

        require_once ROOT_PATH . '/includes/AiService.php';
        $encryptedKey = config('ai_api_key', '');
        $aiKey = $encryptedKey ? AiService::decryptKey($encryptedKey) : '';

        $translatedTitle = dictTranslateTo($title, $toLang) ?: $title;
        $translatedSummary = $summary;

        if ($aiKey) {
            $ai = new AiService(config('ai_provider', 'openai'), $aiKey, config('ai_model', 'gpt-4o-mini'));
            $prompt = "Translate the following to {$langName}. Return JSON: {\"title\":\"...\",\"summary\":\"...\"}. No explanation.\n\nTitle: {$title}\nSummary: {$summary}";
            $result = $ai->chat($prompt, 'You are a professional translator. Return only valid JSON.', 0.3);
            if ($result['success']) {
                $json = json_decode(preg_replace('/^```json\s*|```\s*$/m', '', trim($result['content'] ?? '')), true);
                if ($json) {
                    $translatedTitle = $json['title'] ?? $translatedTitle;
                    $translatedSummary = $json['summary'] ?? $translatedSummary;
                }
            }
        }

        // 查找对应语言的栏目
        $targetChannelId = 0;
        if ($src['channel_id'] > 0) {
            $srcChannel = channelModel()->find((int)$src['channel_id']);
            if ($srcChannel) {
                $chGroupId = (int)($srcChannel['translation_group_id'] ?: $srcChannel['id']);
                $targetChannel = channelModel()->queryOne(
                    "SELECT id FROM " . channelModel()->tableName() . " WHERE translation_group_id = ? AND lang = ?",
                    [$chGroupId, $toLang]
                );
                if ($targetChannel) $targetChannelId = (int)$targetChannel['id'];
            }
        }

        $newId = contentModel()->create([
            'channel_id' => $targetChannelId,
            'type' => $src['type'],
            'title' => $translatedTitle,
            'summary' => $translatedSummary,
            'content' => $src['content'],
            'cover' => $src['cover'] ?? '',
            'attachment' => $src['attachment'] ?? '',
            'views' => 0,
            'is_top' => 0,
            'is_recommend' => (int)($src['is_recommend'] ?? 0),
            'is_hot' => (int)($src['is_hot'] ?? 0),
            'sort_order' => (int)($src['sort_order'] ?? 0),
            'status' => 0,
            'lang' => $toLang,
            'translation_group_id' => $groupId,
            'publish_time' => time(),
            'created_at' => time(),
            'updated_at' => time(),
            'admin_id' => $_SESSION['admin_id'] ?? 0,
        ]);

        adminLog('case', 'translate', "AI翻译案例 #{$srcId} → {$toLang} #{$newId}");
        success(['id' => $newId], "翻译完成，已创建{$langName}版本（草稿状态）");
    }

    exit;
}

// 多语言
$defaultLang = config('site_lang', 'zh-CN');
$multiLangEnabled = isMultiLangEnabled('contents');
$filterLang = get('lang', $defaultLang);
$allLangs = availableLanguages();
if ($multiLangEnabled && !isset($allLangs[$filterLang])) $filterLang = $defaultLang;

// 获取案例类型的栏目
$chConditions = ['type' => 'case', 'status' => 1];
if ($multiLangEnabled) $chConditions['lang'] = $filterLang;
$channels = channelModel()->where($chConditions, 'sort_order ASC');

// 查询参数
$channelId = getInt('channel_id');
$status = get('status', '');
$keyword = get('keyword');
$page = max(1, getInt('page', 1));
$perPage = 20;

// 构建查询
$where = ['c.type = ?'];
$params = [$contentType];

if ($multiLangEnabled) {
    $where[] = 'c.lang = ?';
    $params[] = $filterLang;
}

if ($channelId > 0) {
    $where[] = 'c.channel_id = ?';
    $params[] = $channelId;
}

if ($status !== '') {
    $where[] = 'c.status = ?';
    $params[] = (int)$status;
}

if ($keyword) {
    $where[] = '(c.title LIKE ? OR c.summary LIKE ?)';
    $params[] = '%' . $keyword . '%';
    $params[] = '%' . $keyword . '%';
}

$whereClause = 'WHERE ' . implode(' AND ', $where);

$total = (int) contentModel()->queryColumn(
    "SELECT COUNT(*) FROM " . contentModel()->tableName() . " c $whereClause",
    $params
);

$offset = (int)(($page - 1) * $perPage);
$perPage = (int)$perPage;
$params[] = $perPage;
$params[] = $offset;
$items = contentModel()->query(
    "SELECT c.*, ch.name as channel_name FROM " . contentModel()->tableName() . " c
     LEFT JOIN " . channelModel()->tableName() . " ch ON c.channel_id = ch.id
     $whereClause ORDER BY c.is_top DESC, c.id DESC LIMIT ? OFFSET ?",
    $params
);

// 翻译状态：收集当前列表项的 translation_group_id，查找各语言翻译是否存在
$otherLangs = $allLangs;
unset($otherLangs[$filterLang]);
$translationStatus = []; // [group_id => [lang => item_id]]
if ($multiLangEnabled && !empty($otherLangs) && !empty($items)) {
    $groupIds = [];
    foreach ($items as $it) {
        $gid = (int)($it['translation_group_id'] ?: $it['id']);
        $groupIds[$gid] = true;
    }
    if (!empty($groupIds)) {
        $gidList = implode(',', array_keys($groupIds));
        $transRows = contentModel()->query(
            "SELECT id, lang, translation_group_id FROM " . contentModel()->tableName() . " WHERE translation_group_id IN ({$gidList}) AND lang != ?",
            [$filterLang]
        );
        foreach ($transRows as $tr) {
            $translationStatus[(int)$tr['translation_group_id']][$tr['lang']] = (int)$tr['id'];
        }
    }
}

$pageTitle = '案例管理';
$currentMenu = 'case';

require_once ROOT_PATH . '/admin/includes/header.php';
?>

<?php if ($multiLangEnabled && count($allLangs) > 1): ?>
<div class="mb-4 flex items-center gap-2 flex-wrap">
    <span class="text-sm text-gray-500">语言：</span>
    <?php foreach ($allLangs as $lc => $ll): ?>
    <a href="?lang=<?php echo e($lc); ?>" class="px-4 py-1.5 rounded-full text-sm border transition <?php echo $lc === $filterLang ? 'bg-primary text-white border-primary' : 'text-gray-600 border-gray-200 hover:border-primary hover:text-primary'; ?>"><?php echo e($ll); ?></a>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- 工具栏 -->
<div class="bg-white rounded-lg shadow mb-6">
    <div class="p-4 flex flex-wrap gap-4 items-center justify-between">
        <form class="flex flex-wrap gap-3 items-center">
            <?php if ($multiLangEnabled): ?><input type="hidden" name="lang" value="<?php echo e($filterLang); ?>"><?php endif; ?>
            <select name="channel_id" class="border rounded px-3 py-2">
                <option value="">全部栏目</option>
                <?php foreach ($channels as $ch): ?>
                <option value="<?php echo $ch['id']; ?>" <?php echo $channelId === (int)$ch['id'] ? 'selected' : ''; ?>>
                    <?php echo e($ch['name']); ?>
                </option>
                <?php endforeach; ?>
            </select>

            <select name="status" class="border rounded px-3 py-2">
                <option value="">全部状态</option>
                <option value="1" <?php echo $status === '1' ? 'selected' : ''; ?>>已发布</option>
                <option value="0" <?php echo $status === '0' ? 'selected' : ''; ?>>草稿</option>
            </select>

            <input type="text" name="keyword" value="<?php echo e($keyword); ?>"
                   class="border rounded px-3 py-2" placeholder="搜索标题...">

            <button type="submit" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z"></path></svg>
                筛选
            </button>
        </form>

        <div class="flex gap-2">
            <a href="/admin/channel.php?type=case" class="bg-white border border-gray-300 hover:bg-gray-50 text-gray-700 px-4 py-2 rounded inline-flex items-center gap-1" title="管理案例分类栏目">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16M4 10h16M4 14h16M4 18h16"/></svg>
                管理栏目
            </a>
            <a href="/admin/content_edit.php?type=case" class="bg-primary hover:bg-secondary text-white px-4 py-2 rounded inline-flex items-center gap-1">
                <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"></path></svg>
                添加案例
            </a>
        </div>
    </div>
</div>

<!-- 列表 -->
<div class="bg-white rounded-lg shadow">
    <form id="listForm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead class="bg-gray-50">
                    <tr>
                        <th class="px-4 py-3 text-left"><input type="checkbox" id="checkAll"></th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 uppercase">标题</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">栏目</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">浏览</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">推荐</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">状态</th>
                        <?php if ($multiLangEnabled && !empty($otherLangs)): ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">翻译</th>
                        <?php endif; ?>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">发布时间</th>
                        <th class="px-4 py-3 text-center text-xs font-medium text-gray-500 uppercase">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($items as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-4 py-3"><input type="checkbox" name="ids[]" value="<?php echo $item['id']; ?>"></td>
                        <td class="px-4 py-3">
                            <div class="flex items-center gap-3">
                                <?php if ($item['cover']): ?>
                                <img src="<?php echo e($item['cover']); ?>" class="w-16 h-12 object-cover rounded">
                                <?php endif; ?>
                                <div class="font-medium"><?php echo e(cutStr($item['title'], 40)); ?></div>
                            </div>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <span class="text-xs bg-blue-100 text-blue-600 px-2 py-1 rounded">
                                <?php echo e($item['channel_name'] ?: '-'); ?>
                            </span>
                        </td>
                        <td class="px-4 py-3 text-center text-sm text-gray-500"><?php echo number_format((int)$item['views']); ?></td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'is_recommend', <?php echo $item['is_recommend'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['is_recommend'] ? 'bg-purple-100 text-purple-600' : 'bg-gray-100 text-gray-400'; ?>">
                                <?php echo $item['is_recommend'] ? '推荐' : '普通'; ?>
                            </button>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <button onclick="toggle(<?php echo $item['id']; ?>, 'status', <?php echo $item['status'] ? 0 : 1; ?>, this)"
                                    class="text-xs px-2 py-1 rounded <?php echo $item['status'] ? 'bg-green-100 text-green-600' : 'bg-gray-100 text-gray-500'; ?>">
                                <?php echo $item['status'] ? '已发布' : '草稿'; ?>
                            </button>
                        </td>
                        <?php if ($multiLangEnabled && !empty($otherLangs)):
                            $gid = (int)($item['translation_group_id'] ?: $item['id']);
                        ?>
                        <td class="px-4 py-3 text-center">
                            <div class="flex items-center justify-center gap-1">
                                <?php foreach ($otherLangs as $olc => $oll):
                                    $hasTranslation = isset($translationStatus[$gid][$olc]);
                                    $transId = $translationStatus[$gid][$olc] ?? 0;
                                    $label = strtoupper(explode('-', $olc)[0]);
                                ?>
                                <?php if ($hasTranslation): ?>
                                <a href="/admin/content_edit.php?id=<?php echo $transId; ?>" title="<?php echo e($oll); ?> - 已翻译，点击编辑"
                                   class="px-1.5 py-0.5 rounded text-xs bg-green-100 text-green-600 hover:bg-green-200"><?php echo $label; ?></a>
                                <?php else: ?>
                                <button type="button" onclick="quickTranslate(<?php echo $item['id']; ?>, '<?php echo e($olc); ?>', this)" title="<?php echo e($oll); ?> - 点击AI翻译"
                                        class="px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-400 hover:bg-amber-100 hover:text-amber-600 cursor-pointer"><?php echo $label; ?></button>
                                <?php endif; ?>
                                <?php endforeach; ?>
                            </div>
                        </td>
                        <?php endif; ?>
                        <td class="px-4 py-3 text-center text-sm text-gray-500">
                            <?php echo $item['publish_time'] ? date('Y-m-d', (int)$item['publish_time']) : '-'; ?>
                        </td>
                        <td class="px-4 py-3 text-center">
                            <a href="/admin/content_edit.php?id=<?php echo $item['id']; ?>" class="text-blue-500 hover:text-blue-700 text-sm inline-flex items-center gap-1 mr-2" title="编辑"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M11 5H6a2 2 0 00-2 2v11a2 2 0 002 2h11a2 2 0 002-2v-5m-1.414-9.414a2 2 0 112.828 2.828L11.828 15H9v-2.828l8.586-8.586z"></path></svg> 编辑</a>
                            <button onclick="deleteItem(<?php echo $item['id']; ?>)" class="text-red-500 hover:text-red-700" title="删除"><svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 7l-.867 12.142A2 2 0 0116.138 21H7.862a2 2 0 01-1.995-1.858L5 7m5 4v6m4-6v6m1-10V4a1 1 0 00-1-1h-4a1 1 0 00-1 1v3M4 7h16"></path></svg></button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if (empty($items)): ?>
                    <tr><td colspan="<?php echo ($multiLangEnabled && !empty($otherLangs)) ? 9 : 8; ?>" class="px-4 py-8 text-center text-gray-500">暂无数据</td></tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <div class="px-6 py-4 border-t flex flex-wrap gap-4 items-center justify-between">
            <button type="button" onclick="batchDelete()" class="border px-3 py-1 rounded text-sm hover:bg-gray-100">批量删除</button>
            <?php if ($total > $perPage): ?>
            <div class="flex items-center gap-2">
                <span class="text-sm text-gray-500">共 <?php echo $total; ?> 条</span>
                <?php
                $totalPages = ceil($total / $perPage);
                $queryString = http_build_query(array_filter(['channel_id' => $channelId, 'status' => $status, 'keyword' => $keyword]));
                $baseUrl = '?' . ($queryString ? $queryString . '&' : '');
                ?>
                <?php if ($page > 1): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page - 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100">上一页</a>
                <?php endif; ?>
                <span class="text-sm">第 <?php echo $page; ?>/<?php echo $totalPages; ?> 页</span>
                <?php if ($page < $totalPages): ?>
                <a href="<?php echo $baseUrl; ?>page=<?php echo $page + 1; ?>" class="px-3 py-1 border rounded hover:bg-gray-100">下一页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
document.getElementById('checkAll').addEventListener('change', function() {
    document.querySelectorAll('input[name="ids[]"]').forEach(el => el.checked = this.checked);
});

async function toggle(id, field, value, btn) {
    const formData = new FormData();
    formData.append('action', 'toggle');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        location.reload();
    }
}

async function deleteItem(id) {
    if (!confirm('确定要删除吗？')) return;
    const formData = new FormData();
    formData.append('action', 'delete');
    formData.append('id', id);
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}

async function batchDelete() {
    const checked = document.querySelectorAll('input[name="ids[]"]:checked');
    if (checked.length === 0) { showMessage('请选择要删除的项', 'error'); return; }
    if (!confirm(`确定要删除选中的 ${checked.length} 项吗？`)) return;
    const formData = new FormData();
    formData.append('action', 'batch_delete');
    checked.forEach(el => formData.append('ids[]', el.value));
    const response = await fetch('', { method: 'POST', body: formData });
    const data = await safeJson(response);
    if (data.code === 0) {
        showMessage('删除成功');
        setTimeout(() => location.reload(), 1000);
    }
}
async function quickTranslate(srcId, toLang, btn) {
    var langNames = <?php echo json_encode($allLangs, JSON_UNESCAPED_UNICODE); ?>;
    var langName = langNames[toLang] || toLang;
    if (!confirm('AI 翻译此案例到 ' + langName + '？\n翻译后将创建草稿，可在编辑页调整。')) return;

    btn.disabled = true;
    btn.textContent = '...';
    btn.className = 'px-1.5 py-0.5 rounded text-xs bg-amber-100 text-amber-600 animate-pulse';

    var fd = new FormData();
    fd.append('action', 'quick_translate');
    fd.append('src_id', srcId);
    fd.append('to_lang', toLang);
    try {
        var resp = await fetch('', { method: 'POST', body: fd });
        var data = await safeJson(resp);
        if (data.code === 0) {
            btn.className = 'px-1.5 py-0.5 rounded text-xs bg-green-100 text-green-600';
            btn.textContent = btn.textContent.replace('...', '✓');
            showMessage(data.msg || '翻译完成');
            setTimeout(function() { location.reload(); }, 1000);
        } else {
            btn.disabled = false;
            btn.className = 'px-1.5 py-0.5 rounded text-xs bg-red-100 text-red-500';
            showMessage(data.msg || '翻译失败', 'error');
        }
    } catch(e) {
        btn.disabled = false;
        btn.className = 'px-1.5 py-0.5 rounded text-xs bg-gray-100 text-gray-400';
        showMessage('请求失败', 'error');
    }
}
</script>

<?php require_once ROOT_PATH . '/admin/includes/footer.php'; ?>
