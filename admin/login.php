<?php
/**
 * YikaiCMS - 后台登录
 *
 * PHP 8.0+
 */

declare(strict_types=1);

define('ROOT_PATH', dirname(__DIR__));
require_once ROOT_PATH . '/config/config.php';
require_once ROOT_PATH . '/includes/functions.php';
require_once ROOT_PATH . '/admin/includes/auth.php';

// 已登录则跳转
if (!empty($_SESSION['admin_id'])) {
    redirect('/admin/');
}

// ============================================================
// 语言切换：以后台「admin_languages」设置为准，可在 /admin/setting.php?tab=lang 配置
// ============================================================
$_allLangs = availableLanguages();  // ['zh-CN' => '中文', 'en' => 'English', 'ja' => '日本語', ...]
// enabled_languages 是站长在「语言设置」里的明确选择，优先于 admin_languages
// ——后者是安装时写死的 'zh-CN,en,ja'，从不跟随站点实际启用语言，纯英文站的
// 登录页因此一直显示「中文 / 日本語」，点进去整个后台变中文（客户实测报出）。
$_enabledRaw = trim((string) config('enabled_languages', ''));
$_enabledList = $_enabledRaw !== '' ? json_decode($_enabledRaw, true) : null;
$_adminLangsRaw = trim((string) config('admin_languages', ''));

if (is_array($_enabledList) && $_enabledList !== []) {
    $_adminAllowed = array_values(array_filter(array_map('strval', $_enabledList)));
} elseif ($_adminLangsRaw !== '') {
    $_adminAllowed = array_values(array_filter(array_map('trim', explode(',', $_adminLangsRaw))));
} else {
    // 两者都没配：退到后台语言/站点语言，再不行才列全部语言包
    $_fallbackLang = (string) (config('admin_lang', '') ?: config('site_lang', ''));
    $_adminAllowed = $_fallbackLang !== '' ? [$_fallbackLang] : array_keys($_allLangs);
}
// 后台语言本身必须在列表里：admin_lang 设了 ja 而 enabled_languages 只有 en 时，
// 不能把管理员正在用的语言从切换器里摘掉。
$_curAdminLang = (string) config('admin_lang', '');
if ($_curAdminLang !== '' && !in_array($_curAdminLang, $_adminAllowed, true)) {
    $_adminAllowed[] = $_curAdminLang;
}
$supportedLangs = [];
foreach ($_adminAllowed as $_lc) {
    if (isset($_allLangs[$_lc])) $supportedLangs[$_lc] = $_allLangs[$_lc];
}
if (empty($supportedLangs)) $supportedLangs = $_allLangs;

// 1. URL ?lang=xxx 切换 (重定向去掉参数,避免后续刷新冲突)
if (isset($_GET['lang']) && isset($supportedLangs[$_GET['lang']])) {
    $_SESSION['login_lang'] = $_GET['lang'];
    redirect('/admin/login.php');
}

// 2. 没设置过 → 从浏览器 Accept-Language 第一项检测
if (empty($_SESSION['login_lang'])) {
    $accept = strtolower(trim((string)($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? '')));
    $first = trim(explode(';', explode(',', $accept)[0] ?? '')[0]);
    if (str_starts_with($first, 'zh') && isset($supportedLangs['zh-CN'])) {
        $_SESSION['login_lang'] = 'zh-CN';
    } elseif (str_starts_with($first, 'ja') && isset($supportedLangs['ja'])) {
        $_SESSION['login_lang'] = 'ja';
    } elseif (str_starts_with($first, 'en') && isset($supportedLangs['en'])) {
        $_SESSION['login_lang'] = 'en';
    }
    // 不匹配则不设, getLang() 走原有 admin_lang / 默认逻辑
}

$error = '';

// 两步验证待验证态（密码已过，等验证器 6 位码）
$awaiting2fa = !empty($_SESSION['2fa_pending']) && ($_SESSION['2fa_pending']['expires'] ?? 0) >= time();
if (!empty($_SESSION['2fa_pending']) && !$awaiting2fa) {
    unset($_SESSION['2fa_pending']); // 过期清理
}

// 处理登录
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    verifyCsrf();

    if (post('action') === 'totp') {
        // 第二步：验证器验证码
        $result = doTotpLogin(post('totp_code'));
        if ($result['success']) {
            unset($_SESSION['login_lang']);
            redirect('/admin/');
        }
        $error = $result['message'];
        if (!empty($result['expired'])) {
            $awaiting2fa = false; // 会话过期/锁定 → 回到账号密码步
        }
    } else {
        $username = post('username');
        $password = post('password');

        if (empty($username) || empty($password)) {
            $error = __('login_empty_fields');
        } else {
            $result = doLogin($username, $password);
            if ($result['success']) {
                unset($_SESSION['login_lang']); // 登录成功 -> 交回 admin_lang 设置
                redirect('/admin/');
            } elseif (!empty($result['need_2fa'])) {
                $awaiting2fa = true; // 进入第二步
            } else {
                $error = $result['message'];
            }
        }
    }
}
?>
<?php $currentLang = function_exists('getLang') ? getLang() : 'ja'; ?>
<!DOCTYPE html>
<html lang="<?php echo e($currentLang); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo __('login_page_title'); ?> - <?php echo e(config('site_name', 'YikaiCMS')); ?></title>
    <link rel="stylesheet" href="/assets/css/tailwind.css">
    <link rel="stylesheet" href="/assets/css/admin.css">
