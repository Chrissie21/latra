<?php
namespace Latra\Infrastructure\Scheduling;

use Latra\Application\Scheduling\ScheduleClock;

final class SystemScheduleClock implements ScheduleClock
{
    public function __construct(private string $format) {}
    public function now(): float { return microtime(true); }
    public function format(float $time): string { return date($this->format, (int) $time); }
}
