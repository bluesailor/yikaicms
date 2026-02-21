<?php
declare(strict_types=1);

class FormTemplateModel extends Model
{
    protected string $table = 'form_templates';
    protected string $defaultOrder = 'id ASC';

    /**
     * 根据slug获取表单模板
     */
    public function findBySlug(string $slug): ?array
    {
        return $this->findWhere(['slug' => $slug, 'status' => 1]);
    }

    /**
     * 检查slug是否唯一
     */
    public function isSlugUnique(string $slug, int $excludeId = 0): bool
    {
        $sql = "SELECT COUNT(*) FROM {$this->tableName()} WHERE slug = ?";
        $params = [$slug];
        if ($excludeId > 0) {
            $sql .= " AND id != ?";
            $params[] = $excludeId;
        }
        return (int)db()->fetchColumn($sql, $params) === 0;
    }

    /**
     * 获取所有有效模板
     */
    public function getActive(): array
    {
        return $this->where(['status' => 1]);
    }

    /**
     * 获取模板关联的提交数
     */
    public function getSubmitCount(string $slug): int
    {
        return (int)db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "forms WHERE type = ?",
            [$slug]
        );
    }
}
