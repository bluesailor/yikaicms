<?php
declare(strict_types=1);

class JobModel extends Model
{
    /**
     * Keep stored option values stable while translating their frontend labels.
     * @psalm-suppress PossiblyUnusedMethod Used by job_detail.php and theme card partials.
     */
    public static function fieldLabel(string $field, string $value): string
    {
        $keys = [
            'job_type' => ['全职' => 'job_fulltime', '兼职' => 'job_parttime', '实习' => 'job_intern'],
            'education' => ['不限' => 'job_any', '高中' => 'job_edu_highschool', '大专' => 'job_edu_college', '本科' => 'job_edu_bachelor', '硕士' => 'job_edu_master', '博士' => 'job_edu_phd'],
            'experience' => ['不限' => 'job_any', '应届生' => 'job_exp_fresh', '1-3年' => 'job_exp_1_3', '3-5年' => 'job_exp_3_5', '5-10年' => 'job_exp_5_10', '10年以上' => 'job_exp_10p'],
        ];
        if (isset($keys[$field][$value])) return __($keys[$field][$value]);
        if ($field === 'experience' && preg_match('/^(\d+)年以上$/u', $value, $match)) return __('job_exp_years_plus', ['years' => $match[1]]);
        return $value;
    }

    protected string $table = 'jobs';
    protected string $defaultOrder = 'is_top DESC, sort_order DESC, id DESC';
    protected bool $softDelete = true;

    /**
     * 获取招聘列表（分页+筛选）
     */
    public function getList(array $filters = [], int $limit = 20, int $offset = 0): array
    {
        // 回收站的行不进任何列表（前台/后台通用）
        $where = ['deleted_at IS NULL'];
        $params = [];

        if (isset($filters['status']) && $filters['status'] !== '') {
            $where[] = 'status = ?';
            $params[] = (int) $filters['status'];
        }
        if (!empty($filters['keyword'])) {
            $where[] = '(title LIKE ? OR location LIKE ?)';
            $kw = '%' . $filters['keyword'] . '%';
            $params[] = $kw;
            $params[] = $kw;
        }
        if (!empty($filters['lang'])) {
            $where[] = 'lang = ?';
            $params[] = $filters['lang'];
        }

        $whereSQL = $where ? 'WHERE ' . implode(' AND ', $where) : '';

        $total = (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM {$this->tableName()} {$whereSQL}",
            $params
        );

        $items = db()->fetchAll(
            "SELECT * FROM {$this->tableName()} {$whereSQL}
             ORDER BY {$this->defaultOrder}
             LIMIT ? OFFSET ?",
            array_merge($params, [$limit, $offset])
        );

        return ['items' => $items, 'total' => $total];
    }

    /**
     * 增加浏览量
     * @psalm-suppress PossiblyUnusedMethod Called by JobDetailController outside the Psalm entry list.
     */
    public function incrementViews(int $id): int
    {
        return $this->increment($id, 'views');
    }
}
