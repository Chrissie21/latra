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
        \Latra\Infrastructure\Logging\StructuredLog::write($level, $event, $ctx, self::requestId());
    }

    public static function info(string $event, array $ctx = []): void { self::log('info', $event, $ctx); }
    public static function warn(string $event, array $ctx = []): void { self::log('warn', $event, $ctx); }
    public static function error(string $event, array $ctx = []): void { self::log('error', $event, $ctx); }
}
