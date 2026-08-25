<?php
/**
 * Yikai CMS - 安装向导
 *
 * PHP 8.0+
 */

declare(strict_types=1);

// 定义安装目录
define('INSTALL_PATH', __DIR__);
define('ROOT_PATH', dirname(__DIR__));

// 读取 CMS 版本号（单一可信来源：config/version.php）
$cmsVersion = '1.0.0';
$versionFile = ROOT_PATH . '/config/version.php';
if (is_file($versionFile)
    && preg_match("/CMS_VERSION',\\s*'([^']+)'/", (string) file_get_contents($versionFile), $m)) {
    $cmsVersion = $m[1];
}

// 检查是否已安装
// 安全：POST 写操作在已安装时一律拒绝，不给 step 任何例外——否则 ?step=4 可绕过
// 锁触达 action=install，把未转义输入写进 config.php 造成任意代码执行（重装 RCE）。
// GET 仅允许 step=4 展示「安装完成」页，其余跳首页。
$step = (int)($_GET['step'] ?? 1);
if (file_exists(ROOT_PATH . '/installed.lock')) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // AJAX 写请求返回 JSON 提示，而非重定向（防止空响应导致前端报错）
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '系统已安装。如需重新安装，请先删除根目录下的 installed.lock 文件。']);
        exit;
    }
    if ($step !== 4) {
        header('Location: /');
        exit;
    }
}

// ─── 安装向导界面语言（zh / ja / en） ───────────────────────────
// 优先级: ?install_lang=  > cookie  > Accept-Language 自动嗅探  > zh
$supportedLangs = ['zh' => '简体中文', 'ja' => '日本語', 'en' => 'English'];

function detectInstallLang(array $supported): string
{
    if (!empty($_GET['install_lang']) && isset($supported[$_GET['install_lang']])) {
        $lang = (string) $_GET['install_lang'];
        // 持久化 30 天，避免每点一步都要带参数
        setcookie('install_lang', $lang, time() + 86400 * 30, '/');
        return $lang;
    }
    if (!empty($_COOKIE['install_lang']) && isset($supported[$_COOKIE['install_lang']])) {
        return (string) $_COOKIE['install_lang'];
    }
    // 浏览器 Accept-Language 嗅探（按 q 值排序后逐项匹配，
    // 避免裸 str_contains 把"zh-CN,en;q=0.8"误判成 en）
    $picked = pickAcceptLanguage(
        $_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '',
        array_keys($supported)
    );
    return $picked ?? 'zh';
}

/**
 * 解析 Accept-Language 头，按 q 值降序找第一个能映射到 supported 的语言。
 * 子标签前缀匹配：ja-JP → ja，zh-CN / zh-Hans → zh。
 *
 * @param string   $header
 * @param string[] $supported  e.g. ['zh','ja','en']
 */
function pickAcceptLanguage(string $header, array $supported): ?string
{
    if ($header === '') return null;

    $entries = [];
    foreach (explode(',', $header) as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $bits = explode(';', $part);
        $tag  = strtolower(trim($bits[0]));
        $q    = 1.0;
        foreach (array_slice($bits, 1) as $attr) {
            if (preg_match('/q\s*=\s*([\d.]+)/i', $attr, $m)) {
                $q = (float) $m[1];
            }
        }
        $entries[] = ['tag' => $tag, 'q' => $q];
    }
    // 稳定排序：保留出现顺序作 tie-breaker
    usort($entries, fn($a, $b) => $b['q'] <=> $a['q']);

    foreach ($entries as $e) {
        foreach ($supported as $code) {
            $low = strtolower($code);
            if ($e['tag'] === $low || str_starts_with($e['tag'], $low . '-')) {
                return $code;
            }
        }
    }
    return null;
}

$lang = detectInstallLang($supportedLangs);
$L = require INSTALL_PATH . "/lang/{$lang}.php";

// 把简短的安装向导语言映射到 CMS 内部的标签（zh→zh-CN，其它原样）
function installerLangToSiteLang(string $l): string
{
    return $l === 'zh' ? 'zh-CN' : $l;
}

// 限制步骤范围
$step = max(1, min(4, $step));

// 环境检测函数
function checkEnvironment(): array
{
    $checks = [];

    // PHP 版本
    $checks['php'] = [
        'name' => 'PHP 8.0+',
        'required' => '8.0.0',
        'current' => PHP_VERSION,
        'pass' => version_compare(PHP_VERSION, '8.0.0', '>='),
        'type' => 'required'
    ];

    // 必需扩展
    // fileinfo v1.18.6 起必需：上传 MIME 检测的安全基线（PHP 8.0 门槛本身已筛选现代主机）
    // dom：富文本净化（HtmlPolicy）用 DOMDocument 做标签/属性白名单。缺了会降级成
    // 「转义后的纯文本」——不至于 XSS，但整篇正文会显示成乱码标签，必须当必需项。
    $requiredExts = ['pdo', 'json', 'mbstring', 'fileinfo', 'dom'];
    foreach ($requiredExts as $ext) {
        $checks[$ext] = [
            'name' => strtoupper($ext),
            'required' => true,
            'current' => extension_loaded($ext),
            'pass' => extension_loaded($ext),
            'type' => 'required'
        ];
    }

    // 数据库扩展（至少一个）
    $hasMysql = extension_loaded('pdo_mysql');
    $hasSqlite = extension_loaded('pdo_sqlite');
    $checks['pdo_mysql'] = [
        'name' => 'PDO MySQL',
        'required' => false,
        'current' => $hasMysql,
        'pass' => $hasMysql || $hasSqlite,
        'type' => 'database'
    ];
    $checks['pdo_sqlite'] = [
        'name' => 'PDO SQLite',
        'required' => false,
        'current' => $hasSqlite,
        'pass' => $hasMysql || $hasSqlite,
        'type' => 'database'
    ];

    // 可选扩展
    $optionalExts = ['openssl', 'gd'];
    foreach ($optionalExts as $ext) {
        $checks[$ext] = [
            'name' => strtoupper($ext),
            'required' => false,
            'current' => extension_loaded($ext),
            'pass' => true,
            'type' => 'optional'
        ];
    }

    // 目录可写检测
    $dirs = ['config', 'uploads', 'storage'];
    foreach ($dirs as $dir) {
        $path = ROOT_PATH . '/' . $dir;
        $writable = is_dir($path) && is_writable($path);
        $checks['dir_' . $dir] = [
            'name' => '/' . $dir,
            'required' => true,
            'current' => $writable ? 'writable' : (is_dir($path) ? 'not_writable' : 'not_found'),
            'pass' => $writable,
            'type' => 'directory'
        ];
    }

    return $checks;
}

// 检测是否全部通过
function checkAllPass(array $checks): bool
{
    foreach ($checks as $check) {
        if ($check['type'] === 'required' && !$check['pass']) {
            return false;
        }
        if ($check['type'] === 'directory' && !$check['pass']) {
            return false;
        }
    }
    // 至少需要一个数据库扩展
    if (!extension_loaded('pdo_mysql') && !extension_loaded('pdo_sqlite')) {
        return false;
    }
    return true;
}

/**
 * SQL 种子代表默认 zh-CN 状态；安装器随后切换语言、站点 URL 等配置时，可能让
 * 幂等数据迁移重新变成待执行。写安装锁前必须用正式迁移器收尾，保证新站首次启动
 * 就与升级后的站点处于同一状态。
 */