</head>
<body class="bg-gray-100 min-h-screen flex items-center justify-center relative">

    <!-- 语言切换（右上角；以后台 admin_languages 设置为准） -->
    <?php
    $flagFile = ['zh-CN' => 'cn', 'en' => 'us', 'ja' => 'jp'];
    if (count($supportedLangs) >= 2):
    ?>
    <div class="absolute top-4 right-4 flex items-center gap-1.5 z-10">
        <?php foreach ($supportedLangs as $code => $label):
            $active = $currentLang === $code;
            $flag = $flagFile[$code] ?? '';
        ?>
            <a href="?lang=<?php echo e($code); ?>"
               hreflang="<?php echo e($code); ?>"
               title="<?php echo e($label); ?>"
               class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-md border text-xs transition <?php echo $active
                   ? 'border-primary text-primary bg-blue-50 font-medium'
                   : 'border-gray-200 text-gray-500 bg-white hover:border-gray-300 hover:bg-gray-50'; ?>">
                <?php if ($flag): ?>
                <img src="/assets/icons/flags/<?php echo e($flag); ?>.svg"
                     alt="" aria-hidden="true"
                     class="w-4 h-3 rounded-sm shrink-0 ring-1 ring-gray-200">
                <?php endif; ?>
                <span><?php echo e($label); ?></span>
            </a>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>

    <div class="w-full max-w-md">
        <div class="bg-white rounded-lg shadow-lg p-8">
            <div class="text-center mb-8">
                <h1 class="text-2xl font-bold text-gray-800"><?php echo e(config('site_name', 'YikaiCMS')); ?></h1>
                <p class="text-gray-500 mt-2"><?php echo __('login_subtitle'); ?></p>
            </div>

            <?php if ($error): ?>
            <div class="bg-red-50 text-red-600 p-4 rounded-lg mb-6">
                <?php echo e($error); ?>
            </div>
            <?php endif; ?>

            <?php if ($awaiting2fa): ?>
            <!-- 第二步：两步验证码 -->
            <form method="post" class="space-y-6">
                <?php echo csrfField(); ?>
                <input type="hidden" name="action" value="totp">
                <div>
                    <label class="block text-gray-700 mb-2"><?php echo __('login_2fa_code'); ?></label>
                    <input type="text" name="totp_code" required autofocus
                           inputmode="numeric" autocomplete="one-time-code" maxlength="7"
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 text-center text-2xl tracking-[0.5em] focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="000000">
                    <p class="text-gray-400 text-sm mt-2"><?php echo __('login_2fa_hint'); ?></p>
                </div>
                <button type="submit"
                        class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-lg transition cursor-pointer">
                    <?php echo __('login_2fa_button'); ?>
                </button>
            </form>
            <?php else: ?>
            <form method="post" class="space-y-6">
                <?php echo csrfField(); ?>
                <div>
                    <label class="block text-gray-700 mb-2"><?php echo __('login_username'); ?></label>
                    <input type="text" name="username" required autofocus
                           class="w-full border border-gray-300 rounded-lg px-4 py-3 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                           placeholder="<?php echo __('login_username_placeholder'); ?>">
                </div>

                <div>
                    <label class="block text-gray-700 mb-2"><?php echo __('login_password'); ?></label>
                    <div class="relative pwd-toggle">
                        <input type="password" name="password" required
                               class="w-full border border-gray-300 rounded-lg px-4 py-3 pr-11 focus:outline-none focus:ring-2 focus:ring-primary focus:border-transparent"
                               placeholder="<?php echo __('login_password_placeholder'); ?>">
                        <button type="button" onclick="togglePassword(this)" class="absolute right-3 top-1/2 -translate-y-1/2 text-gray-400 hover:text-gray-600 cursor-pointer">
                            <i class="ti ti-eye text-lg eye-open hidden"></i>
                            <i class="ti ti-eye-off text-lg eye-closed"></i>
                        </button>
                    </div>
                </div>

                <button type="submit"
                        class="w-full bg-primary hover:bg-secondary text-white font-bold py-3 rounded-lg transition cursor-pointer">
                    <?php echo __('login_button'); ?>
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="text-center mt-6 text-gray-500 text-sm">
            &copy; <?php echo date('Y'); ?> <?php echo e(config('site_name', 'YikaiCMS')); ?>
        </div>
    </div>
<script>
function togglePassword(el) {
    var wrap = el.closest('.pwd-toggle');
    var input = wrap.querySelector('input');
    var isHidden = input.type === 'password';
    input.type = isHidden ? 'text' : 'password';
    wrap.querySelector('.eye-open').classList.toggle('hidden', !isHidden);
    wrap.querySelector('.eye-closed').classList.toggle('hidden', isHidden);
}
</script>
</body>
</html>
