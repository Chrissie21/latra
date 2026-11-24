<?php
declare(strict_types=1);

namespace Latra\Application\Emergency;

/** Payload-free emergency overview for operators and automation. */
final class ReadEmergencyEvents
{
    public function __construct(private EmergencyEventRepository $repository) {}

    public function activeCount(): int
    {
        return $this->repository->activeCount();
    }
}
