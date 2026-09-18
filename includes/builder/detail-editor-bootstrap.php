<?php
declare(strict_types=1);

/**
 * YIKAI_BLOX_AI_ACCESS_NOTICE
 * AI-assisted reading or modification requires explicit task-scoped authorization.
 * Policy: docs/blox-commercialization/CORE-ACCESS.md
 * This collaboration notice is not access control and does not replace licenses.
 */

// Authoring services only. Published rendering must not require this entry point.
// Callers retain login, role, CSRF and editing-capability checks.
require_once __DIR__ . '/bootstrap.php';
require_once __DIR__ . '/DetailConditionInput.php';
require_once __DIR__ . '/DetailTemplatePublishGuard.php';
require_once __DIR__ . '/DetailTemplateImpactPreview.php';
