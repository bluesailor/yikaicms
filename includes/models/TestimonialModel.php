<?php
declare(strict_types=1);

/**
 * 客户评价（2.1.0）：按语言存行，同一条评价的各语言版本共享 translation_group_id（与 banners、timelines 一致）。
 * 评价轮播元素选「来自客户评价」时从这里取当前语言的已启用评价；这一语言一条都没有就回退到默认语言。
 */
class TestimonialModel extends Model
{
    protected string $table = 'testimonials';
    protected string $defaultOrder = 'sort_order ASC, id ASC';

    /**
     * 前台可显示的评价（$limit = 0 不限）。
     *
     * @return list<array<string,mixed>>
     */
    public function getActive(?string $lang = null, int $limit = 0): array
    {
        $lang ??= function_exists('siteLang') ? siteLang() : (string) config('site_lang', 'zh-CN');
        $defaultLang = (string) config('site_lang', 'zh-CN');
        $rows = $this->activeFor($lang, $limit);
        if ($rows === [] && $lang !== $defaultLang) {
            $rows = $this->activeFor($defaultLang, $limit);
        }
        return $rows;
    }

    /** @return list<array<string,mixed>> */
    private function activeFor(string $lang, int $limit): array
    {
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 AND lang = ? ORDER BY {$this->defaultOrder}"
                . ($limit > 0 ? ' LIMIT ' . $limit : ''),
            [$lang]
        );
    }
}
