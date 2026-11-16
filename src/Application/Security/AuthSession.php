<?php
namespace Latra\Application\Security;

interface AuthSession
{
    public function start(): void;
    public function get(string $key): mixed;
    public function set(string $key, mixed $value): void;
    public function login(array $identity, int $now): void;
    public function clear(): void;
}
