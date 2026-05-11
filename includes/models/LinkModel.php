<?php
declare(strict_types=1);

class LinkModel extends Model
{
    protected string $table = 'links';
    protected string $defaultOrder = 'sort_order ASC, id DESC';

    /**
     * 获取前台有效链接（按当前 siteLang 过滤；无对应语言行时回退到默认语言）
     */
    public function getActive(int $limit = 20): array
    {
        $lang = function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        $defaultLang = (string) config('site_lang', 'zh-CN');
        $rows = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 AND lang = ? ORDER BY sort_order DESC, id DESC LIMIT ?",
            [$lang, $limit]
        );
        // 该语言一条都没翻译时回退到默认语言，避免前台合作伙伴区域全空
        if (!$rows && $lang !== $defaultLang) {
            $rows = db()->fetchAll(
                "SELECT * FROM {$this->tableName()} WHERE status = 1 AND lang = ? ORDER BY sort_order DESC, id DESC LIMIT ?",
                [$defaultLang, $limit]
            );
        }
        return $rows;
    }

    /**
     * 批量更新排序
     */
    public function updateSort(array $ids): void
    {
        foreach ($ids as $index => $id) {
            db()->update($this->table, ['sort_order' => $index], 'id = ?', [(int) $id]);
        }
    }
}
