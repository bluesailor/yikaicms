<?php
/**
 * 导航图标自动匹配（v1.18.6）：按栏目名称语义（中/英/日关键词）与栏目类型
 * 推断一个合理的默认图标，让「自动匹配图标」开启的导航零配置就有图标。
 *
 * 优先级（由 NavMegaElement::nodeIconHtml 编排）：
 *   菜单项手填 _icon → 栏目 icon 列 → 本匹配器（仅元素开了 auto_icons）。
 * 名称词典先于类型映射：type=page 的「关于我们」「联系我们」靠名称区分。
 * 图标名全部核对存在于随包 Tabler 集。纯静态零依赖，可独立加载。
 * 放 includes/ 顶层而非 builder/：多语言关键词是跨语言语义数据（与
 * content_model_presets 同性质），不是 Blox UI 文案，不入 Blox i18n 门禁范围。
 */

declare(strict_types=1);

final class BloxNavIconMatcher
{
    /** 名称关键词 → 图标（先命中先用；关键词为包含匹配，大小写不敏感） */
    private const NAME_RULES = [
        ['home', ['首页', '首頁', 'home', 'ホーム', 'トップ'],],
        ['info-circle', ['关于', '關於', '简介', '簡介', 'about', '会社概要', '公司'],],
        ['phone', ['联系', '聯絡', '聯繫', 'contact', 'お問い合わせ', '問い合わせ'],],
        ['news', ['新闻', '新聞', '资讯', '資訊', '动态', 'news', 'ニュース', 'blog', '博客'],],
        ['box', ['产品', '產品', 'product', '製品', '商品'],],
        ['photo', ['案例', 'case', '实绩', '実績', '事例', '相册', '相冊', 'gallery'],],
        ['tool', ['服务', '服務', 'service', 'サービス', '支持', 'support'],],
        ['download', ['下载', '下載', 'download', 'ダウンロード', '资料', '資料'],],
        ['briefcase', ['招聘', '招募', 'career', 'join', 'recruit', '采用', '採用', '人才'],],
        ['users', ['团队', '團隊', 'team', 'チーム'],],
        ['award', ['荣誉', '榮譽', '资质', '資質', 'honor', 'certificate', '認証'],],
        ['history', ['历程', '歷程', 'history', '沿革'],],
        ['video', ['视频', '視頻', 'video', '動画'],],
        ['message-circle', ['留言', '反馈', '反饋', 'message', 'feedback'],],
        ['shopping-cart', ['商城', '商店', 'shop', 'store'],],
        ['help', ['faq', '常见问题', '常見問題', '帮助', '幫助', 'help'],],
    ];

    /** 栏目类型兜底映射 */
    private const TYPE_MAP = [
        'product' => 'box',
        'case' => 'photo',
        'download' => 'download',
        'job' => 'briefcase',
        'page' => 'file-text',
        'link' => 'link',
        'list' => 'news',
    ];

    /** @param array<string,mixed> $node 导航节点（含 name，栏目节点另含 type） */
    public static function match(array $node): string
    {
        $name = mb_strtolower(trim((string) ($node['name'] ?? '')));
        if ($name !== '') {
            if (!empty($node['_is_home'])) {
                return 'home';
            }
            foreach (self::NAME_RULES as [$icon, $keywords]) {
                foreach ($keywords as $keyword) {
                    if (str_contains($name, $keyword)) {
                        return $icon;
                    }
                }
            }
        }
        return self::TYPE_MAP[(string) ($node['type'] ?? '')] ?? '';
    }
}
