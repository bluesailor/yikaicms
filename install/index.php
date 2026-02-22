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

// 检查是否已安装（允许step=4显示完成页面）
$step = (int)($_GET['step'] ?? 1);
if (file_exists(ROOT_PATH . '/installed.lock') && $step !== 4) {
    // AJAX 请求返回 JSON 提示，而非重定向（防止空响应导致前端报错）
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['success' => false, 'message' => '系统已安装。如需重新安装，请先删除根目录下的 installed.lock 文件。']);
        exit;
    }
    header('Location: /');
    exit;
}

// 加载中文语言包
$lang = 'zh';
$L = require INSTALL_PATH . "/lang/zh.php";

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
    $requiredExts = ['pdo', 'json', 'mbstring'];
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
    $optionalExts = ['openssl', 'fileinfo', 'gd'];
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

                // 验证数据库名（仅允许字母数字下划线）
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
                    throw new Exception('数据库名只允许字母、数字和下划线');
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
                if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbName)) {
                    throw new Exception('数据库名只允许字母、数字和下划线');
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

            // 分割并执行 SQL 语句
            if ($driver === 'sqlite') {
                $pdo->exec($sql);
            } else {
                $pdo->exec($sql);
            }

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

            // 生成配置文件
            $configFile = ROOT_PATH . '/config/config.sample.php';
            if (!file_exists($configFile)) {
                throw new Exception('配置模板文件不存在: config/config.sample.php');
            }
            $configTemplate = file_get_contents($configFile);
            $configContent = str_replace(
                ['{{DB_DRIVER}}', '{{DB_HOST}}', '{{DB_PORT}}', '{{DB_NAME}}', '{{DB_USER}}', '{{DB_PASS}}', '{{SITE_NAME}}', '{{SITE_URL}}', "define('DB_PREFIX', 'yikai_')"],
                [$driver, $host, $port, $dbName, $user, $pass, $siteName, $siteUrl, "define('DB_PREFIX', '{$prefix}')"],
                $configTemplate
            );

            if (!file_put_contents(ROOT_PATH . '/config/config.php', $configContent)) {
                throw new Exception($L['error_config_write']);
            }

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
        <!-- 头部 -->
        <div class="text-center mb-8">
            <h1 class="text-3xl font-bold text-gray-800 mb-2"><?php echo $L['title']; ?></h1>
            <p class="text-gray-400 text-sm">v1.0.0</p>
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
                        <a href="?step=2&lang=<?php echo $lang; ?>" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
                            <?php echo $L['next']; ?>
                        </a>
                    <?php else: ?>
                        <a href="?step=1&lang=<?php echo $lang; ?>" class="bg-gray-400 text-white px-6 py-2 rounded">
                            <?php echo $L['retry']; ?>
                        </a>
                    <?php endif; ?>
                </div>

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

                    <!-- 测试按钮 -->
                    <div class="mt-6">
                        <button type="button" id="testDbBtn" class="bg-gray-500 hover:bg-gray-600 text-white px-4 py-2 rounded transition">
                            <?php echo $L['db_test']; ?>
                        </button>
                        <span id="testResult" class="ml-4"></span>
                    </div>
                </form>

                <div class="flex justify-between mt-8">
                    <a href="?step=1&lang=<?php echo $lang; ?>" class="border border-gray-300 hover:bg-gray-100 px-6 py-2 rounded transition">
                        <?php echo $L['prev']; ?>
                    </a>
                    <a href="?step=3&lang=<?php echo $lang; ?>" id="nextStep2" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
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
                        <input type="text" name="site_url" value="<?php echo 'http' . (isset($_SERVER['HTTPS']) ? 's' : '') . '://' . $_SERVER['HTTP_HOST']; ?>" class="w-full border rounded px-3 py-2" required>
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
                                <button type="button" onclick="togglePassword('admin_pass')" class="absolute right-2.5 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600">
                                    <svg class="eye-open w-5 h-5 hidden" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"></path><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2.458 12C3.732 7.943 7.523 5 12 5c4.478 0 8.268 2.943 9.542 7-1.274 4.057-5.064 7-9.542 7-4.477 0-8.268-2.943-9.542-7z"></path></svg>
                                    <svg class="eye-closed w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M13.875 18.825A10.05 10.05 0 0112 19c-4.478 0-8.268-2.943-9.543-7a9.97 9.97 0 011.563-3.029m5.858.908a3 3 0 114.243 4.243M9.878 9.878l4.242 4.242M9.878 9.878L6.59 6.59m7.532 7.532l3.29 3.29M3 3l18 18"></path></svg>
                                </button>
                            </div>
                            <button type="button" onclick="generatePassword()" class="px-3 py-2 border rounded text-sm text-gray-600 hover:bg-gray-50 whitespace-nowrap" title="随机生成密码">随机生成</button>
                        </div>
                        <p class="text-sm text-gray-500 mt-1"><?php echo $L['admin_pass_tip']; ?></p>
                    </div>
                    <div>
                        <label class="block text-gray-700 mb-1"><?php echo $L['admin_email']; ?></label>
                        <input type="email" name="admin_email" class="w-full border rounded px-3 py-2">
                    </div>
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
                    <a href="?step=2&lang=<?php echo $lang; ?>" class="border border-gray-300 hover:bg-gray-100 px-6 py-2 rounded transition">
                        <?php echo $L['prev']; ?>
                    </a>
                    <button type="button" id="installBtn" class="bg-primary hover:bg-secondary text-white px-6 py-2 rounded transition">
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

                // 恢复之前保存的数据库配置
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
                            result.innerHTML = '<div class="bg-green-50 text-green-700 p-4 rounded"></div>';
                            result.querySelector('div').textContent = data.message;
                            setTimeout(() => { window.location.href = '?step=4&lang=<?php echo $lang; ?>'; }, 1500);
                        } else {
                            result.innerHTML = '<div class="bg-red-50 text-red-700 p-4 rounded"></div>';
                            result.querySelector('div').textContent = data.message;
                            document.getElementById('stepButtons').classList.remove('hidden');
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

                    <div class="bg-yellow-50 text-yellow-700 p-4 rounded mb-6">
                        <?php echo $L['security_tip']; ?>
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
