<?php
declare(strict_types=1);
namespace Latra\Application\Collection;

use Closure;
use RuntimeException;

/** Reapply current mapping gates only within the requested vehicle's source streams. */
final class MapRelayReview
{
    private Closure $eventActivities;
    private Closure $bucketActivities;
    private Closure $mapPayload;

    public function __construct(private string $vehicleUnit, callable $eventActivities,
        callable $bucketActivities, callable $mapPayload)
    {
        $this->eventActivities = Closure::fromCallable($eventActivities);
        $this->bucketActivities = Closure::fromCallable($bucketActivities);
        $this->mapPayload = Closure::fromCallable($mapPayload);
    }

    public function map(array $source, string $unit): array
    {
        if (!str_starts_with($unit, $this->vehicleUnit . ':')) {
            throw new RuntimeException('Review stream does not belong to the requested vehicle');
        }
        $stream = substr($unit, strlen($this->vehicleUnit));
        $events = $stream === ':events';
        if ($events) $allowed = ($this->eventActivities)();
        elseif (preg_match('/^:activity:([0-9]+)$/D', $stream, $match)) $allowed = [(int) $match[1]];
        elseif ($stream === ':poll') $allowed = ($this->bucketActivities)();
        else throw new RuntimeException('Unsupported collection stream for automatic recovery');
        return ($this->mapPayload)($source, $allowed, $events);
    }
}
