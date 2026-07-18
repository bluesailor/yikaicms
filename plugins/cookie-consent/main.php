<?php
/**
 * Cookie 同意横幅插件 v1.1
 *
 * 在前台底部注入横幅 + 控制脚本。
 * 用户选择保存到 cookie `ik_consent`（JSON: {necessary:true, analytics:bool, marketing:bool, v:int, ts:int}）。
 *
 * 其他脚本判断：
 *   if (window.IK_consent && window.IK_consent.analytics) { 加载 GA; }
 * 或监听事件：window.addEventListener('ik:consent', e => ...e.detail...)
 *
 * v1.1（GDPR 补齐）：
 *   - 「Cookie 设置」常驻入口：随时重开横幅、撤回/变更同意（GDPR Art.7(3)）
 *   - 隐私政策链接（后台配置）
 *   - Google Consent Mode v2：gtag consent default/update，GA/Ads 在欧盟合规必备
 *   - 政策版本号：后台递增后所有访客重新弹出
 *
 * 钩子：
 *   - ik_head: 注入 window.IK_consent + Consent Mode v2 default（尽量早于 gtag.js）
 *   - ik_footer_scripts: 注入横幅 DOM + JS + 「Cookie 设置」重开入口
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

/** 读取已存同意（内部用） */
function ik_cc_stored(): ?array
{
    $raw = $_COOKIE['ik_consent'] ?? '';
    if (!$raw) return null;
    $d = json_decode($raw, true);
    return is_array($d) ? $d : null;
}

