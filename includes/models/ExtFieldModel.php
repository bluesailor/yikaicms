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

    public const TYPES = [
        'text'         => '单行文本',
        'textarea'     => '多行文本',
        'richtext'     => '富文本',
        'image'        => '单图',
        'images'       => '多图',
        'select'       => '下拉选择',
        'multi_select' => '多选',
        'date'         => '日期',
        'number'       => '数字',
        'switch'       => '开关',
    ];

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
