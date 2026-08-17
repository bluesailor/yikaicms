<?php

declare(strict_types=1);

/**
 * 随程序发布、可在媒体选择器中直接使用的只读图片。
 *
 * 这类资源不能放进 uploads/ 或 media 表：升级包会排除 uploads/，数据库记录也不会
 * 随代码升级自动出现。这里仅向选择器提供稳定 URL，不接入上传媒体的删除流程。
 */
final class BundledMediaLibrary
{
    /** @var array<int, array{id: string, file: string, label: string, keywords: string}> */
    private const ITEMS = [
        [
            'id' => 'builtin-cta-technology-services',
            'file' => 'cta-technology-services.png',
            'label' => 'blox_media_builtin_cta_technology_services',
            'keywords' => 'cta technology services tech 科技 技术 服务 テクノロジー 技術 サービス',
        ],
        [
            'id' => 'builtin-cta-smart-manufacturing',
            'file' => 'cta-smart-manufacturing.png',
            'label' => 'blox_media_builtin_cta_smart_manufacturing',
            'keywords' => 'cta smart manufacturing factory 智能 制造 工厂 スマート 製造 工場',
        ],
        [
            'id' => 'builtin-cta-corporate-campus',
            'file' => 'cta-corporate-campus.png',
            'label' => 'blox_media_builtin_cta_corporate_campus',
            'keywords' => 'cta corporate campus company office 企业 园区 公司 オフィス 企業 キャンパス',
        ],
        [
            'id' => 'builtin-cta-business-collaboration',
            'file' => 'cta-business-collaboration.png',
            'label' => 'blox_media_builtin_cta_business_collaboration',
            'keywords' => 'cta business collaboration partnership 商务 商业 合作 ビジネス 連携 協業',
        ],
    ];

    /**
     * @return array<int, array<string, int|string|bool>>
     */
    public static function search(string $type = 'image', string $keyword = ''): array
    {
        if ($type !== '' && $type !== 'image') {
            return [];
        }

        $needle = trim($keyword);
        $items = [];
        foreach (self::ITEMS as $definition) {
            $url = '/themes/default/assets/images/cta/' . $definition['file'];
            $path = ROOT_PATH . str_replace('/', DIRECTORY_SEPARATOR, $url);
            if (!is_file($path)) {
                continue;
            }

            $name = __($definition['label']);
            $haystack = $name . ' ' . $definition['id'] . ' ' . $definition['keywords'];
            if ($needle !== '' && stripos($haystack, $needle) === false) {
                continue;
            }

            $image = @getimagesize($path);
            $items[] = [
                'id' => $definition['id'],
                'name' => $name,
                'url' => $url,
                'type' => 'image',
                'ext' => 'png',
                'mime' => 'image/png',
                'size' => (int) (filesize($path) ?: 0),
                'width' => is_array($image) ? (int) $image[0] : 0,
                'height' => is_array($image) ? (int) $image[1] : 0,
                'admin_id' => 0,
                'created_at' => (int) (filemtime($path) ?: 0),
                'builtin' => true,
            ];
        }

        return $items;
    }
}
