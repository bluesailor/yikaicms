<?php
/**
 * Yikai CMS - 自定义内容模型定义 Model
 *
 * 一个「内容模型」= 一种自定义内容类型（如 team/solution/faq）。
 * 内容行仍存 contents 表（type = model_key），字段定义走 extfields(owner_type=model_key)，
 * 字段值走 metas。见 yikaicms-docs/design-custom-content-model.md。
 */

declare(strict_types=1);

class ContentModelModel extends Model
{
    protected string $table = 'content_models';
    protected string $defaultOrder = 'sort_order DESC, id ASC';

    /** 内置内容类型 key（不可作自定义模型 key，避免与栏目内置类型冲突） */
    public const RESERVED_KEYS = ['list', 'page', 'product', 'case', 'download', 'job', 'album', 'link', 'article', 'content'];

    /**
     * 表是否就绪。老站升级前 / 全新安装尚未跑迁移时 content_models 可能不存在，
     * 读方法必须容错返回空，绝不因缺表让栏目/内容页 500。
     */
    private function ready(): bool
    {
        try {
            return db()->tableExists('content_models');
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** 按 key 取模型 */
    public function getByKey(string $key): ?array
    {
        if (!$this->ready()) {
            return null;
        }
        return db()->fetchOne(
            "SELECT * FROM {$this->tableName()} WHERE model_key = ?",
            [$key]
        );
    }

    /** 全部启用模型（后台下拉 / 类型白名单用） */
    public function allActive(): array
    {
        if (!$this->ready()) {
            return [];
        }
        return db()->fetchAll(
            "SELECT * FROM {$this->tableName()} WHERE status = 1 ORDER BY {$this->defaultOrder}"
        );
    }

    /** 已注册的启用模型 key 列表（供 owner_type / 栏目类型动态白名单） */
    public function keys(): array
    {
        if (!$this->ready()) {
            return [];
        }
        $rows = db()->fetchAll("SELECT model_key FROM {$this->tableName()} WHERE status = 1");
        return array_column($rows, 'model_key');
    }

    /**
     * 启用模型的 model_key => url_prefix 映射（url_prefix 空则回退 model_key）。
     * 进程内静态缓存，供 contentUrl 每链接调用而不重复查库。
     */
    public function prefixMap(): array
    {
        static $map = null;
        if ($map === null) {
            $map = [];
            if ($this->ready()) {
                foreach (db()->fetchAll("SELECT model_key, url_prefix FROM {$this->tableName()} WHERE status = 1") as $r) {
                    $map[$r['model_key']] = ($r['url_prefix'] !== '' ? $r['url_prefix'] : $r['model_key']);
                }
            }
        }
        return $map;
    }

    /** 按 URL 前缀反查模型（先匹配 url_prefix，再回退 model_key） */
    public function getByUrlPrefix(string $prefix): ?array
    {
        if (!$this->ready()) {
            return null;
        }
        $row = db()->fetchOne("SELECT * FROM {$this->tableName()} WHERE url_prefix = ? AND status = 1", [$prefix]);
        return $row ?: $this->getByKey($prefix);
    }

    /** key 是否可用（格式合法、未占用保留字、未重复） */
    public function isKeyValid(string $key): bool
    {
        if (!preg_match('/^[a-z][a-z0-9_]{1,31}$/', $key)) {
            return false;
        }
        if (in_array($key, self::RESERVED_KEYS, true)) {
            return false;
        }
        return $this->getByKey($key) === null;
    }

    /** 该模型下的内容条数（contents.type = model_key，排除回收站） */
    public function contentCount(string $key): int
    {
        return (int) db()->fetchColumn(
            "SELECT COUNT(*) FROM " . DB_PREFIX . "contents WHERE type = ? AND deleted_at IS NULL",
            [$key]
        );
    }
}
