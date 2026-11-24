<?php
declare(strict_types=1);

namespace Latra\Domain\Collection;

/**
 * Validated relay enrollment settings for one vehicle.
 *
 * The stored row is checked once, when it crosses the persistence boundary, so
 * no consumer has to re-verify that every budget exists or is positive. Budget
 * rules and their operator-facing diagnostics stay in {@see RelayIdentityPolicy}.
 */
final readonly class RelaySettings
{
    public function __construct(
        public int $vehicleId,
        public string $profile,
        public int $maxRecords,
        public int $maxBytes,
        public int $maxPendingRecords,
        public int $maxPendingBytes,
    ) {
        RelayIdentityPolicy::assertBudgets([
            'max_records' => $maxRecords,
            'max_bytes' => $maxBytes,
            'max_pending_records' => $maxPendingRecords,
            'max_pending_bytes' => $maxPendingBytes,
        ]);
    }

    /** Convert one enabled enrollment row, rejecting missing or non-positive budgets. */
    public static function fromRow(array $row, int $vehicleId): self
    {
        return new self(
            $vehicleId,
            (string) ($row['profile'] ?? ''),
            (int) ($row['max_records'] ?? 0),
            (int) ($row['max_bytes'] ?? 0),
            (int) ($row['max_pending_records'] ?? 0),
            (int) ($row['max_pending_bytes'] ?? 0),
        );
    }
}
