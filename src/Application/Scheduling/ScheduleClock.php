<?php
namespace Latra\Application\Scheduling;

interface ScheduleClock
{
    public function now(): float;
    public function format(float $time): string;
}
