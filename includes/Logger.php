<?php
/**
 * Simple JSON logger.
 */
class Logger {
    private static string $requestId = '';

    public static function requestId(): string {
        if (self::$requestId === '') {
            self::$requestId = bin2hex(random_bytes(8));
        }
        return self::$requestId;
    }

    public static function log(string $level, string $event, array $ctx = []): void {
        $line = json_encode(array_merge([
            'ts' => gmdate('Y-m-d\TH:i:s\Z'),
            'level' => $level,
            'event' => $event,
            'rid' => self::requestId(),
        ], $ctx), JSON_UNESCAPED_SLASHES);
        @file_put_contents(LOGS_DIR . '/app-' . date('Y-m-d') . '.log', $line . "\n", FILE_APPEND);
        if (class_exists('Automation')) {
            Automation::log($event, $ctx);
        }
    }

    public static function info(string $event, array $ctx = []): void { self::log('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void { self::log('warn', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::log('error', $event, $ctx); }
}
