<?php
/**
 * Cookie 同意横幅插件
 *
 * 在前台底部注入横幅 + 控制脚本。
 * 用户选择保存到 cookie `ik_consent`（JSON: {necessary:true, analytics:bool, marketing:bool, ts:int}）。
 *
 * 其他脚本判断：
 *   if (window.IK_consent && window.IK_consent.analytics) { 加载 GA; }
 *
 * 钩子：
 *   - ik_head: 注入 window.IK_consent 全局对象（早期可用）
 *   - ik_footer_scripts: 注入横幅 DOM + JS
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

// 早期暴露同意状态：脚本可在 head 中判断
add_action('ik_head', function () {
    $raw = $_COOKIE['ik_consent'] ?? '';
    $consent = ['necessary' => true, 'analytics' => false, 'marketing' => false, 'ts' => 0];
    if ($raw) {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $consent['analytics'] = !empty($decoded['analytics']);
            $consent['marketing'] = !empty($decoded['marketing']);
            $consent['ts']        = (int)($decoded['ts'] ?? 0);
        }
    }
    // JSON_HEX_TAG 防御性转义：未来若 $consent 含字符串字段，避免 </script> 破出脚本。
    echo '<script>window.IK_consent = ' . json_encode($consent, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>' . "\n";
});

// 底部横幅 + JS
add_action('ik_footer_scripts', function () {
    // 已决定过就不再弹（cookie 存在即视为已决）
    if (!empty($_COOKIE['ik_consent'])) return;

    $lang = defined('SITE_LANG') ? SITE_LANG : 'zh-CN';
    $i18n = [
        'zh-CN' => [
            'title'   => '关于 Cookie 的使用',
            'desc'    => '我们使用 Cookie 来提升体验、分析访问。点击「全部接受」即同意所有类别；也可在「自定义」中按类别选择。',
            'necessary' => '必要（始终启用）',
            'analytics' => '分析',
            'marketing' => '营销',
            'accept_all' => '全部接受',
            'reject_all' => '仅必要',
            'customize'  => '自定义',
            'save'       => '保存选择',
        ],
        'ja' => [
            'title'   => 'Cookie の使用について',
            'desc'    => '体験向上と利用解析のため Cookie を使用します。「すべて許可」で全項目に同意、「カスタマイズ」で個別選択できます。',
            'necessary' => '必須（常に有効）',
            'analytics' => '解析',
            'marketing' => 'マーケティング',
            'accept_all' => 'すべて許可',
            'reject_all' => '必須のみ',
            'customize'  => 'カスタマイズ',
            'save'       => '選択を保存',
        ],
        'en' => [
            'title'   => 'About Cookies',
            'desc'    => 'We use cookies to improve your experience and analyze traffic. Click "Accept all" or customize by category.',
            'necessary' => 'Necessary (always on)',
            'analytics' => 'Analytics',
            'marketing' => 'Marketing',
            'accept_all' => 'Accept all',
            'reject_all' => 'Necessary only',
            'customize'  => 'Customize',
            'save'       => 'Save choices',
        ],
    ];
    $t = $i18n[$lang] ?? $i18n['zh-CN'];
    ?>
    <div id="ik-consent" role="dialog" aria-labelledby="ik-consent-title" style="
        position:fixed;left:16px;right:16px;bottom:16px;z-index:10000;
        max-width:680px;margin:0 auto;background:#fff;color:#1a202c;
        border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.18);
        padding:18px 20px;font-family:system-ui,-apple-system,'Segoe UI','Hiragino Kaku Gothic ProN','Meiryo',sans-serif;
        font-size:.92rem;line-height:1.55;">
        <div id="ik-consent-title" style="font-weight:700;font-size:1rem;margin-bottom:6px;"><?= htmlspecialchars($t['title']) ?></div>
        <div style="color:#4a5568;margin-bottom:12px;"><?= htmlspecialchars($t['desc']) ?></div>
        <div id="ik-consent-options" style="display:none;margin-bottom:12px;padding:10px 12px;background:#f7fafc;border-radius:8px;">
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;color:#718096;">
                <input type="checkbox" checked disabled> <?= htmlspecialchars($t['necessary']) ?>
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;">
                <input type="checkbox" id="ik-consent-analytics"> <?= htmlspecialchars($t['analytics']) ?>
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;">
                <input type="checkbox" id="ik-consent-marketing"> <?= htmlspecialchars($t['marketing']) ?>
            </label>
        </div>
        <div style="display:flex;flex-wrap:wrap;gap:8px;justify-content:flex-end;">
            <button type="button" id="ik-consent-customize" style="padding:8px 14px;border:1px solid #cbd5e0;background:#fff;color:#4a5568;border-radius:6px;font-size:.86rem;cursor:pointer;">
                <?= htmlspecialchars($t['customize']) ?>
            </button>
            <button type="button" id="ik-consent-reject" style="padding:8px 14px;border:1px solid #cbd5e0;background:#fff;color:#4a5568;border-radius:6px;font-size:.86rem;cursor:pointer;">
                <?= htmlspecialchars($t['reject_all']) ?>
            </button>
            <button type="button" id="ik-consent-save" style="display:none;padding:8px 14px;border:none;background:#2563eb;color:#fff;border-radius:6px;font-size:.86rem;cursor:pointer;font-weight:600;">
                <?= htmlspecialchars($t['save']) ?>
            </button>
            <button type="button" id="ik-consent-accept" style="padding:8px 16px;border:none;background:#2563eb;color:#fff;border-radius:6px;font-size:.86rem;cursor:pointer;font-weight:600;">
                <?= htmlspecialchars($t['accept_all']) ?>
            </button>
        </div>
    </div>
    <script>
    (function () {
        var box = document.getElementById('ik-consent');
        var opts = document.getElementById('ik-consent-options');
        var btnCustomize = document.getElementById('ik-consent-customize');
        var btnReject    = document.getElementById('ik-consent-reject');
        var btnAccept    = document.getElementById('ik-consent-accept');
        var btnSave      = document.getElementById('ik-consent-save');
        var cbAnalytics  = document.getElementById('ik-consent-analytics');
        var cbMarketing  = document.getElementById('ik-consent-marketing');

        function setConsent(analytics, marketing) {
            var c = { necessary: true, analytics: !!analytics, marketing: !!marketing, ts: Math.floor(Date.now() / 1000) };
            // 1 年有效期；HTTPS 下加 Secure，避免 cookie 通过明文 HTTP 泄漏
            var secureFlag = (location.protocol === 'https:') ? ';Secure' : '';
            document.cookie = 'ik_consent=' + encodeURIComponent(JSON.stringify(c)) + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax' + secureFlag;
            window.IK_consent = c;
            box.style.display = 'none';
            // 触发自定义事件，其他脚本可监听后挂载（如 GA）
            try { window.dispatchEvent(new CustomEvent('ik:consent', { detail: c })); } catch (e) {}
        }

        btnCustomize.addEventListener('click', function () {
            opts.style.display = 'block';
            btnCustomize.style.display = 'none';
            btnSave.style.display = 'inline-block';
        });
        btnAccept.addEventListener('click', function () { setConsent(true, true); });
        btnReject.addEventListener('click', function () { setConsent(false, false); });
        btnSave.addEventListener('click', function () { setConsent(cbAnalytics.checked, cbMarketing.checked); });
    })();
    </script>
    <?php
});

// 暴露一个 PHP 端的便利函数：判断当前请求是否允许某类别
if (!function_exists('ik_consent_allows')) {
    function ik_consent_allows(string $category): bool
    {
        if ($category === 'necessary') return true;
        $raw = $_COOKIE['ik_consent'] ?? '';
        if (!$raw) return false;
        $d = json_decode($raw, true);
        return is_array($d) && !empty($d[$category]);
    }
}
