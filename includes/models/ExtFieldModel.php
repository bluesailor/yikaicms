<?php
/**
 * Yikai CMS - 扩展字段定义 Model
 *
 * 参考 PbootCMS ay_extfield：字段定义与字段值解耦。
 * 字段值通过 MetaModel 写入 yikai_metas。
 */

declare(strict_types=1);

class ExtFieldModel extends Model
{
    protected string $table = 'extfields';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    /** 字段类型键 => lang 键。标签本地化走 typeLabels()——const 不能调 __()。 */
    public const TYPES = [
        'text'         => 'ext_type_text',
        'textarea'     => 'ext_type_textarea',
        'richtext'     => 'ext_type_richtext',
        'image'        => 'ext_type_image',
        'images'       => 'ext_type_images',
        'select'       => 'ext_type_select',
        'multi_select' => 'ext_type_multi_select',
        'date'         => 'ext_type_date',
        'number'       => 'ext_type_number',
        'switch'       => 'ext_type_switch',
    ];

    /** 字段类型键 => 当前语言的显示标签。 */
    public static function typeLabels(): array
    {
        $out = [];
        foreach (self::TYPES as $key => $langKey) {
            $out[$key] = __($langKey);
        }
        return $out;
    }

    /**
     * 获取指定 owner_type 的启用字段（按 sort_order 排序）
     */
    public function getByOwner(string $ownerType, bool $onlyEnabled = true): array
    {
        $sql = "SELECT * FROM {$this->tableName()} WHERE owner_type = ?";
        $params = [$ownerType];
        if ($onlyEnabled) {
            $sql .= " AND status = 1";
        }
        $sql .= " ORDER BY sort_order ASC, id ASC";
        return db()->fetchAll($sql, $params);
    }

    public function isFieldKeyUnique(string $ownerType, string $fieldKey, int $excludeId = 0): bool
    {
        $sql = "SELECT id FROM {$this->tableName()} WHERE owner_type = ? AND field_key = ?";
        $params = [$ownerType, $fieldKey];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        return !db()->fetchOne($sql, $params);
    }
}
