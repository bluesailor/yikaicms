<?php
/** 已退役：Blox 编辑器现为无条件可用的核心能力。 */

declare(strict_types=1);

return [
    'id' => '20260816_enable_blox_editor_by_default',
    'title' => '确认 Blox 可视化编辑器可用',
    'desc' => 'Blox 已成为无条件可用的核心能力，无需再写入开关设置。',
    'title_en' => 'Keep the Blox visual editor available',
    'title_ja' => 'Blox ビジュアルエディターを常時利用可能にする',
    'desc_en' => 'Blox is now an always-available core capability and no longer needs a stored switch.',
    'desc_ja' => 'Blox は常時利用できるコア機能になり、保存された切り替え設定は不要です。',
    'check' => static fn(): bool => true,
    'sqls' => [],
];
