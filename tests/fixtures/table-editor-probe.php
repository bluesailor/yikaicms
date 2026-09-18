<?php
declare(strict_types=1);

// Standalone loopback-only UI fixture. No site config, session, database or drafts.
if (PHP_SAPI !== 'cli-server' || !in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1'], true)) {
    http_response_code(404);
    exit;
}
define('ROOT_PATH', dirname(__DIR__, 2));
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (str_starts_with($path, '/assets/')) {
    $file = realpath(ROOT_PATH . $path);
    $base = realpath(ROOT_PATH . '/assets') . DIRECTORY_SEPARATOR;
    $mime = ['css' => 'text/css', 'js' => 'text/javascript', 'woff2' => 'font/woff2'];
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    if ($file && str_starts_with($file, $base) && isset($mime[$ext])) {
        header('Content-Type: ' . $mime[$ext]);
        readfile($file);
    } else http_response_code(404);
    exit;
}
if (!in_array($path, ['/', '/canvas', '/styles'], true)) { http_response_code(404); exit; }
$labels = require ROOT_PATH . '/lang/zh-CN.php';
function __(string $key, array $params = []): string
{
    $text = $GLOBALS['labels'][$key] ?? $key;
    foreach ($params as $key => $value) $text = str_replace(':' . $key, (string) $value, $text);
    return $text;
}
function e(?string $text): string { return htmlspecialchars($text ?? '', ENT_QUOTES, 'UTF-8'); }
require ROOT_PATH . '/includes/builder/bootstrap.php';
$element = new TableElement();
$data = $element->defaults();
if ($path === '/styles') {
    echo '<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/assets/css/blox-table.css"><style>body{margin:24px;font:14px sans-serif;color:#1f2937;background:white;--yk-color-primary:#2563eb}main{max-width:900px;margin:auto;display:grid;gap:24px}h2{font-size:16px;margin:0 0 8px}</style><main>';
    foreach (['lines', 'bordered', 'striped', 'brand', 'dark'] as $preset) {
        $sample = $data;
        $sample['table_style'] = $preset;
        echo '<section><h2>' . e(__('blox_table_' . $preset)) . '</h2>' . $element->render($sample) . '</section>';
    }
    $sample = array_replace($data, ['custom_style' => true, 'table_style' => 'striped', 'header_bg' => 'var(--yk-color-primary)',
        'header_color' => '#fff', 'body_bg' => '#f0fdf4', 'body_color' => '#14532d', 'stripe_color' => '#dcfce7',
        'border_color' => '#86efac', 'border_width' => 2, 'padding_y' => 8, 'padding_x' => 24, 'header_align' => 'center', 'header_bold' => false]);
    echo '<section><h2>' . e(__('blox_table_custom')) . '</h2>' . $element->render($sample) . '</section></main>';
    exit;
}
$data['grid'] = ['rows' => [array_map(static fn (int $i): string => 'Column ' . $i, range(1, 10)), array_fill(0, 10, 'Value'), array_fill(0, 10, '')], 'widths' => array_fill(0, 10, 120)];
if ($path === '/canvas') {
    $input = json_decode(file_get_contents('php://input'), true);
    if (is_array($input)) $data['grid'] = TableElement::normalizeGrid($input);
    $source = file_get_contents(ROOT_PATH . '/includes/builder/BloxCanvasPreview.php');
    preg_match('/\$bloxInject = <<<\x27HTML\x27\R(.*?)\RHTML;/s', $source, $match);
    $script = preg_replace_callback('/@@([a-z_]+)@@/', static fn (array $m): string => $m[1] === 'templates_enabled' ? 'false' : json_encode(__($m[1])), $match[1]);
    $tableLabels = ['expand' => __('blox_table_expand')];
    foreach (['row', 'column'] as $axis) foreach (['add', 'delete', 'previous', 'next'] as $action) $tableLabels[$axis . '-' . $action] = __('blox_table_' . $axis . '_' . $action);
    $script = str_replace('__YK_TABLE_LABELS__', json_encode($tableLabels), $script);
    $script = preg_replace('/__YK_[A-Z_]+__/', '{}', $script);
    echo '<!doctype html><link rel="stylesheet" href="/assets/css/blox-table.css"><link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css"><div data-yk-sec="0" data-yk-sec-id="s1"><div data-yk-con="0"><div data-yk-col="0.0"><div data-yk-el="0.0.0" data-yk-el-id="t1" data-yk-el-type="table">' . $element->render($data) . '</div></div></div></div>' . $script;
    exit;
}
?>
<!doctype html><html lang="zh-CN"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<link rel="stylesheet" href="/assets/css/tailwind.css"><link rel="stylesheet" href="/assets/tabler/tabler-icons.min.css">
<style>[x-cloak]{display:none!important}body{margin:0;background:#f3f4f6;font:14px sans-serif}</style>
<script src="/plugins/yikai-builder/assets/blox-pro-table.js"></script><script src="/assets/js/blox-canvas-bridge.js"></script><script src="/assets/js/blox-dialog-focus.js"></script>
<script src="/assets/js/blox-page-settings.js"></script>
<script>
function probe() {
    return {
        ...YikaiBloxPageSettings.mixin({}), ...BloxTableControl.methods, tableExpanded:null, tableCreate:null, tableCanvasEditing:false,
        selEl:{id:'t1',type:'table',data:<?= json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP) ?>},
        uiText:{saveConflict:'Conflict'},
        elSchema(){return {controls:<?= json_encode($element->controls(), JSON_HEX_TAG | JSON_HEX_AMP) ?>};},
        elementPathById(id){return id==='t1'?'0.0.0':'';},
        elementAtPath(path){return path==='0.0.0'?this.selEl:null;},
        selectPath(){},flushHistory(){},toast(text){this.$refs.status.textContent=text;},
        historyData(){return JSON.stringify(this.selEl);},rememberRecentElement(){},
        _addElementRaw(el){this.selEl={id:'t1',type:el.type,data:el.defaults};},
        runCommand(name,run){run.call(this);this.schedulePreview();return {ok:true};},previewClient(){return {cancel(){}};},
        focusDialog(root){this.$nextTick(()=>BloxDialogFocus.open(root,'textarea'));},
        releaseDialog(root){this.$nextTick(()=>BloxDialogFocus.close(root));},
        dialogKeydown(event,root,close){BloxDialogFocus.keydown(event,root,close);},
        schedulePreview(){if(!this.tableCanvasEditing)fetch('/canvas',{method:'POST',body:JSON.stringify(this.selEl.data.grid)}).then(r=>r.text()).then(html=>{if(!this.tableCanvasEditing)this.$refs.canvas.srcdoc=html;});},
        init(){let self=this;new BloxCanvasBridge({getFrame:()=>self.$refs.canvas,onInlineEdit:data=>self.applyTableCanvasCell(data),onTableAction:data=>self.handleTableCanvasAction(data)}).start();this.schedulePreview();}
    };
}
</script><script defer src="/assets/alpinejs/alpine.min.js"></script></head>
<body><main x-data="probe()" class="p-4">
    <button type="button" @click="addElement({type:'table',defaults:{}})" class="rounded bg-blue-600 text-white px-4 py-2 mb-4"><?= e(__('blox_table_create')) ?></button>
    <button type="button" @click="openTableExpanded()" class="rounded bg-blue-600 text-white px-4 py-2 mb-4"><?= e(__('blox_table_expand')) ?></button>
    <output x-ref="status"></output>
    <iframe x-ref="canvas" title="Table canvas" class="w-full h-[600px] border bg-white"></iframe>
    <?php require ROOT_PATH . '/admin/blox_editor/partials/table-expanded.php'; ?>
    <?php require ROOT_PATH . '/admin/blox_editor/partials/table-create.php'; ?>
</main></body></html>