function finalizeFreshInstallMigrations(): void
{
    try {
        require_once ROOT_PATH . '/config/config.php';
        require_once ROOT_PATH . '/includes/functions.php';
        require_once ROOT_PATH . '/includes/models/autoload.php';
        require_once ROOT_PATH . '/includes/Migrator.php';

        foreach (Migrator::loadAll() as $migration) {
            if (Migrator::isApplied($migration)) {
                continue;
            }
            $result = Migrator::runOne($migration);
            if (empty($result['ok'])) {
                throw new RuntimeException(
                    (string) ($migration['id'] ?? 'unknown') . ': ' . (string) ($result['message'] ?? 'failed')
                );
            }
        }

        $pending = [];
        foreach (Migrator::loadAll() as $migration) {
            if (!Migrator::isApplied($migration)) {
                $pending[] = (string) ($migration['id'] ?? 'unknown');
            }
        }
        if ($pending !== []) {
            throw new RuntimeException('仍有待执行迁移：' . implode(', ', $pending));
        }
    } catch (Throwable $e) {
        throw new RuntimeException('全新安装迁移收尾失败：' . $e->getMessage(), 0, $e);
    }
}

// 处理 AJAX 请求
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    // 捕获所有输出，防止 PHP 警告污染 JSON 响应
    ob_start();
    header('Content-Type: application/json; charset=utf-8');

    // 捕获致命错误，确保返回 JSON
    register_shutdown_function(function () {
        $error = error_get_last();
        if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => 'PHP Fatal: ' . $error['message'] . ' in ' . $error['file'] . ':' . $error['line']]);
        }
    });

    $action = $_POST['action'];

    if ($action === 'test_db') {
        $driver = $_POST['db_driver'] ?? 'mysql';
        try {
            if ($driver === 'sqlite') {
                $dbPath = ROOT_PATH . '/storage/database.sqlite';
                $dir = dirname($dbPath);
                if (!is_writable($dir)) {
                    throw new Exception($L['error_dir_not_writable'] . $dir);
                }
                $pdo = new PDO('sqlite:' . $dbPath);
                ob_end_clean();
                echo json_encode(['success' => true, 'message' => $L['db_test_success']]);
            } else {
                $host = $_POST['db_host'] ?? 'localhost';
                $port = $_POST['db_port'] ?? '3306';
                $name = $_POST['db_name'] ?? '';
                $user = $_POST['db_user'] ?? 'root';
                $pass = $_POST['db_pass'] ?? '';
                $createDb = isset($_POST['db_create']);

                // 验证数据库名（允许字母数字下划线连字符；建库语句已反引号转义，DSN 亦支持连字符）
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $name)) {
                    throw new Exception('数据库名只允许字母、数字、下划线和连字符');
                }

                // 验证 host 和 port
                $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
                $port = (string)(int)$port;

                // 先连接不指定数据库
                $dsn = "mysql:host={$host};port={$port};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);

                // 检查数据库是否存在
                $stmt = $pdo->prepare("SHOW DATABASES LIKE ?");
                $stmt->execute([$name]);
                $exists = $stmt->fetch();

                if (!$exists && $createDb) {
                    $pdo->exec("CREATE DATABASE `" . str_replace('`', '``', $name) . "` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
                } elseif (!$exists) {
                    throw new Exception("Database '{$name}' does not exist");
                }

                ob_end_clean();
                echo json_encode(['success' => true, 'message' => $L['db_test_success']]);
            }
        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $L['db_test_fail'] . ': ' . $e->getMessage()]);
        }
        exit;
    }

    if ($action === 'install') {
        // 防御纵深：即使上游锁校验被绕过，install 动作在已安装时也拒绝执行。
        if (file_exists(ROOT_PATH . '/installed.lock')) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => '系统已安装，禁止重复安装。']);
            exit;
        }
        try {
            $driver = $_POST['db_driver'] ?? 'mysql';
            $host = $_POST['db_host'] ?? 'localhost';
            $port = $_POST['db_port'] ?? '3306';
            $dbName = $_POST['db_name'] ?? 'ikaicms';
            $user = $_POST['db_user'] ?? 'root';
            $pass = $_POST['db_pass'] ?? '';
            $prefix = $_POST['db_prefix'] ?? 'yikai_';

            $adminUser = $_POST['admin_user'] ?? 'admin';
            $adminPass = $_POST['admin_pass'] ?? '';
            $adminEmail = $_POST['admin_email'] ?? '';
            $siteName = $_POST['site_name'] ?? 'Yikai CMS';
            $siteUrl = $_POST['site_url'] ?? '';

            // 校验范围：lang/*.php 实际存在的 code（扫文件，扩展时无需改代码）
            $_installerSupported = [];
            foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $_f) {
                $_c = basename($_f, '.php');
                if (strpos($_c, 'dict-') !== 0) $_installerSupported[] = $_c;
            }
            if (empty($_installerSupported)) $_installerSupported = ['zh-CN', 'ja', 'en'];
            $siteLang  = in_array($_POST['site_lang'] ?? '', $_installerSupported, true) ? $_POST['site_lang'] : 'zh-CN';
            $adminLang = in_array($_POST['admin_lang'] ?? '', $_installerSupported, true) ? $_POST['admin_lang'] : 'zh-CN';
            $installDemo = !empty($_POST['install_demo']);
            // 初始场景预设功能 v1.7.4 移除（装完后台 → 外观 → 场景预设 操作）

            // 验证表前缀（仅允许字母数字下划线）
            if (!preg_match('/^[a-zA-Z0-9_]*$/', $prefix)) {
                throw new Exception('表前缀只允许字母、数字和下划线');
            }

            // 连接数据库
            if ($driver === 'sqlite') {
                $dbPath = ROOT_PATH . '/storage/database.sqlite';
                $pdo = new PDO('sqlite:' . $dbPath, null, null, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $sqlFile = INSTALL_PATH . '/sql/sqlite.sql';
            } else {
                // 验证输入
                $host = preg_replace('/[^a-zA-Z0-9.\-:]/', '', $host);
                $port = (string)(int)$port;
                if (!preg_match('/^[a-zA-Z0-9_-]+$/', $dbName)) {
                    throw new Exception('数据库名只允许字母、数字、下划线和连字符');
                }

                $dsn = "mysql:host={$host};port={$port};dbname={$dbName};charset=utf8mb4";
                $pdo = new PDO($dsn, $user, $pass, [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
                ]);
                $sqlFile = INSTALL_PATH . '/sql/mysql.sql';
            }

            // 替换表前缀并执行 SQL
            if (!file_exists($sqlFile)) {
                throw new Exception('SQL 文件不存在: ' . basename($sqlFile));
            }
            $sql = file_get_contents($sqlFile);
            $sql = str_replace('yikai_', $prefix, $sql);

            // 根据演示数据选项剥离 @demo:start ~ @demo:end 区块
            if (!$installDemo) {
                $sql = preg_replace('/--\s*@demo:start.*?--\s*@demo:end/s', '', $sql);
            }

            // 分割并执行 SQL 语句
            $pdo->exec($sql);

            // 镜像默认频道到 en / ja —— 让多语言菜单装完即用
            require_once INSTALL_PATH . '/seed_channel_translations.php';
            seedChannelTranslations($pdo, $prefix);

            // 创建管理员
            $now = time();
            $hashedPass = password_hash($adminPass, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO {$prefix}users (username, password, nickname, email, role_id, status, created_at, updated_at) VALUES (?, ?, ?, ?, 1, 1, ?, ?)");
            $stmt->execute([$adminUser, $hashedPass, $adminUser, $adminEmail, $now, $now]);

            // 更新站点配置
            $stmt = $pdo->prepare("UPDATE {$prefix}settings SET value = ? WHERE `key` = 'site_name'");
            $stmt->execute([$siteName]);
            if ($siteUrl) {
                $stmt = $pdo->prepare("UPDATE {$prefix}settings SET value = ? WHERE `key` = 'site_url'");
                $stmt->execute([rtrim($siteUrl, '/')]);
            }

            // SEO 标题默认跟随站点名称；并清除演示数据里各语言的品牌标题
            // （否则前台 <title> 会残留演示品牌，如英文站显示 "YikaiCMS - Professional Enterprise CMS"）
            $stmt = $pdo->prepare("UPDATE {$prefix}settings SET value = ? WHERE `key` = 'seo_title'");
            $stmt->execute([$siteName]);
            $pdo->exec("DELETE FROM {$prefix}settings WHERE `key` LIKE 'seo\\_title\\_%'");

            // 前台/后台语言
            $stmt = $pdo->prepare("UPDATE {$prefix}settings SET value = ? WHERE `key` = 'site_lang'");
            $stmt->execute([$siteLang]);
            $stmt = $pdo->prepare("UPDATE {$prefix}settings SET value = ? WHERE `key` = 'admin_lang'");
            $stmt->execute([$adminLang]);

            // 默认仅启用所选站点语言（其它语言可在后台「语言设置」中开启）
            $enabledJson = json_encode([$siteLang]);
            if ($driver === 'sqlite') {
                $stmt = $pdo->prepare("INSERT OR REPLACE INTO {$prefix}settings (`group`, `key`, `value`, `name`, `type`, `sort_order`) VALUES ('site', 'enabled_languages', ?, '', '', 0)");
            } else {
                $stmt = $pdo->prepare("INSERT INTO {$prefix}settings (`group`, `key`, `value`, `name`, `type`, `sort_order`) VALUES ('site', 'enabled_languages', ?, '', '', 0) ON DUPLICATE KEY UPDATE value = VALUES(value)");
            }
            $stmt->execute([$enabledJson]);

            // 生成配置文件
            $configFile = ROOT_PATH . '/config/config.sample.php';
            if (!file_exists($configFile)) {
                throw new Exception('配置模板文件不存在: config/config.sample.php');
            }
            $configTemplate = file_get_contents($configFile);
            $sessionId = substr(md5(uniqid((string)mt_rand(), true)), 0, 8);
            $encryptKey = 'ik_' . bin2hex(random_bytes(16));
            // 安全：所有用户输入都落进单引号 define('X','...') 字面量。占位符替换前必须转义，
            // 否则 db_pass 传 `'); system($_GET[c]); //` 之类可闭合引号注入可执行 PHP（RCE）。
            // 单引号 PHP 字符串只需转义 ' 与 \，addslashes 正好覆盖。driver 限枚举、port 限数字。
            $driver = in_array($driver, ['mysql', 'sqlite'], true) ? $driver : 'mysql';
            $port   = (string)(int)$port;
            $esc = static fn ($v): string => addslashes((string)$v);
            $configContent = str_replace(
                ['{{DB_DRIVER}}', '{{DB_HOST}}', '{{DB_PORT}}', '{{DB_NAME}}', '{{DB_USER}}', '{{DB_PASS}}', '{{SITE_NAME}}', '{{SITE_URL}}', '{{SESSION_ID}}', '{{ENCRYPT_KEY}}', "define('DB_PREFIX', 'yikai_')"],
                [$driver, $esc($host), $port, $esc($dbName), $esc($user), $esc($pass), $esc($siteName), $esc($siteUrl), $sessionId, $encryptKey, "define('DB_PREFIX', '" . $esc($prefix) . "')"],
                $configTemplate
            );

            if (!file_put_contents(ROOT_PATH . '/config/config.php', $configContent)) {
                throw new Exception($L['error_config_write']);
            }

            finalizeFreshInstallMigrations();

            // 创建安装锁
            file_put_contents(ROOT_PATH . '/installed.lock', date('Y-m-d H:i:s'));

            ob_end_clean();
            echo json_encode(['success' => true, 'message' => $L['install_success']]);

        } catch (Exception $e) {
            ob_end_clean();
            echo json_encode(['success' => false, 'message' => $L['install_fail'] . ': ' . $e->getMessage()]);
        }
        exit;
    }

    exit;
}

