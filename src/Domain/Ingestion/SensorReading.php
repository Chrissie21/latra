<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** One decoded source sensor: type identity, reported value and reported unit. */
final readonly class SensorReading
{
    public function __construct(
        public ?string $type = null,
        public mixed $value = null,
        public ?string $unit = null,
    ) {
    }
}
