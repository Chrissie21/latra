<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Logging;

/** Structured single-line JSON to logs/automation-YYYY-MM-DD.log. */
final class AutomationLog
{
    public static function write(string $event, array $context = []): void
    {
        $line = json_encode(array_merge(
            ['ts' => gmdate('Y-m-d\TH:i:s\Z')],
            ['event' => $event],
            $context,
        ));
        $directory = defined('LOGS_DIR') ? LOGS_DIR : dirname(__DIR__, 3) . '/logs';
        @file_put_contents($directory . '/automation-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND);
    }
}
