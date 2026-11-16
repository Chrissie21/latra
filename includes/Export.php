<?php
require_once __DIR__ . '/autoload.php';

/** Compatibility entry point for retained export callers. */
class Export
{
    public static function pushLogRows(string $status = ''): iterable
    {
        return (new \Latra\Application\Export\ExportPushLog(
            new \Latra\Infrastructure\Persistence\PdoPushLogExportRepository(Database::getInstance()),
        ))->rows($status);
    }
    public static function stream(string $filename, array $headers, iterable $rows): void
    {
        \Latra\Infrastructure\Http\CsvDownload::stream($filename, $headers, $rows);
    }
}
