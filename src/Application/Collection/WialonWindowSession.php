<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

/**
 * One loaded Wialon message window: the first bounded page carries the
 * declared total count, and later pages are addressed by exclusive index
 * range. Every implementation must release the loaded window (unload) even
 * when validation fails.
 */
interface WialonWindowSession
{
    /** @return array{count?: mixed, messages?: mixed}|null */
    public function loadWindow(int $unitId, int $from, int $to, int $count, int $maxBytes): ?array;

    /** @return mixed list of source messages, or a malformed response */
    public function loadPage(int $from, int $to, int $maxBytes): mixed;

    public function unload(): void;
}
