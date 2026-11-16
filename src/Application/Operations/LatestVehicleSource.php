<?php
namespace Latra\Application\Operations;

interface LatestVehicleSource
{
    /** Matching unit metadata, if available, plus the newest source message. */
    public function latest(int $unit,int $from,int $to): ?array;
}
