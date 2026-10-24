<?php
/**
 * YikaiCMS - 多套 AI API 配置（2.1.2）
 *
 * 一个站点可以保存多套 API 信息（名称、服务商、接口地址、密钥、默认模型、翻译模型），
 * 选其中一套作为「主 API」。所有没有单独指定配置的 AI 功能都用主 API。
 *
 * 存储：settings 表的 ai_profiles（JSON，密钥经 SecretStore 加密），主 API 编号在 ai_active_profile。
 * 不另建表——可移植表结构是整站模板的契约，加表加列要配迁移，这里没有必要。
 *
 * 和旧代码的衔接：主 API 的值同步写回旧的 ai_provider / ai_api_key / ai_model / ai_base_url /
 * ai_translation_model。AiService 的默认值、AI 面板、各处翻译和插件都只认这几个键，
 * 所以它们自动跟随主 API；没有配置列表的老站照旧可用。写这几个键的只有本类（以及旧版设置页）。
 * 一次请求开始时 settings 整表读入缓存，AiService 构造时取定服务商、密钥、模型和地址，
 * 同一次多阶段翻译中途切换主 API 也不会换服务商。
 */

declare(strict_types=1);

final class AiProfiles
{
    public const STORE_KEY = 'ai_profiles';
    public const ACTIVE_KEY = 'ai_active_profile';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_ARCHIVED = 'archived';

    /** 主 API 同步写回的旧单配置键（AiService 等旧代码的读取口径） */
    public const LEGACY_KEYS = ['ai_provider', 'ai_base_url', 'ai_api_key', 'ai_model', 'ai_translation_model'];

    /**
     * 全部配置（含归档），按编号排序；密钥字段 api_key 是密文。
     * 还没有配置列表、但旧单配置已填过时，按旧值给出编号 1 的配置（只在内存里，第一次保存时才落库），
     * 所以迁移不需要单独跑、也不会重复生成。
     *
     * @return list<array{id:int,name:string,provider:string,base_url:string,api_key:string,model:string,translation_model:string,status:string,created_at:int,updated_at:int}>
     */
    public static function all(): array
    {
        $stored = self::stored();
        if ($stored !== null) {
            return $stored['items'];
        }
        $legacy = self::legacyProfile();
        return $legacy === null ? [] : [$legacy];
    }

    /** @return array<string,mixed>|null */
    public static function find(int $id): ?array
    {
        foreach (self::all() as $profile) {
            if ($profile['id'] === $id) return $profile;
        }
        return null;
    }

    /** 主 API 编号；0 = 还没有任何配置 */
    public static function activeId(): int
    {
        $profiles = self::all();
        if ($profiles === []) return 0;
        $id = (int) settingModel()->get(self::ACTIVE_KEY, '0');
        foreach ($profiles as $profile) {
            if ($profile['id'] === $id && $profile['status'] === self::STATUS_ACTIVE) return $id;
        }
        // 未记录（旧站第一次打开）时，第一套可用配置就是主 API
        foreach ($profiles as $profile) {
            if ($profile['status'] === self::STATUS_ACTIVE) return $profile['id'];
        }
        return 0;
    }

