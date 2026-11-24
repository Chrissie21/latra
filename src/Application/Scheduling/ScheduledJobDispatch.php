<?php
declare(strict_types=1);

namespace Latra\Application\Scheduling;

/**
 * Maps a scheduled job key to its registered work.
 *
 * An unknown key is a no-op, so a renamed or removed job cannot abort a whole
 * scheduler tick. Registration order is preserved for operator reporting.
 */
final class ScheduledJobDispatch
{
    /** @param array<string, callable(): int> $jobs */
    public function __construct(private array $jobs)
    {
    }

    public function run(string $key): int
    {
        if (!isset($this->jobs[$key])) {
            return 0;
        }
        return (int) ($this->jobs[$key])();
    }

    /** @return list<string> */
    public function keys(): array
    {
        return array_keys($this->jobs);
    }
}