// 早期暴露同意状态 + Consent Mode v2 default：脚本可在 head 中判断
add_action('ik_head', function () {
    $stored = ik_cc_stored();
    $policyVersion = (int) config('cc_policy_version', '1');
    // 存的版本低于当前政策版本 → 视为未同意（横幅会重新弹）
    $valid = $stored !== null && (int) ($stored['v'] ?? 1) >= $policyVersion;

    $consent = [
        'necessary' => true,
        'analytics' => $valid && !empty($stored['analytics']),
        'marketing' => $valid && !empty($stored['marketing']),
        'decided'   => $valid,
        'ts'        => $valid ? (int) ($stored['ts'] ?? 0) : 0,
    ];
    // JSON_HEX_TAG 防御性转义：避免 </script> 破出脚本。
    echo '<script>window.IK_consent = ' . json_encode($consent, JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ';</script>' . "\n";

    // Google Consent Mode v2（可选）：default 必须在 gtag.js 之前执行
    if ((string) config('cc_consent_mode', '0') === '1') {
        $ga = fn(bool $b) => $b ? 'granted' : 'denied';
        echo "<script>\n"
            . "window.dataLayer = window.dataLayer || [];\n"
            . "if (typeof gtag !== 'function') { function gtag(){ dataLayer.push(arguments); } window.gtag = gtag; }\n"
            . "gtag('consent', 'default', {"
            . "'analytics_storage':'" . $ga($consent['analytics']) . "',"
            . "'ad_storage':'" . $ga($consent['marketing']) . "',"
            . "'ad_user_data':'" . $ga($consent['marketing']) . "',"
            . "'ad_personalization':'" . $ga($consent['marketing']) . "',"
            . "'wait_for_update': 500});\n"
            . "window.addEventListener('ik:consent', function (e) {\n"
            . "  var c = e.detail || {};\n"
            . "  gtag('consent', 'update', {\n"
            . "    'analytics_storage': c.analytics ? 'granted' : 'denied',\n"
            . "    'ad_storage': c.marketing ? 'granted' : 'denied',\n"
            . "    'ad_user_data': c.marketing ? 'granted' : 'denied',\n"
            . "    'ad_personalization': c.marketing ? 'granted' : 'denied'\n"
            . "  });\n"
            . "});\n"
            . "</script>\n";
    }
});

// 底部横幅 + 「Cookie 设置」重开入口
add_action('ik_footer_scripts', function () {
    $stored = ik_cc_stored();
    $policyVersion = (int) config('cc_policy_version', '1');
    $decided = $stored !== null && (int) ($stored['v'] ?? 1) >= $policyVersion;
    $policyUrl = trim((string) config('cc_policy_url', ''));
    $footerLink = (string) config('cc_footer_link', '1') === '1';

    // 已决定且不需要重开入口 → 什么都不输出
    if ($decided && !$footerLink) return;

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
            'policy'     => '隐私政策',
            'settings'   => 'Cookie 设置',
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
            'policy'     => 'プライバシーポリシー',
            'settings'   => 'Cookie 設定',
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
            'policy'     => 'Privacy Policy',
            'settings'   => 'Cookie Settings',
        ],
    ];
    $t = $i18n[$lang] ?? $i18n['zh-CN'];
    $storedAnalytics = $decided && !empty($stored['analytics']);
    $storedMarketing = $decided && !empty($stored['marketing']);
    ?>
    <div id="ik-consent" role="dialog" aria-labelledby="ik-consent-title" style="
        <?= $decided ? 'display:none;' : '' ?>
        position:fixed;left:16px;right:16px;bottom:16px;z-index:10000;
        max-width:680px;margin:0 auto;background:#fff;color:#1a202c;
        border-radius:12px;box-shadow:0 10px 40px rgba(0,0,0,.18);
        padding:18px 20px;font-family:system-ui,-apple-system,'Segoe UI','Hiragino Kaku Gothic ProN','Meiryo',sans-serif;
        font-size:.92rem;line-height:1.55;">
        <div id="ik-consent-title" style="font-weight:700;font-size:1rem;margin-bottom:6px;"><?= htmlspecialchars($t['title']) ?></div>
        <div style="color:#4a5568;margin-bottom:12px;">
            <?= htmlspecialchars($t['desc']) ?>
            <?php if ($policyUrl !== ''): ?>
            <a href="<?= htmlspecialchars($policyUrl, ENT_QUOTES) ?>" target="_blank" rel="noopener" style="color:#2563eb;text-decoration:underline;margin-left:4px;"><?= htmlspecialchars($t['policy']) ?></a>
            <?php endif; ?>
        </div>
        <div id="ik-consent-options" style="display:none;margin-bottom:12px;padding:10px 12px;background:#f7fafc;border-radius:8px;">
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;color:#718096;">
                <input type="checkbox" checked disabled> <?= htmlspecialchars($t['necessary']) ?>
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;">
                <input type="checkbox" id="ik-consent-analytics" <?= $storedAnalytics ? 'checked' : '' ?>> <?= htmlspecialchars($t['analytics']) ?>
            </label>
            <label style="display:flex;align-items:center;gap:8px;padding:4px 0;cursor:pointer;">
                <input type="checkbox" id="ik-consent-marketing" <?= $storedMarketing ? 'checked' : '' ?>> <?= htmlspecialchars($t['marketing']) ?>
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
    <?php if ($footerLink): ?>
    <!-- 常驻重开入口：撤回/变更同意（GDPR Art.7(3)）。未决定时隐藏（横幅本身在显示） -->
    <button type="button" id="ik-consent-reopen" aria-label="<?= htmlspecialchars($t['settings'], ENT_QUOTES) ?>" style="
        position:fixed;left:14px;bottom:14px;z-index:9999;
        display:<?= $decided ? 'inline-flex' : 'none' ?>;align-items:center;gap:5px;
        padding:6px 12px;border:1px solid #e2e8f0;border-radius:999px;
        background:rgba(255,255,255,.92);color:#64748b;font-size:.78rem;cursor:pointer;
        box-shadow:0 2px 10px rgba(0,0,0,.08);">
        <span style="font-size:.95rem;">🍪</span><?= htmlspecialchars($t['settings']) ?>
    </button>
    <?php endif; ?>
    <script>
    (function () {
        var box = document.getElementById('ik-consent');
        var opts = document.getElementById('ik-consent-options');
        var btnCustomize = document.getElementById('ik-consent-customize');
        var btnReject    = document.getElementById('ik-consent-reject');
        var btnAccept    = document.getElementById('ik-consent-accept');
        var btnSave      = document.getElementById('ik-consent-save');
        var btnReopen    = document.getElementById('ik-consent-reopen');
        var cbAnalytics  = document.getElementById('ik-consent-analytics');
        var cbMarketing  = document.getElementById('ik-consent-marketing');

        function setConsent(analytics, marketing) {
            var c = { necessary: true, analytics: !!analytics, marketing: !!marketing,
                      v: <?= (int) $policyVersion ?>, ts: Math.floor(Date.now() / 1000) };
            // 1 年有效期；HTTPS 下加 Secure，避免 cookie 通过明文 HTTP 泄漏
            var secureFlag = (location.protocol === 'https:') ? ';Secure' : '';
            document.cookie = 'ik_consent=' + encodeURIComponent(JSON.stringify(c)) + ';path=/;max-age=' + (365 * 86400) + ';SameSite=Lax' + secureFlag;
            c.decided = true;
            window.IK_consent = c;
            box.style.display = 'none';
            if (btnReopen) btnReopen.style.display = 'inline-flex';
            // 触发自定义事件，其他脚本可监听后挂载/卸载（如 GA、Consent Mode update）
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
        if (btnReopen) {
            btnReopen.addEventListener('click', function () {
                // 直接展开自定义面板，方便调整后保存（撤回=取消勾选后保存）
                btnReopen.style.display = 'none';
                box.style.display = 'block';
                opts.style.display = 'block';
                btnCustomize.style.display = 'none';
                btnSave.style.display = 'inline-block';
            });
        }
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
        if (!is_array($d)) return false;
        // 政策版本升级后旧同意失效
        if ((int) ($d['v'] ?? 1) < (int) config('cc_policy_version', '1')) return false;
        return !empty($d[$category]);
    }
}
