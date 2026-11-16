<?php
require_once __DIR__.'/autoload.php';

/** Compatibility boundary for explicitly authorized generated-data maintenance. */
class DataCleaner {
    public const RESET_ORDER = \Latra\Domain\Maintenance\DataCleanupPolicy::RESET_ORDER;
    public const PROTECTED = \Latra\Domain\Maintenance\DataCleanupPolicy::PROTECTED;
    public static function categories(): array { return \Latra\Domain\Maintenance\DataCleanupPolicy::categories(); }
    private static function work(): \Latra\Application\Maintenance\ClearGeneratedData {
        return new \Latra\Application\Maintenance\ClearGeneratedData(
            new \Latra\Infrastructure\Persistence\PdoDataCleanupRepository(Database::getInstance()),static fn(): int => time());
    }
    public static function clear(string $category,array $filters): array { return self::work()->clear($category,$filters); }
    public static function resetAll(): array { return self::work()->resetAll(); }
}
