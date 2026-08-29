<?php
/** 已退役：Blox 编辑器现为无条件可用的核心能力。 */

declare(strict_types=1);

return [
    'id' => '20260808_blox_editor_switch_seed',
    'title' => '退役 Blox 编辑器开关',
    'desc' => 'Blox 已成为无条件可用的核心能力，不再创建独立开关设置。',
    'title_en' => 'Retire the Blox editor switch',
    'title_ja' => 'Blox エディター切り替えを廃止',
    'desc_en' => 'Blox is now an always-available core capability, so no separate switch setting is created.',
    'desc_ja' => 'Blox は常時利用できるコア機能になったため、個別の切り替え設定は作成しません。',
    'check' => static fn(): bool => true,
    'sqls' => [],
];