    /**
     * 新建（id = 0）或更新一套配置，返回编号。
     * $plainKey：空串或带 *** 的脱敏值表示「不修改已存密钥」；新建时为空则不存密钥。
     * $clearKey：删除已存密钥（后台点了清空）；同时给了新密钥时以新密钥为准。
     * 第一套配置自动成为主 API；保存的是主 API 时同步写回旧键。
     *
     * @param array{name?:string,provider?:string,base_url?:string,model?:string,translation_model?:string} $fields
     */
    public static function save(int $id, array $fields, string $plainKey, bool $clearKey = false): int
    {
        $state = self::state();
        $provider = trim((string) ($fields['provider'] ?? ''));
        if ($provider === '' || !array_key_exists($provider, AiService::getProviders())) {
            throw new InvalidArgumentException('ai_profile_bad_provider');
        }
        $name = trim((string) ($fields['name'] ?? ''));
        if ($name === '') $name = (string) (AiService::getProviders()[$provider]['name'] ?? $provider);
        $now = time();
        $values = [
            'name' => mb_substr($name, 0, 60),
            'provider' => $provider,
            'base_url' => trim((string) ($fields['base_url'] ?? '')),
            'model' => trim((string) ($fields['model'] ?? '')),
            'translation_model' => trim((string) ($fields['translation_model'] ?? '')),
            'updated_at' => $now,
        ];
        $keyChanged = $plainKey !== '' && !str_contains($plainKey, '***');

        if ($id > 0) {
            $index = self::indexOf($state['items'], $id);
            if ($index === null) throw new InvalidArgumentException('ai_profile_not_found');
            // 换了服务商却没给新密钥：旧密钥属于原服务商，不能跟着发给新服务商。
            // 官方接口（yikai）凭授权码调用、不用密钥，改成它时直接清掉旧密钥（AiService 只在密钥为空时用授权码）。
            $old = $state['items'][$index];
            if ($clearKey && !$keyChanged) $values['api_key'] = '';
            if ($old['provider'] !== $provider && !$keyChanged && !$clearKey && $old['api_key'] !== '') {
                if ($provider !== 'yikai') throw new InvalidArgumentException('ai_provider_key_required');
                $values['api_key'] = '';
            }
            $state['items'][$index] = array_merge($state['items'][$index], $values);
            if ($keyChanged) $state['items'][$index]['api_key'] = AiService::encryptKey($plainKey);
        } else {
            $id = $state['next_id'];
            $state['next_id']++;
            $state['items'][] = $values + [
                'id' => $id,
                'api_key' => $keyChanged ? AiService::encryptKey($plainKey) : '',
                'status' => self::STATUS_ACTIVE,
                'created_at' => $now,
            ];
        }
        $active = $state['active'] > 0 ? $state['active'] : $id;
        self::persist($state['items'], $state['next_id'], $active);
        return $id;
    }

    /** 设为主 API（归档的配置不能当主 API） */
    public static function setActive(int $id): void
    {
        $state = self::state();
        $index = self::indexOf($state['items'], $id);
        if ($index === null) throw new InvalidArgumentException('ai_profile_not_found');
        if ($state['items'][$index]['status'] !== self::STATUS_ACTIVE) throw new InvalidArgumentException('ai_profile_archived_cannot_be_primary');
        self::persist($state['items'], $state['next_id'], $id);
    }

    /** 归档：不再出现在可选列表里，密钥保留，可恢复。主 API 要先切换才能归档。 */
    public static function archive(int $id): void
    {
        self::setStatus($id, self::STATUS_ARCHIVED);
    }

    public static function restore(int $id): void
    {
        self::setStatus($id, self::STATUS_ACTIVE);
    }

    /** 删除：只允许删已归档的配置（两步：先归档、再删除），主 API 不能删 */
    public static function delete(int $id): void
    {
        $state = self::state();
        $index = self::indexOf($state['items'], $id);
        if ($index === null) throw new InvalidArgumentException('ai_profile_not_found');
        if ($id === $state['active']) throw new InvalidArgumentException('ai_profile_primary_locked');
        if ($state['items'][$index]['status'] !== self::STATUS_ARCHIVED) throw new InvalidArgumentException('ai_profile_archive_first');
        array_splice($state['items'], $index, 1);
        self::persist($state['items'], $state['next_id'], $state['active']);
    }

    /** 某套配置的明文密钥（连接测试用；只在服务端使用，绝不回给浏览器） */
    public static function plainKey(int $id): string
    {
        $profile = self::find($id);
        return $profile === null || $profile['api_key'] === '' ? '' : AiService::decryptKey($profile['api_key']);
    }

    /**
     * 给页面用的视图：去掉密文，只留脱敏后的末四位。
     *
     * @param array<string,mixed> $profile
     * @return array<string,mixed>
     */
    public static function publicView(array $profile): array
    {
        $masked = $profile['api_key'] === '' ? '' : SecretStore::masked(AiService::decryptKey((string) $profile['api_key']));
        unset($profile['api_key']);
        $profile['key_masked'] = $masked;
        return $profile;
    }

    // ── 内部 ──────────────────────────────────────────

    private static function setStatus(int $id, string $status): void
    {
        $state = self::state();
        $index = self::indexOf($state['items'], $id);
        if ($index === null) throw new InvalidArgumentException('ai_profile_not_found');
        if ($status === self::STATUS_ARCHIVED && $id === $state['active']) throw new InvalidArgumentException('ai_profile_primary_locked');
        $state['items'][$index]['status'] = $status;
        $state['items'][$index]['updated_at'] = time();
        self::persist($state['items'], $state['next_id'], $state['active']);
    }

