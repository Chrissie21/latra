<?php
namespace Latra\Application\Export;

interface PushLogExportRepository
{
    public function ceiling(): int;
    public function page(string $status, int $ceiling, ?array $cursor, int $limit): array;
}
