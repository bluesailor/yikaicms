<?php
/**
 * 网站统计接入插件
 *
 * 按服务商在前台 <head> 注入统计代码：
 *   baidu  - 百度统计（hm.js）
 *   51la   - 51.la（js-sdk-pro）
 *   cnzz   - CNZZ / 友盟+
 *   ga4    - Google Analytics 4（gtag）
 *   custom - 自定义代码（原样输出）
 *
 * 设置项（group=plugin）：stats_provider、stats_id、stats_custom_code。
 * 钩子：ik_head（注入统计脚本）。
 *
 * Pro（数据看板）在 admin.php 中凭 license_has_module('stats') 解锁。
 */

if (!defined('ROOT_PATH')) {
    exit('Access Denied');
}

add_action('ik_head', function () {
    $provider = (string) config('stats_provider', '');
    if ($provider === '') {
        return;
    }

    if ($provider === 'custom') {
        // 自定义代码原样输出（与系统「Body代码」同等信任级别，由站长自负）
        $code = (string) config('stats_custom_code', '');
        if (trim($code) !== '') {
            echo "\n<!-- stats: custom -->\n" . $code . "\n";
        }
        return;
    }

    // 预设服务商：ID 仅允许字母数字（含 - _），防止注入
    $id = preg_replace('/[^A-Za-z0-9\-_]/', '', (string) config('stats_id', ''));
    if ($id === '') {
        return;
    }

    $out = '';
    switch ($provider) {
        case 'baidu':
            $out = '<script>var _hmt=_hmt||[];(function(){var hm=document.createElement("script");'
                 . 'hm.src="https://hm.baidu.com/hm.js?' . $id . '";'
                 . 'var s=document.getElementsByTagName("script")[0];s.parentNode.insertBefore(hm,s);})();</script>';
            break;

        case '51la':
            $out = '<script charset="UTF-8" id="LA_COLLECT" src="//sdk.51.la/js-sdk-pro.min.js?id='
                 . $id . '&ck=' . $id . '"></script>';
            break;

        case 'cnzz':
            $out = '<script async src="https://s9.cnzz.com/z_stat.php?id=' . $id . '&web_id=' . $id . '"></script>';
            break;

        case 'ga4':
            $out = '<script async src="https://www.googletagmanager.com/gtag/js?id=' . $id . '"></script>'
                 . '<script>window.dataLayer=window.dataLayer||[];function gtag(){dataLayer.push(arguments);}'
                 . "gtag('js',new Date());gtag('config','" . $id . "');</script>";
            break;
    }

    if ($out !== '') {
        echo "\n<!-- stats: " . $provider . " -->\n" . $out . "\n";
    }
});