// 环境检测结果
$envChecks = checkEnvironment();
$envAllPass = checkAllPass($envChecks);
?>
<!DOCTYPE html>
<html lang="<?php echo $lang; ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo $L['title']; ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <style>
        .step-item.active { color: #3b82f6; border-color: #3b82f6; }
        .step-item.completed { color: #10b981; border-color: #10b981; }
        .step-line.completed { background-color: #10b981; }
    </style>
</head>
<body class="bg-gray-100 min-h-screen">
    <div class="container mx-auto px-4 py-8 max-w-3xl">
        <!-- 安装向导界面语言切换器（仅影响向导本身的提示文本，
             与 step 3 选择的"前台语言/后台语言"无关） -->
        <div class="flex justify-end mb-4 text-sm">
            <div class="inline-flex items-center gap-1 bg-white border border-gray-200 rounded-full px-1 py-1">
                <?php foreach ($supportedLangs as $code => $name):
                    $active = $code === $lang;
                    $url = '?step=' . $step . '&install_lang=' . $code;
                ?>
                <a href="<?php echo $url; ?>"
                   class="px-3 py-1 rounded-full transition <?php echo $active
                       ? 'bg-gray-900 text-white'
                       : 'text-gray-500 hover:text-gray-900'; ?>">
                    <?php echo $name; ?>
                </a>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- 头部 -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2"><?php echo $L['title']; ?></h1>
            <p class="text-gray-400 text-sm">v<?php echo htmlspecialchars($cmsVersion); ?></p>
        </div>

        <!-- 步骤指示器 -->
        <div class="flex items-center justify-center mb-8">
            <?php for ($i = 1; $i <= 4; $i++): ?>
                <?php
                $isActive = $i === $step;
                $isCompleted = $i < $step;
                $stepClass = $isActive ? 'active' : ($isCompleted ? 'completed' : '');
                ?>
                <div class="flex items-center">
                    <div class="step-item w-10 h-10 rounded-full border-2 flex items-center justify-center font-bold <?php echo $stepClass; ?> <?php echo !$stepClass ? 'border-gray-300 text-gray-400' : ''; ?>">
                        <?php echo $isCompleted ? '✓' : $i; ?>
                    </div>
                    <?php if ($i < 4): ?>
                        <div class="w-16 h-1 mx-2 <?php echo $isCompleted ? 'step-line completed' : 'bg-gray-300'; ?>"></div>
                    <?php endif; ?>
                </div>
            <?php endfor; ?>
        </div>

        <!-- 内容区域 -->
        <div class="bg-white rounded-lg shadow-lg p-8">
            <?php if ($step === 1): ?>
                <!-- 步骤1：环境检测 -->
                <h2 class="text-xl font-bold mb-6"><?php echo $L['step1']; ?></h2>

                <table class="w-full mb-6">
                    <thead>
                        <tr class="border-b">
                            <th class="text-left py-2"><?php echo $L['env_check']; ?></th>
                            <th class="text-center py-2"><?php echo $L['env_required']; ?></th>
                            <th class="text-center py-2"><?php echo $L['env_current']; ?></th>
                            <th class="text-center py-2"><?php echo $L['env_status']; ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($envChecks as $key => $check): ?>
                            <tr class="border-b">
                                <td class="py-2"><?php echo $check['name']; ?></td>
                                <td class="text-center py-2">
                                    <?php
                                    if ($check['type'] === 'required') echo $L['env_required_ext'];
                                    elseif ($check['type'] === 'optional') echo $L['env_optional_ext'];
                                    elseif ($check['type'] === 'directory') echo $L['env_writable'];
                                    else echo '-';
                                    ?>
                                </td>
                                <td class="text-center py-2">
                                    <?php
                                    if (is_bool($check['current'])) {
                                        echo $check['current'] ? '✓' : '✗';
                                    } elseif ($check['current'] === 'writable') {
                                        echo $L['env_writable'];
                                    } elseif ($check['current'] === 'not_writable') {
                                        echo $L['env_not_writable'];
                                    } elseif ($check['current'] === 'not_found') {
                                        echo $L['env_not_found'];
                                    } else {
                                        echo $check['current'];
                                    }
                                    ?>
                                </td>
                                <td class="text-center py-2">
                                    <?php if ($check['pass']): ?>
                                        <span class="text-green-600 font-bold"><?php echo $L['env_pass']; ?></span>
                                    <?php else: ?>
                                        <span class="text-red-600 font-bold"><?php echo $L['env_fail']; ?></span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>

                <?php if (!$envAllPass): ?>
                    <div class="bg-red-50 text-red-600 p-4 rounded mb-6">
                        <?php echo $L['env_check_fail']; ?>
                    </div>
                <?php endif; ?>

                <div class="flex justify-end">
                    <?php if ($envAllPass): ?>
                        <a href="?step=2&install_lang=<?php echo $lang; ?>" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                            <?php echo $L['next']; ?>
                        </a>
                    <?php else: ?>
                        <a href="?step=1&install_lang=<?php echo $lang; ?>" class="bg-gray-400 text-white px-6 py-2 rounded">
                            <?php echo $L['retry']; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if ($envAllPass && extension_loaded('pdo_sqlite')): ?>
                <!-- 一键极速安装（SQLite，零配置） -->
                <div class="mt-6 border-t pt-6">
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-4 flex items-start gap-3">
                        <div class="text-2xl leading-none">⚡</div>
                        <div class="flex-1 min-w-0">
                            <div class="font-bold text-gray-800 mb-1"><?php echo $L['quick_title']; ?></div>
                            <p class="text-sm text-gray-600 mb-3"><?php echo $L['quick_desc']; ?></p>
                            <button type="button" id="quickBtn" onclick="quickInstall()" class="bg-blue-600 hover:bg-blue-700 text-white px-5 py-2 rounded text-sm font-medium"><?php echo $L['quick_button']; ?></button>
                            <div id="quickResult" class="hidden"></div>
                        </div>
                    </div>
                </div>
                <script>
                var QL = <?php echo json_encode([
                    'confirm'    => $L['quick_confirm'],
                    'installing' => $L['quick_installing'],
                    'button'     => $L['quick_button'],
                    'done'       => $L['quick_done'],
                    'account'    => $L['quick_account'],
                    'password'   => $L['quick_password'],
                    'login_url'  => $L['quick_login_url'],
                    'copy'       => $L['quick_copy'],
                    'copied'     => $L['quick_copied'],
                    'copy_fail'  => $L['quick_copy_fail'],
                    'copy_all'   => $L['quick_copy_all'],
                    'warn'       => $L['quick_warn'],
                    'goto'       => $L['goto_admin'],
                    'fail'       => $L['install_fail'] ?? 'Install failed',
                ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
                async function quickInstall() {
                    if (!confirm(QL.confirm)) return;
                    var btn = document.getElementById('quickBtn');
                    btn.disabled = true; btn.textContent = QL.installing;
                    var pass = 'Yk' + Math.random().toString(36).slice(2, 8) + Math.floor(Math.random() * 90 + 10);
                    var fd = new FormData();
                    fd.append('action', 'install');
                    fd.append('db_driver', 'sqlite');
                    fd.append('db_prefix', 'yikai_');
                    fd.append('admin_user', 'admin');
                    fd.append('admin_pass', pass);
                    fd.append('admin_email', '');
                    fd.append('site_name', 'Yikai CMS');
                    fd.append('install_demo', '1');
                    fd.append('site_lang', '<?php echo $lang; ?>');
                    fd.append('admin_lang', '<?php echo $lang; ?>');
                    try {
                        var resp = await fetch('', { method: 'POST', body: fd });
                        var data = await resp.json();
                        if (data.success) {
                            var box = document.getElementById('quickResult');
                            box.className = 'mt-4 bg-white border border-green-300 rounded-lg p-4';
                            box.innerHTML =
                                '<div class="text-green-700 font-bold mb-2">✓ ' + QL.done + '</div>' +
                                '<div class="text-sm text-gray-700 mb-1">' + QL.login_url + '：<b class="select-all" style="font-family:ui-monospace,Menlo,Consolas,monospace">' + window.location.origin + '/admin/login.php</b></div>' +
                                '<div class="text-sm text-gray-700 mb-1">' + QL.account + '：<b>admin</b></div>' +
                                '<div class="text-sm text-gray-700 mb-1">' + QL.password + '：</div>' +
                                // 密码只显示这一次：等宽字体避免 0/O、l/1 混淆；可整段选中；提供一键复制
                                '<div class="flex items-stretch gap-2 mb-3">' +
                                  '<input id="quickPass" type="text" readonly value="' + pass + '" onclick="this.select()"' +
                                  ' class="flex-1 min-w-0 border border-gray-300 rounded px-3 py-2 text-sm bg-gray-50 text-red-600 font-bold select-all"' +
                                  ' style="font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;letter-spacing:.05em">' +
                                  '<button type="button" id="quickCopy" onclick="copyQuickPass()"' +
                                  ' class="shrink-0 bg-gray-800 hover:bg-black text-white px-4 rounded text-sm font-medium">' + QL.copy + '</button>' +
                                '</div>' +
                                '<div class="text-xs text-amber-600 mb-3">' + QL.warn + '</div>' +
                                '<a href="/admin/" class="inline-block bg-primary hover:bg-secondary text-white px-5 py-2 rounded text-sm font-medium">' + QL.goto + '</a>';
                            // 直接选中，Ctrl+C 就能复制
                            var pf = document.getElementById('quickPass');
                            if (pf) { pf.focus(); pf.select(); }
                            box.classList.remove('hidden');
                            btn.style.display = 'none';
                        } else {
                            alert(data.message || QL.fail);
                            btn.disabled = false; btn.textContent = QL.button;
                        }
                    } catch (e) {
                        alert(QL.fail + ': ' + e.message);
                        btn.disabled = false; btn.textContent = QL.button;
                    }
                }

                // 安装页常跑在 http 或局域网 IP 下，navigator.clipboard 不可用，故保留 execCommand 降级
                function copyQuickPass() {
                    var el = document.getElementById('quickPass');
                    var btn = document.getElementById('quickCopy');
                    if (!el) return;
                    el.select();
                    el.setSelectionRange(0, 99999);
                    var ok = false;
                    try {
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(el.value);
                            ok = true;
                        } else {
                            ok = document.execCommand('copy');
                        }
                    } catch (e) { ok = false; }
                    if (btn) {
                        var old = btn.textContent;
                        btn.textContent = ok ? QL.copied : QL.copy_fail;
                        btn.classList.toggle('bg-green-600', ok);
                        btn.classList.toggle('bg-gray-800', !ok);
                        setTimeout(function () {
                            btn.textContent = old;
                            btn.classList.remove('bg-green-600');
                            btn.classList.add('bg-gray-800');
                        }, 1800);
                    }
                }
                </script>
                <?php endif; ?>

            <?php elseif ($step === 2): ?>
                <!-- 步骤2：数据库配置 -->
                <h2 class="text-xl font-bold mb-6"><?php echo $L['step2']; ?></h2>

                <form id="dbForm">
                    <!-- 数据库类型选择 -->
                    <div class="mb-6">
                        <label class="block text-gray-700 font-bold mb-2"><?php echo $L['db_type']; ?></label>
                        <div class="grid grid-cols-2 gap-4">
                            <label class="border-2 rounded-lg p-4 cursor-pointer hover:border-primary transition <?php echo extension_loaded('pdo_mysql') ? '' : 'opacity-50'; ?>">
                                <input type="radio" name="db_driver" value="mysql" class="mr-2" <?php echo extension_loaded('pdo_mysql') ? 'checked' : 'disabled'; ?>>
                                <span class="font-bold"><?php echo $L['db_mysql']; ?></span>
                                <p class="text-sm text-gray-500 mt-1"><?php echo $L['db_mysql_desc']; ?></p>
                            </label>
                            <label class="border-2 rounded-lg p-4 cursor-pointer hover:border-primary transition <?php echo extension_loaded('pdo_sqlite') ? '' : 'opacity-50'; ?>">
                                <input type="radio" name="db_driver" value="sqlite" class="mr-2" <?php echo !extension_loaded('pdo_mysql') && extension_loaded('pdo_sqlite') ? 'checked' : ''; ?> <?php echo extension_loaded('pdo_sqlite') ? '' : 'disabled'; ?>>
                                <span class="font-bold"><?php echo $L['db_sqlite']; ?></span>
                                <p class="text-sm text-gray-500 mt-1"><?php echo $L['db_sqlite_desc']; ?></p>
                            </label>
                        </div>
                    </div>

                    <!-- MySQL 配置 -->
                    <div id="mysqlConfig" class="space-y-4">
                        <div class="grid grid-cols-3 gap-4">
                            <div class="col-span-2">
                                <label class="block text-gray-700 mb-1"><?php echo $L['db_host']; ?></label>
                                <input type="text" name="db_host" value="localhost" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-gray-700 mb-1"><?php echo $L['db_port']; ?></label>
                                <input type="text" name="db_port" value="3306" class="w-full border rounded px-3 py-2">
                            </div>
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-1"><?php echo $L['db_name']; ?></label>
                            <input type="text" name="db_name" value="ikaicms" class="w-full border rounded px-3 py-2">
                        </div>
                        <div class="grid grid-cols-2 gap-4">
                            <div>
                                <label class="block text-gray-700 mb-1"><?php echo $L['db_user']; ?></label>
                                <input type="text" name="db_user" value="root" class="w-full border rounded px-3 py-2">
                            </div>
                            <div>
                                <label class="block text-gray-700 mb-1"><?php echo $L['db_pass']; ?></label>
                                <input type="password" name="db_pass" class="w-full border rounded px-3 py-2">
                            </div>
                        </div>
                        <div>
                            <label class="flex items-center">
                                <input type="checkbox" name="db_create" value="1" class="mr-2">
                                <?php echo $L['db_create_new']; ?>
                            </label>
                        </div>
                    </div>

                    <!-- 表前缀 -->
                    <div class="mt-4">
                        <label class="block text-gray-700 mb-1"><?php echo $L['db_prefix']; ?></label>
                        <input type="text" name="db_prefix" value="yikai_" class="w-full border rounded px-3 py-2">
                    </div>

                    <!-- 警告 -->
                    <div class="mt-4 bg-amber-50 border border-amber-200 rounded-lg p-3 flex items-start gap-2">
                        <svg class="w-5 h-5 text-amber-500 mt-0.5 flex-shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg>
                        <p class="text-sm text-amber-700">安装时将删除数据库中同名的表并重新创建。如果数据库中已有数据，请先备份。</p>
                    </div>

                    <!-- 演示数据与语言选择已搬到 step 3，避免在两个表单里重复维护 -->

                    <!-- 测试按钮 -->
                    <div class="mt-6">
                        <button type="button" id="testDbBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition cursor-pointer">
                            <?php echo $L['db_test']; ?>
                        </button>
                        <span id="testResult" class="ml-4"></span>
                    </div>
                </form>

                <div class="flex justify-between mt-8">
                    <a href="?step=1&install_lang=<?php echo $lang; ?>" class="border border-gray-300 hover:bg-gray-100 px-6 py-2 rounded transition">
                        <?php echo $L['prev']; ?>
                    </a>
                    <a href="?step=3&install_lang=<?php echo $lang; ?>" id="nextStep2" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                        <?php echo $L['next']; ?>
                    </a>
                </div>

                <script>
                // 显示/隐藏 MySQL 配置
                document.querySelectorAll('input[name="db_driver"]').forEach(el => {
                    el.addEventListener('change', function() {
                        document.getElementById('mysqlConfig').style.display = this.value === 'mysql' ? 'block' : 'none';
                    });
                });

                // 测试数据库连接
                document.getElementById('testDbBtn').addEventListener('click', async function() {
                    const form = document.getElementById('dbForm');
                    const formData = new FormData(form);
                    formData.append('action', 'test_db');

                    const result = document.getElementById('testResult');
                    result.textContent = '...';

                    try {
                        const response = await fetch('', { method: 'POST', body: formData });
                        const data = await response.json();
                        const span = document.createElement('span');
                        span.className = data.success ? 'text-green-600' : 'text-red-600';
                        span.textContent = data.message;
                        result.innerHTML = '';
                        result.appendChild(span);
                    } catch (e) {
                        result.innerHTML = '<span class="text-red-600">Error</span>';
                    }
                });

                // 保存配置到 sessionStorage
                document.getElementById('nextStep2').addEventListener('click', function(e) {
                    const form = document.getElementById('dbForm');
                    const formData = new FormData(form);
                    for (const [key, value] of formData.entries()) {
                        sessionStorage.setItem(key, value);
                    }
                });
                </script>

            <?php elseif ($step === 3): ?>
                <!-- 步骤3：管理员设置 -->
                <h2 class="text-xl font-bold mb-6"><?php echo $L['step3']; ?></h2>

                <form id="adminForm" class="space-y-4">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['site_name']; ?></label>
                        <input type="text" name="site_name" value="Yikai CMS" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['site_url']; ?></label>
                        <input type="text" name="site_url" value="<?php echo htmlspecialchars('http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . ($_SERVER['HTTP_HOST'] ?? ''), ENT_QUOTES); ?>" class="w-full border rounded px-3 py-2" required>
                    </div>
                    <hr class="my-6">
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['admin_user']; ?></label>
                        <input type="text" name="admin_user" value="admin" class="w-full border rounded px-3 py-2" required pattern="[a-zA-Z0-9]{4,20}">
                        <p class="text-sm text-gray-500 mt-1"><?php echo $L['admin_user_tip']; ?></p>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['admin_pass']; ?></label>
                        <div class="flex gap-2">
                            <div class="relative flex-1">
                                <input type="password" name="admin_pass" id="admin_pass" class="w-full border rounded px-3 py-2 pr-10" required minlength="6">
                                <button type="button" onclick="togglePassword('admin_pass')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 cursor-pointer">
                                    <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                                </button>
                            </div>
                            <button type="button" onclick="generatePassword()" class="px-3 py-2 border rounded text-sm text-gray-600 hover:bg-gray-50 whitespace-nowrap cursor-pointer" title="随机生成密码">随机生成</button>
                        </div>
                        <p class="text-sm text-gray-500 mt-1"><?php echo $L['admin_pass_tip']; ?></p>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['admin_email']; ?></label>
                        <input type="email" name="admin_email" class="w-full border rounded px-3 py-2">
                    </div>

                    <hr class="my-6">

                    <!-- 前台/后台语言选择（默认跟随当前安装向导语言；扫 lang/*.php 自动发现可选项） -->
                    <?php
                    $defaultSite = installerLangToSiteLang($lang);
                    $_installerLangLabels = ['zh-CN' => '简体中文', 'ja' => '日本語', 'en' => 'English', 'ko' => '한국어', 'fr' => 'Français', 'de' => 'Deutsch', 'es' => 'Español'];
                    $siteLangOptions = [];
                    foreach (glob(dirname(__DIR__) . '/lang/*.php') ?: [] as $_f) {
                        $_code = basename($_f, '.php');
                        if (strpos($_code, 'dict-') === 0) continue;
                        $siteLangOptions[$_code] = $_installerLangLabels[$_code] ?? $_code;
                    }
                    if (empty($siteLangOptions)) {
                        $siteLangOptions = ['zh-CN' => '简体中文', 'ja' => '日本語', 'en' => 'English'];
                    }
                    ?>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-gray-700 mb-1"><?php echo $L['site_lang_label']; ?></label>
                            <select name="site_lang" class="w-full border rounded px-3 py-2 bg-white">
                                <?php foreach ($siteLangOptions as $code => $name): ?>
                                <option value="<?php echo $code; ?>" <?php echo $code === $defaultSite ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-sm text-gray-500 mt-1"><?php echo $L['site_lang_tip']; ?></p>
                        </div>
                        <div>
                            <label class="block text-gray-700 mb-1"><?php echo $L['admin_lang_label']; ?></label>
                            <select name="admin_lang" class="w-full border rounded px-3 py-2 bg-white">
                                <?php foreach ($siteLangOptions as $code => $name): ?>
                                <option value="<?php echo $code; ?>" <?php echo $code === $defaultSite ? 'selected' : ''; ?>>
                                    <?php echo $name; ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <p class="text-sm text-gray-500 mt-1"><?php echo $L['admin_lang_tip']; ?></p>
                        </div>
                    </div>

                    <div class="mt-2">
                        <label class="inline-flex items-center gap-2 cursor-pointer">
                            <input type="checkbox" name="install_demo" value="1" checked class="rounded">
                            <span class="text-gray-700"><?php echo $L['install_demo_label']; ?></span>
                        </label>
                        <p class="text-sm text-gray-500 mt-1 ml-6"><?php echo $L['install_demo_tip']; ?></p>
                    </div>

                    <!-- 初始场景预设：v1.7.4 移除（默认就是企业站 CMS，装完后台 → 外观 → 场景预设 可再换） -->
                </form>

                <div id="installProgress" class="hidden mt-6">
                    <div class="bg-blue-50 p-4 rounded">
                        <p class="text-blue-700"><?php echo $L['installing']; ?></p>
                        <div class="mt-2 h-2 bg-blue-200 rounded overflow-hidden">
                            <div id="progressBar" class="h-full bg-blue-500 transition-all" style="width: 0%"></div>
                        </div>
                    </div>
                </div>

                <div id="installResult" class="hidden mt-6"></div>

                <div class="flex justify-between mt-8" id="stepButtons">
                    <a href="?step=2&install_lang=<?php echo $lang; ?>" class="border border-gray-300 hover:bg-gray-100 px-6 py-2 rounded transition">
                        <?php echo $L['prev']; ?>
                    </a>
                    <button type="button" id="installBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition cursor-pointer">
                        <?php echo $L['finish']; ?>
                    </button>
                </div>

                <script>
                function togglePassword(id) {
                    var input = document.getElementById(id);
                    var btn = input.parentElement.querySelector('button');
                    var isHidden = input.type === 'password';
                    input.type = isHidden ? 'text' : 'password';
                    btn.querySelector('.eye-open').classList.toggle('hidden', !isHidden);
                    btn.querySelector('.eye-closed').classList.toggle('hidden', isHidden);
                }

                function generatePassword() {
                    var chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789!@#$%';
                    var pwd = '';
                    for (var i = 0; i < 12; i++) pwd += chars.charAt(Math.floor(Math.random() * chars.length));
                    var input = document.getElementById('admin_pass');
                    input.value = pwd;
                    input.type = 'text';
                    var btn = input.parentElement.querySelector('button');
                    btn.querySelector('.eye-open').classList.remove('hidden');
                    btn.querySelector('.eye-closed').classList.add('hidden');
                }

                // 从 sessionStorage 恢复 step 2 (数据库) 字段；
                // site_lang / admin_lang / install_demo 现在是 step 3 表单字段，
                // 不再走 sessionStorage 通道，否则会覆盖用户在 step 3 的选择。
                const dbFields = ['db_driver', 'db_host', 'db_port', 'db_name', 'db_user', 'db_pass', 'db_prefix', 'db_create'];

                // 安装
                document.getElementById('installBtn').addEventListener('click', async function() {
                    const form = document.getElementById('adminForm');

                    if (!form.checkValidity()) {
                        form.reportValidity();
                        return;
                    }

                    // 显示进度
                    document.getElementById('stepButtons').classList.add('hidden');
                    document.getElementById('installProgress').classList.remove('hidden');
                    const progressBar = document.getElementById('progressBar');

                    // 准备数据
                    const formData = new FormData(form);
                    formData.append('action', 'install');

                    // 添加数据库配置
                    dbFields.forEach(field => {
                        const value = sessionStorage.getItem(field);
                        if (value) formData.append(field, value);
                    });

                    progressBar.style.width = '30%';

                    try {
                        const response = await fetch('', { method: 'POST', body: formData });
                        progressBar.style.width = '80%';
                        const text = await response.text();
                        let data;
                        try {
                            data = JSON.parse(text);
                        } catch (parseErr) {
                            throw new Error(text || '服务器返回空响应，请检查 PHP 错误日志');
                        }
                        progressBar.style.width = '100%';

                        document.getElementById('installProgress').classList.add('hidden');
                        const result = document.getElementById('installResult');
                        result.classList.remove('hidden');

                        if (data.success) {
                            // 就地渲染完成信息，不跳 ?step=4：安装成功的同时 installed.lock 已写入，
                            // .htaccess/nginx 会立即封禁整个 install/ 目录（防重装绕过），
                            // 此时跳转必然 403/404，完成页与登录信息根本显示不出来。
                            showInstallDone(form);
                        } else {
                            result.innerHTML = '<div class="bg-red-50 text-red-700 p-4 rounded"></div>';
                            result.querySelector('div').textContent = data.message;
                            document.getElementById('stepButtons').classList.remove('hidden');
                        }
                    function showInstallDone(f) {
                        var user = (f.querySelector('[name=admin_user]') || {}).value || 'admin';
                        var url = window.location.origin + '/admin/login.php';
                        var L = <?php echo json_encode([
                            'title'   => $L['install_complete'],
                            'desc'    => $L['install_complete_desc'],
                            'info'    => $L['login_info_title'],
                            'url'     => $L['quick_login_url'],
                            'account' => $L['quick_account'],
                            'pass'    => $L['quick_password'],
                            'passHint'=> $L['login_info_pass_hint'],
                            'tip'     => $L['login_info_tip'],
                            'copyAll' => $L['quick_copy_all'],
                            'copied'  => $L['quick_copied'],
                            'failed'  => $L['quick_copy_fail'],
                            'goto'    => $L['goto_admin'],
                            'sec'     => $L['security_tip'],
                        ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG); ?>;
                        var wrap = document.createElement('div');
                        wrap.className = 'text-center';
                        var h = document.createElement('h2');
                        h.className = 'text-2xl font-bold text-green-600 mb-2';
                        h.textContent = L.title;
                        var p = document.createElement('p');
                        p.className = 'text-gray-600 mb-5';
                        p.textContent = L.desc;
                        var card = document.createElement('div');
                        card.className = 'bg-blue-50 border border-blue-200 rounded-lg p-5 mb-6 text-left';
                        var head = document.createElement('div');
                        head.className = 'flex items-center justify-between mb-3';
                        var ht = document.createElement('h3');
                        ht.className = 'font-bold text-blue-900 text-sm';
                        ht.textContent = L.info;
                        var cbtn = document.createElement('button');
                        cbtn.type = 'button';
                        cbtn.className = 'text-xs bg-white border border-blue-300 text-blue-700 hover:bg-blue-100 px-3 py-1 rounded transition';
                        cbtn.textContent = L.copyAll;
                        head.appendChild(ht); head.appendChild(cbtn);
                        var dl = document.createElement('dl');
                        dl.className = 'space-y-2 text-sm';
                        function row(label, value, mono, muted) {
                            var d = document.createElement('div');
                            d.className = 'flex items-start gap-2';
                            var dt = document.createElement('dt');
                            dt.className = 'text-gray-500 w-20 shrink-0';
                            dt.textContent = label;
                            var dd = document.createElement('dd');
                            dd.className = 'flex-1 break-all ' + (mono ? 'font-mono select-all ' : '') + (muted ? 'text-gray-500' : 'text-blue-800');
                            dd.textContent = value;
                            d.appendChild(dt); d.appendChild(dd); dl.appendChild(d);
                        }
                        var pass = (f.querySelector('[name=admin_pass]') || {}).value || '';
                        row(L.url, url, true, false);
                        row(L.account, user, true, false);
                        // 密码就是用户上一步自己设的，此处回显 + 纳入复制，便于整段发给客户；
                        // 取不到（理论上不会）则退回文字提示，不留空行。
                        row(L.pass, pass || L.passHint, pass !== '', pass === '');
                        var tip = document.createElement('p');
                        tip.className = 'text-xs text-blue-700 mt-3';
                        tip.textContent = L.tip;
                        card.appendChild(head); card.appendChild(dl); card.appendChild(tip);
                        var go = document.createElement('a');
                        go.href = '/admin/';
                        go.className = 'inline-block bg-primary hover:bg-secondary text-white text-lg font-bold px-8 py-3 rounded-lg shadow transition';
                        go.textContent = L.goto;
                        var sec = document.createElement('div');
                        sec.className = 'bg-yellow-50 text-yellow-700 p-4 rounded mt-6 text-sm';
                        sec.textContent = L.sec;
                        wrap.appendChild(h); wrap.appendChild(p); wrap.appendChild(card); wrap.appendChild(go); wrap.appendChild(sec);
                        result.innerHTML = '';
                        result.appendChild(wrap);
                        document.getElementById('stepButtons').classList.add('hidden');

                        // http / 局域网 IP 下 clipboard API 不可用，故保留 execCommand 降级
                        cbtn.onclick = function () {
                            var text = L.url + ': ' + url + '\n' + L.account + ': ' + user
                                     + (pass ? '\n' + L.pass + ': ' + pass : '');
                            var ok = function () { cbtn.textContent = L.copied; setTimeout(function () { cbtn.textContent = L.copyAll; }, 2000); };
                            var fb = function () {
                                var ta = document.createElement('textarea');
                                ta.value = text; ta.style.cssText = 'position:fixed;left:-9999px';
                                document.body.appendChild(ta); ta.select();
                                var done = false;
                                try { done = document.execCommand('copy'); } catch (e) {}
                                document.body.removeChild(ta);
                                if (done) { ok(); } else { cbtn.textContent = L.failed; setTimeout(function () { cbtn.textContent = L.copyAll; }, 3000); }
                            };
                            if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(ok, fb); } else { fb(); }
                        };
                    }

                    } catch (e) {
                        document.getElementById('installProgress').classList.add('hidden');
                        const errResult = document.getElementById('installResult');
                        errResult.classList.remove('hidden');
                        errResult.innerHTML = '<div class="bg-red-50 text-red-700 p-4 rounded break-all"></div>';
                        errResult.querySelector('div').textContent = 'Error: ' + e.message;
                        document.getElementById('stepButtons').classList.remove('hidden');
                    }
                });
                </script>

            <?php elseif ($step === 4): ?>
                <!-- 步骤4：安装完成 -->
                <div class="text-center">
                    <div class="w-20 h-20 bg-green-100 rounded-full flex items-center justify-center mx-auto mb-6">
                        <svg class="w-10 h-10 text-green-600" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 13l4 4L19 7"></path>
                        </svg>
                    </div>
                    <h2 class="text-2xl font-bold text-green-600 mb-4"><?php echo $L['install_complete']; ?></h2>
                    <p class="text-gray-600 mb-6"><?php echo $L['install_complete_desc']; ?></p>

                    <?php
                    // 登录信息卡：完成页只给一个「进入后台」按钮是不够的——装完要把网址发给客户、
                    // 或换台机器登录时，没有可复制的网址和用户名。密码不显示（常规安装是用户自设的）。
                    $__scheme   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                    $__loginUrl = $__scheme . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . '/admin/login.php';
                    $__adminName = '';
                    try {
                        if (is_file(dirname(__DIR__) . '/config/config.php')) {
                            require_once dirname(__DIR__) . '/config/config.php';
                            require_once dirname(__DIR__) . '/includes/functions.php';
                            require_once dirname(__DIR__) . '/includes/models/autoload.php';
                            $__row = db()->fetchOne('SELECT username FROM ' . DB_PREFIX . 'users ORDER BY id ASC LIMIT 1');
                            $__adminName = (string) ($__row['username'] ?? '');
                        }
                    } catch (\Throwable $e) {
                        $__adminName = '';   // 取不到就只显示网址，不影响完成页
                    }
                    ?>
                    <div class="bg-blue-50 border border-blue-200 rounded-lg p-5 mb-6 text-left">
                        <div class="flex items-center justify-between mb-3">
                            <h3 class="font-bold text-blue-900 text-sm"><?php echo $L['login_info_title']; ?></h3>
                            <button type="button" onclick="copyLoginInfo(this)"
                                    class="text-xs bg-white border border-blue-300 text-blue-700 hover:bg-blue-100 px-3 py-1 rounded transition"><?php echo $L['quick_copy_all']; ?></button>
                        </div>
                        <dl class="space-y-2 text-sm">
                            <div class="flex items-start gap-2">
                                <dt class="text-gray-500 w-20 shrink-0"><?php echo $L['quick_login_url']; ?></dt>
                                <dd class="flex-1 font-mono text-blue-800 break-all select-all" id="loginUrlText"><?php echo htmlspecialchars($__loginUrl, ENT_QUOTES, 'UTF-8'); ?></dd>
                            </div>
                            <?php if ($__adminName !== ''): ?>
                            <div class="flex items-start gap-2">
                                <dt class="text-gray-500 w-20 shrink-0"><?php echo $L['quick_account']; ?></dt>
                                <dd class="flex-1 font-mono text-blue-800 select-all" id="loginUserText"><?php echo htmlspecialchars($__adminName, ENT_QUOTES, 'UTF-8'); ?></dd>
                            </div>
                            <?php endif; ?>
                            <div class="flex items-start gap-2">
                                <dt class="text-gray-500 w-20 shrink-0"><?php echo $L['quick_password']; ?></dt>
                                <dd class="flex-1 text-gray-500"><?php echo $L['login_info_pass_hint']; ?></dd>
                            </div>
                        </dl>
                        <p class="text-xs text-blue-700 mt-3"><?php echo $L['login_info_tip']; ?></p>
                    </div>
                    <script>
                    // 安装页常跑在 http / 局域网 IP 下，navigator.clipboard 不可用，故保留 execCommand 降级
                    function copyLoginInfo(btn) {
                        var u = document.getElementById('loginUrlText');
                        var n = document.getElementById('loginUserText');
                        var text = <?php echo json_encode($L['quick_login_url'], JSON_UNESCAPED_UNICODE); ?> + ': ' + (u ? u.textContent : '');
                        if (n) text += '\n' + <?php echo json_encode($L['quick_account'], JSON_UNESCAPED_UNICODE); ?> + ': ' + n.textContent;
                        var old = btn.textContent;
                        var done = function () {
                            btn.textContent = <?php echo json_encode($L['quick_copied'], JSON_UNESCAPED_UNICODE); ?>;
                            setTimeout(function () { btn.textContent = old; }, 2000);
                        };
                        if (navigator.clipboard && window.isSecureContext) {
                            navigator.clipboard.writeText(text).then(done, function () { fallback(); });
                        } else { fallback(); }
                        function fallback() {
                            var ta = document.createElement('textarea');
                            ta.value = text;
                            ta.style.cssText = 'position:fixed;left:-9999px';
                            document.body.appendChild(ta);
                            ta.select();
                            var ok = false;
                            try { ok = document.execCommand('copy'); } catch (e) {}
                            document.body.removeChild(ta);
                            if (ok) { done(); }
                            else {
                                btn.textContent = <?php echo json_encode($L['quick_copy_fail'], JSON_UNESCAPED_UNICODE); ?>;
                                setTimeout(function () { btn.textContent = old; }, 3000);
                            }
                        }
                    }
                    </script>

                    <!-- 主 CTA：直达后台 -->
                    <div class="mb-8">
                        <a href="/admin/" class="inline-flex items-center gap-2 bg-primary hover:bg-secondary text-white text-lg font-bold px-8 py-3 rounded-lg shadow transition">
                            <?php echo $L['goto_admin']; ?>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l7 7m0 0l-7 7m7-7H3"></path></svg>
                        </a>
                        <p class="text-sm text-gray-500 mt-3"><?php echo $L['install_cta_hint']; ?></p>
                    </div>

                    <div class="bg-yellow-50 text-yellow-700 p-4 rounded mb-6">
                        <?php echo $L['security_tip']; ?>
                    </div>

                    <!-- Rewrite 配置ガイド -->
                    <div class="bg-white border rounded-lg text-left mb-6">
                        <div class="px-5 py-3 border-b bg-gray-50 rounded-t-lg">
                            <h3 class="font-bold text-gray-800 text-sm"><?php echo $L['rewrite_title'] ?? 'URL 伪静态配置'; ?></h3>
                            <p class="text-xs text-gray-500 mt-1"><?php echo $L['rewrite_desc'] ?? '启用友好URL（如 /company.html）需要配置Web服务器的伪静态规则'; ?></p>
                        </div>
                        <div class="p-5">
                            <div class="flex gap-2 mb-3">
                                <button onclick="switchRewriteTab('apache')" id="tab-apache" style="background:#3B82F6;color:#fff" class="px-4 py-1.5 rounded text-sm font-medium">Apache</button>
                                <button onclick="switchRewriteTab('nginx')" id="tab-nginx" style="background:#f3f4f6;color:#4b5563" class="px-4 py-1.5 rounded text-sm font-medium">Nginx</button>
                            </div>
                            <div id="panel-apache">
                                <div style="background:#f0fdf4;color:#15803d;padding:12px;border-radius:6px;font-size:14px">
                                    <strong>&#10003; <?php echo $L['rewrite_apache_ok'] ?? '无需配置'; ?></strong><br>
                                    <?php echo $L['rewrite_apache_desc'] ?? '已内置 .htaccess 文件。确保 Apache 启用了 mod_rewrite 模块即可自动生效。'; ?>
                                </div>
                                <div style="margin-top:8px;font-size:12px;color:#6b7280">
                                    <?php echo $L['rewrite_apache_check'] ?? '请确认 Apache 的 httpd.conf 中已设置 AllowOverride All。'; ?>
                                </div>
                            </div>
                            <div id="panel-nginx" style="display:none">
                                <div style="background:#f0fdf4;color:#15803d;padding:12px;border-radius:6px;font-size:14px;margin-bottom:8px">
                                    <strong>&#10003; <?php echo $L['rewrite_nginx_wp_title'] ?? '与 WordPress 规则通用'; ?></strong><br>
                                    <?php echo $L['rewrite_nginx_wp'] ?? '宝塔等面板：伪静态直接选「wordpress」预设即可，无需手写任何规则。'; ?>
                                </div>
                                <div style="margin:8px 0 4px;font-size:13px;color:#4b5563"><?php echo $L['rewrite_nginx_desc'] ?? '没有预设时，手动在 server 块（或面板伪静态框）加入这一行：'; ?></div>
                                <div style="position:relative">
                                    <pre id="nginxCode" style="background:#0f172a;color:#86efac;padding:16px;border-radius:8px;font-size:12px;overflow-x:auto;line-height:1.6"><code>location / { try_files $uri $uri/ /index.php?$query_string; }</code></pre>
                                    <button onclick="copyNginxCode(this)" style="position:absolute;top:8px;right:8px;background:#334155;color:#fff;border:none;padding:4px 12px;border-radius:4px;font-size:12px;cursor:pointer">复制</button>
                                </div>
                                <div style="margin-top:8px;font-size:12px;color:#6b7280">
                                    <?php echo $L['rewrite_nginx_reload'] ?? '配置后请执行 nginx -t 检查语法，然后 nginx -s reload 生效。'; ?><br>
                                    <?php echo $L['rewrite_nginx_advanced'] ?? '进阶（静态 HTML 直出 + 服务器层安全拦截）见程序仓库 deploy/nginx-server.conf。'; ?>
                                </div>
                            </div>
                            <script>
                            function switchRewriteTab(tab) {
                                document.getElementById('panel-apache').style.display = tab === 'apache' ? '' : 'none';
                                document.getElementById('panel-nginx').style.display = tab === 'nginx' ? '' : 'none';
                                document.getElementById('tab-apache').style.background = tab === 'apache' ? '#3B82F6' : '#f3f4f6';
                                document.getElementById('tab-apache').style.color = tab === 'apache' ? '#fff' : '#4b5563';
                                document.getElementById('tab-nginx').style.background = tab === 'nginx' ? '#3B82F6' : '#f3f4f6';
                                document.getElementById('tab-nginx').style.color = tab === 'nginx' ? '#fff' : '#4b5563';
                            }
                            function copyNginxCode(btn) {
                                var text = document.getElementById('nginxCode').querySelector('code').textContent;
                                // 在代码块下方显示一个 textarea，选中内容
                                var existing = document.getElementById('copyTextarea');
                                if (existing) { existing.parentNode.removeChild(existing); }
                                var ta = document.createElement('textarea');
                                ta.id = 'copyTextarea';
                                ta.value = text;
                                ta.style.cssText = 'width:100%;height:120px;margin-top:8px;padding:8px;font-size:12px;font-family:monospace;border:2px solid #3B82F6;border-radius:6px;background:#f8fafc;color:#1e293b';
                                document.getElementById('nginxCode').parentNode.appendChild(ta);
                                ta.focus();
                                ta.select();
                                ta.setSelectionRange(0, ta.value.length);
                                var done = false;
                                try { done = document.execCommand('copy'); } catch(e) {}
                                if (done) {
                                    btn.textContent = '✓ 已复制';
                                    btn.style.background = '#16a34a';
                                    setTimeout(function() { ta.parentNode.removeChild(ta); btn.textContent = '复制'; btn.style.background = '#334155'; }, 2000);
                                } else {
                                    btn.textContent = '请按 Ctrl+C';
                                    btn.style.background = '#d97706';
                                    setTimeout(function() { btn.textContent = '复制'; btn.style.background = '#334155'; }, 5000);
                                }
                            }
                            </script>
                        </div>
                    </div>

                    <div class="flex justify-center gap-4">
                        <a href="/admin/" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                            <?php echo $L['goto_admin']; ?>
                        </a>
                        <a href="/" class="border border-gray-300 hover:bg-gray-100 px-6 py-2 rounded transition">
                            <?php echo $L['goto_home']; ?>
                        </a>
                    </div>
                </div>

            <?php endif; ?>
        </div>

        <!-- 底部 -->
        <div class="text-center text-gray-400 text-sm mt-8">
            &copy; <?php echo date('Y'); ?> <a href="https://www.yikaicms.com" target="_blank" class="hover:text-gray-600 transition">Yikai CMS</a>
        </div>
    </div>
</body>
</html>
