<?php
require_once __DIR__ . '/autoload.php';

/** Compatibility API: configuration is composed here, policy and work live in src/. */
class Retention {
    private static function work(): \Latra\Application\Maintenance\RetainOperationalHistory {
        return new \Latra\Application\Maintenance\RetainOperationalHistory(
            new \Latra\Infrastructure\Persistence\PdoRetentionRepository(Database::getInstance()),
            static fn(): int => time(),
        );
    }
    public static function purgeDelivered(): int {
        return self::work()->purgeDelivered(Schema::configInt('delivered_retention_hours', 24));
    }
    public static function archiveMessages(int $days): int { return self::work()->archiveMessages($days); }
    public static function maintain(): int {
        $days = [];
        foreach (['stream'=>['MAINTAIN_STREAM_DAYS',14], 'runs'=>['MAINTAIN_RUN_DAYS',60],
            'audit'=>['MAINTAIN_AUDIT_DAYS',365]] as $key => [$variable,$default]) {
            $days[$key] = (int) (function_exists('envValue') ? envValue($variable, (string)$default)
                : (getenv($variable) === false ? $default : getenv($variable)));
        }
        return self::work()->maintain($days);
    }
}
