<?php
declare(strict_types=1);
namespace Latra\Infrastructure\Logging;

final class StructuredLog
{
    public static function write(string $level, string $event, array $context, string $requestId): void
    {
        $line = json_encode(array_merge([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'), 'level' => $level, 'event' => $event, 'rid' => $requestId,
        ], $context), JSON_UNESCAPED_SLASHES);
        @file_put_contents(LOGS_DIR . '/app-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND);
        AutomationLog::write($event, $context);
    }
}