    /**
     * 当前完整状态（还没落库时由旧单配置生成）。
     *
     * @return array{items:list<array<string,mixed>>,next_id:int,active:int}
     */
    private static function state(): array
    {
        $stored = self::stored();
        $items = $stored['items'] ?? self::all();
        $nextId = $stored['next_id'] ?? 1;
        foreach ($items as $item) $nextId = max($nextId, $item['id'] + 1);
        return ['items' => $items, 'next_id' => $nextId, 'active' => self::activeId()];
    }

    /** @return array{items:list<array<string,mixed>>,next_id:int}|null 没存过（或存坏了）时为 null */
    private static function stored(): ?array
    {
        $raw = trim((string) settingModel()->get(self::STORE_KEY, ''));
        if ($raw === '') return null;
        $data = json_decode($raw, true);
        if (!is_array($data) || !is_array($data['items'] ?? null)) return null;
        $items = [];
        foreach ($data['items'] as $item) {
            if (!is_array($item) || (int) ($item['id'] ?? 0) <= 0) continue;
            $items[] = self::normalize($item);
        }
        usort($items, static fn(array $a, array $b): int => $a['id'] <=> $b['id']);
        return ['items' => $items, 'next_id' => max(1, (int) ($data['next_id'] ?? 1))];
    }

    /** 旧单配置 → 编号 1 的配置；什么都没填过的站返回 null */
    private static function legacyProfile(): ?array
    {
        $values = [];
        foreach (self::LEGACY_KEYS as $key) $values[$key] = trim((string) settingModel()->get($key, ''));
        if ($values['ai_api_key'] === '' && $values['ai_base_url'] === '' && $values['ai_model'] === '' && $values['ai_provider'] === '') {
            return null;
        }
        $provider = $values['ai_provider'] !== '' ? $values['ai_provider'] : 'deepseek';
        $providers = AiService::getProviders();
        return self::normalize([
            'id' => 1,
            'name' => (string) ($providers[$provider]['name'] ?? $provider),
            'provider' => $provider,
            'base_url' => $values['ai_base_url'],
            'api_key' => $values['ai_api_key'],
            'model' => $values['ai_model'],
            'translation_model' => $values['ai_translation_model'],
            'status' => self::STATUS_ACTIVE,
        ]);
    }

    /** @param array<string,mixed> $item */
    private static function normalize(array $item): array
    {
        return [
            'id' => (int) $item['id'],
            'name' => (string) ($item['name'] ?? ''),
            'provider' => (string) ($item['provider'] ?? ''),
            'base_url' => (string) ($item['base_url'] ?? ''),
            'api_key' => (string) ($item['api_key'] ?? ''),
            'model' => (string) ($item['model'] ?? ''),
            'translation_model' => (string) ($item['translation_model'] ?? ''),
            'status' => ($item['status'] ?? '') === self::STATUS_ARCHIVED ? self::STATUS_ARCHIVED : self::STATUS_ACTIVE,
            'created_at' => (int) ($item['created_at'] ?? 0),
            'updated_at' => (int) ($item['updated_at'] ?? 0),
        ];
    }

    /** @param list<array<string,mixed>> $items */
    private static function indexOf(array $items, int $id): ?int
    {
        foreach ($items as $i => $item) {
            if ($item['id'] === $id) return $i;
        }
        return null;
    }

    /**
     * 写回存储；主 API 的值同步到旧键。整组在一个事务里，失败时原配置与旧键都不变。
     *
     * @param list<array<string,mixed>> $items
     */
    private static function persist(array $items, int $nextId, int $active): void
    {
        $json = json_encode(['next_id' => $nextId, 'items' => array_values($items)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) throw new RuntimeException('ai_profiles encode failed');
        $primary = null;
        foreach ($items as $item) {
            if ($item['id'] === $active) $primary = $item;
        }
        $batch = [self::STORE_KEY => $json, self::ACTIVE_KEY => (string) ($primary === null ? 0 : $active)];
        if ($primary !== null) {
            $batch += [
                'ai_provider' => $primary['provider'],
                'ai_base_url' => $primary['base_url'],
                'ai_api_key' => $primary['api_key'],
                'ai_model' => $primary['model'],
                'ai_translation_model' => $primary['translation_model'],
            ];
        }
        // 调用方已在事务里时由它提交或回滚（PDO 不支持嵌套事务）
        $own = !db()->getPdo()->inTransaction();
        if ($own) db()->beginTransaction();
        try {
            settingModel()->saveBatch($batch);
            if ($own) db()->commit();
        } catch (Throwable $e) {
            if ($own) db()->rollback();
            settingModel()->clearCache();
            throw $e;
        }
    }
}
