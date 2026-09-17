<?php
/** Deterministic official template catalog fixture for browser presentation checks. */

declare(strict_types=1);

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'code' => 0,
    'data' => [
        // 目录协议 v2（G5-MARKET-API-CONTRACT）：客户端拒绝缺少 protocol_version / access / paid 的旧式目录
        'protocol_version' => 2,
        'updated_at' => '2026-09-05',
        'templates' => [
            [
                'slug' => 'fixture-cover-template',
                'type' => 'page',
                'category' => 'landing',
                'tier' => 'free',
                'name' => 'Fixture cover template',
                'description' => 'A deterministic cover fixture.',
                'version' => '1.0.0',
                'thumbnail' => '/assets/templates/cta-centered.png',
                'access' => 'public',
                'paid' => false,
                'entitled' => true,
            ],
            [
                'slug' => 'fixture-no-cover-template',
                'type' => 'section',
                'category' => 'content',
                'tier' => 'free',
                'name' => 'Fixture without cover',
                'description' => 'A deterministic no-cover fallback fixture.',
                'version' => '1.0.0',
                'thumbnail' => '',
                'access' => 'public',
                'paid' => false,
                'entitled' => true,
            ],
        ],
    ],
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
