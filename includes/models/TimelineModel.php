<?php
declare(strict_types=1);

class TimelineModel extends Model
{
    protected string $table = 'timelines';
    protected string $defaultOrder = 'sort_order DESC, year DESC, month DESC';

    /**
     * 获取前台时间线列表（按当前 siteLang 过滤；无对应语言行时回退到默认语言）
     */
    public function getActive(): array
    {
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        $defaultLang = (string) config('site_lang', 'zh-CN');
        $rows = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 AND lang = ? ORDER BY {$this->defaultOrder}",
            [$lang]
        );
        if (!$rows && $lang !== $defaultLang) {
            $rows = db()->fetchAll(
                "SELECT * FROM {$this->tableName()} WHERE status = 1 AND lang = ? ORDER BY {$this->defaultOrder}",
                [$defaultLang]
            );
        }
        return $rows;
    }

    /**
     * 批量更新排序（拖拽排序）
     */
    public function updateSort(array $ids): void
    {
        $total = count($ids);
        foreach ($ids as $index => $id) {
            db()->update($this->table, ['sort_order' => $total - $index], 'id = ?', [(int) $id]);
        }
    }
}
