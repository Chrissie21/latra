<?php
namespace Latra\Application\Scheduling;

interface ScheduleLocks
{
    public function acquire(string $name): bool;
    public function release(string $name): void;
}
