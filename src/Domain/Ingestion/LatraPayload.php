<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/** Typed assembly of one LATRA envelope. Serialization happens only at the delivery boundary. */
final readonly class LatraPayload
{
    /** @param list<LatraItem> $items */
    public function __construct(
        public ?string $vehicleRegNo,
        public ?string $imei,
        public array $items,
    ) {
    }

    public function withItem(LatraItem $item): self
    {
        $items = $this->items;
        $items[] = $item;
        return new self($this->vehicleRegNo, $this->imei, $items);
    }

    public function toWire(): array
    {
        return [
            'vehicle_reg_no' => $this->vehicleRegNo,
            'type' => 'poi',
            'imei' => $this->imei,
            'items' => array_map(static fn(LatraItem $item): array => $item->toWire(), $this->items),
        ];
    }
}
