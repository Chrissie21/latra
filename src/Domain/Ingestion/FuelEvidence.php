<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/**
 * Decoded fuel facts with their reported unit.
 *
 * Values may be null: an absent measurement is retained for review rather than converted into a
 * healthy-looking zero. Wire conversion and eligibility stay in the LATRA policy.
 */
final readonly class FuelEvidence
{
    public function __construct(
        public ?string $unit = null,
        public ?float $level = null,
        public ?float $rtLevel = null,
        public int|float|string|bool|null $validFlag = null,
        public int|float|string|bool|null $signalLevel = null,
        public int|float|string|bool|null $softStatus = null,
        public int|float|string|bool|null $hardFault = null,
        public ?float $tankTempC = null,
        public int|float|string|bool|null $channel = null,
    ) {
    }
}
