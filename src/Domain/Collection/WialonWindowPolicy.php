<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

use InvalidArgumentException;
use RuntimeException;

/**
 * Bounded Wialon window rules: an explicit unit/time range, a declared count
 * that fits the record budget, pages that match their declared size, source
 * timestamps inside the requested window, and a cumulative byte ceiling.
 *
 * Values and failure messages are unchanged from the legacy client so callers
 * and operators keep the same diagnostics.
 */
final class WialonWindowPolicy
{
    /** Machine-readable marker for "this window must be split", shared with the stream use case. */
    public const SUBDIVISION_CODE = 1001;

    public static function assertRequest(
        int $unitId,
        int $from,
        int $to,
        int $pageSize,
        int $maxRecords,
        int $maxBytes,
    ): void {
        if ($unitId < 1 || $from < 0 || $to < $from || min($pageSize, $maxRecords, $maxBytes) < 1) {
            throw new InvalidArgumentException('Explicit bounded Wialon window required');
        }
    }

    /**
     * The declared total must be a real non-negative integer that fits the
     * caller's record budget; a larger window must be subdivided explicitly.
     */
    public static function assertTotal(mixed $total, int $maxRecords): int
    {
        if (is_int($total) && $total > $maxRecords) {
            throw new RuntimeException('Wialon window needs subdivision', self::SUBDIVISION_CODE);
        }
        if (!is_int($total) || $total < 0 || $total > $maxRecords) {
            throw new RuntimeException('Invalid or over-bound Wialon count');
        }
        return $total;
    }

    /**
     * A page must be a list of exactly the number of records still expected in
     * this offset range, so a partial page can never look like progress.
     */
    public static function assertPage(mixed $page, int $expectedCount): array
    {
        if (!is_array($page) || !array_is_list($page) || count($page) !== $expectedCount) {
            throw new RuntimeException('Incomplete Wialon page');
        }
        return $page;
    }

    public static function assertMessage(mixed $message, int $from, int $to): array
    {
        // Missing/invalid timestamps must reach durable review unchanged. Known times
        // outside the requested interval still invalidate the source window.
        if (!is_array($message) || (is_int($message['t'] ?? null)
            && ($message['t'] < $from || $message['t'] > $to))) {
            throw new RuntimeException('Invalid Wialon source timestamp');
        }
        return $message;
    }

    public static function addBytes(int $bytes, array $message, int $maxBytes): int
    {
        $bytes += strlen(json_encode($message, JSON_THROW_ON_ERROR));
        if ($bytes > $maxBytes) {
            throw new RuntimeException('Wialon window exceeds byte bound');
        }
        return $bytes;
    }

    /**
     * A zero count with a non-empty first page is internally inconsistent and
     * must not be treated as a successfully loaded empty window.
     */
    public static function assertEmptyConsistency(mixed $total, mixed $firstPage): void
    {
        if ($total === 0 && $firstPage !== []) {
            throw new RuntimeException('Inconsistent empty Wialon window');
        }
    }
}
