<?php
/**
 * WP HTML API loader — vendored from WordPress 6.9.4.
 *
 * Provides WP_HTML_Tag_Processor for safe, streaming HTML attribute rewriting.
 * Used by HtmlPipeline to enhance content (lazy-load images, rel=noopener, etc.).
 */

declare(strict_types=1);

require_once __DIR__ . '/wp-shims.php';

require_once __DIR__ . '/class-wp-html-span.php';
require_once __DIR__ . '/class-wp-html-text-replacement.php';
require_once __DIR__ . '/class-wp-html-attribute-token.php';
require_once __DIR__ . '/class-wp-html-decoder.php';
require_once __DIR__ . '/class-wp-html-doctype-info.php';
require_once __DIR__ . '/class-wp-html-tag-processor.php';
