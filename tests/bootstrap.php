<?php
declare(strict_types=1);
if (!function_exists('__')) {
    function __(string $message, ?string $domain = null): string { return $message; }
}
require_once dirname(__DIR__) . '/src/Service/ContentService.php';
require_once dirname(__DIR__) . '/src/Service/AttachmentService.php';
require_once dirname(__DIR__) . '/src/Service/ActionService.php';
require_once dirname(__DIR__) . '/src/Service/QueueService.php';
