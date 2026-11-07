<?php
declare(strict_types=1);

/** Private working copy and history for the product edit form. */
final class ProductEditDraft
{
    private const FIELDS = [
        'category_id', 'title', 'subtitle', 'slug', 'model', 'cover', 'images', 'summary', 'content',
        'specs', 'tags', 'product_type', 'material', 'scene', 'is_top', 'is_recommend', 'is_hot',
        'is_new', 'status', 'price', 'market_price',
    ];

    public static function live(array $row): array
    {
        $id = (int) $row['id'];
        productRouteModel()->clearCache();
        $time = ScheduledPublish::productTime($id);
        $values = metaModel()->getAllByOwner('product', $id);
        $ext = [];
        foreach (ExtFields::fields('product', true) as $field) {
            $key = (string) $field['field_key'];
            $ext[$key] = (string) ($values[$key] ?? '');
        }
        return [
            'fields' => array_intersect_key($row, array_flip(self::FIELDS)),
            'route' => productRouteModel()->pathFor('product', $id),
            'time' => $time > 0 ? date('Y-m-d\TH:i', $time) : '',
            'ext' => $ext,
        ];
    }

    public static function fingerprint(array $snapshot): string
    {
        // Keep JSON numbers consistent between form values and database rows.
        $fields = array_map(static fn(mixed $value): string => (string) $value, $snapshot['fields']);
        ksort($fields);
        $ext = $snapshot['ext'];
        ksort($ext);
        return hash('sha256', json_encode([$fields, $snapshot['route'], $snapshot['time'], $ext], JSON_THROW_ON_ERROR));
    }

    public static function snapshot(array $record): array
    {
        return json_decode((string) $record['snapshot'], true, 512, JSON_THROW_ON_ERROR);
    }

    public static function historyVersion(int $productId, int $revisionId): ?array
    {
        $record = contentRevisionModel()->getOne($revisionId);
        return $record && $record['target_type'] === 'product_edit' && (int) $record['target_id'] === $productId
            ? self::snapshot($record) : null;
    }

    public static function form(array $data, array $posted, array $base): array
    {
        $ext = $base['ext'];
        foreach (ExtFields::fields('product', true) as $field) {
            $key = (string) $field['field_key'];
            if (array_key_exists($key, $posted) || array_key_exists('__present_' . $key, $posted)) {
                $ext[$key] = ExtFields::sanitize($field, $posted[$key] ?? ($field['field_type'] === 'switch' ? '0' : ''));
            }
        }
        return [
            'fields' => array_replace($base['fields'], array_intersect_key($data, array_flip(self::FIELDS))),
            'route' => (string) post('custom_url', $base['route']),
            'time' => (string) post('publish_time'),
            'ext' => $ext,
        ];
    }

    /** Lock the product before reading/writing either copy, so stale tabs cannot overwrite newer work. */
    public static function save(int $id, array $data, array $posted, string $action, int $revisionId): int
    {
        $db = db();
        $model = contentRevisionModel();
        $db->beginTransaction();
        try {
            $row = $id > 0 ? $db->lockRow('products', $id) : null;
            if ($id > 0 && (!$row || !empty($row['deleted_at']))) {
                throw new InvalidArgumentException('product_url_missing');
            }
            $live = $row ? self::live($row) : ['fields' => [], 'route' => '', 'time' => '', 'ext' => []];
            $draftRow = $row ? $model->productEditDraft($id) : null;
            $draft = $draftRow ? self::snapshot($draftRow) : null;
            $liveVersion = self::fingerprint($live);
            $expected = (string) post('product_live_version');
            $editingDraft = postInt('product_editing_draft') === 1;
            if ($row && ($expected !== '' || $draftRow || $action !== 'apply')
                && ($expected !== $liveVersion || postInt('product_draft_version') !== (int) ($draftRow['id'] ?? 0)
                    || ($editingDraft && !$draft))) {
                throw new InvalidArgumentException('prod_edit_draft_conflict');
            }

            if ($action === 'restore') {
                $next = self::historyVersion($id, $revisionId);
                if (!$row || !$next) throw new InvalidArgumentException('admin_bad_params');
            } else {
                $next = self::form($data, $posted, $editingDraft && $draft ? $draft : $live);
            }

            if ($action === 'draft' || $action === 'restore') {
                if (!$row) throw new InvalidArgumentException('admin_bad_params');
                $next['base'] = $action === 'draft' && $editingDraft ? $draft['base'] : $liveVersion;
                if (!$draft) $model->recordProductEdit($id, (string) $row['lang'], $live, false);
                if ($draft && self::fingerprint($draft) !== self::fingerprint($next)) {
                    $model->recordProductEdit($id, (string) $row['lang'], $draft, false);
                }
                $model->recordProductEdit($id, (string) $row['lang'], $next, true);
            } else {
                if ($editingDraft && $draft && $draft['base'] !== $liveVersion) {
                    throw new InvalidArgumentException('prod_edit_draft_stale');
                }
                if ((string) ($data['title'] ?? '') === '') throw new InvalidArgumentException('prod_name_required');
                $missing = ExtFields::missingRequired('product', $next['ext'], (int) $data['category_id']);
                if ($missing !== null) throw new DomainException(sprintf(__('ef_required_missing'), $missing));
                try {
                    $schedule = ScheduledPublish::normalize((int) $data['status'], $next['time'], ScheduledPublish::productTime($id));
                } catch (InvalidArgumentException) {
                    throw new InvalidArgumentException('prod_scheduled_time_required');
                }
                $data['status'] = $schedule['status'];
                $data['slug'] = resolveSlug($data['slug'], $data['title'], 'products', $id);
                if ($row) $model->recordProductEdit($id, (string) $row['lang'], $live, false);
                $id = productRouteModel()->saveEntity('product', $id, $data, $next['route']);
                ScheduledPublish::setProductTime($id, $schedule['status'] === ScheduledPublish::STATUS ? $schedule['publish_time'] : 0);
                foreach (array_diff_assoc($next['ext'], $live['ext']) as $key => $value) {
                    metaModel()->set('product', $id, $key, $value);
                }
                if ($editingDraft) $model->clearProductEditDraft($id);
            }
            $db->commit();
        } catch (Throwable $e) {
            if ($db->getPdo()->inTransaction()) $db->rollback();
            productRouteModel()->clearCache();
            throw $e;
        }
        return $id;
    }
}
