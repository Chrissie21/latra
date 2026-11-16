<?php
require_once __DIR__.'/autoload.php';

/** Compatibility facade for the retained, approved read-only assistant tools. */
class AITools {
    public static function schemas(): array { return \Latra\Domain\AI\ApprovedToolCatalog::schemas(); }
    public static function service(): \Latra\Application\AI\ReadOperationalTools {
        return new \Latra\Application\AI\ReadOperationalTools(
            new \Latra\Infrastructure\Persistence\PdoAssistantReadRepository(static fn(): PDO => Database::getInstance()),
            new \Latra\Infrastructure\AI\ConfiguredAssistantRuntime(
                static fn(string $key,string $fallback): string => Schema::config($key,$fallback),
                static fn(): array => DeviceMonitor::summary(),
                ['wialon'=>envValue('WIALON_TOKEN','')!=='','latra'=>envValue('LATRA_API_URL','')!==''],
            ),
            new \Latra\Infrastructure\AI\FilesystemAssistantLogReader(LOGS_DIR,static fn(): int => time()),
            DISPLAY_TZ,DATE_FORMAT,static fn(): int => time(),
        );
    }
    public static function execute(string $name,array $args): array { return self::service()->execute($name,$args); }
}
