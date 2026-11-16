<?php
namespace Latra\Infrastructure\Scheduling;

use Latra\Application\Scheduling\ScheduleLocks;
use PDO;

/** Shares the repository's captured session; never reconnects while owning a lock. */
final class MysqlScheduleLocks implements ScheduleLocks
{
    public function __construct(private PDO $pdo) {}
    public function acquire(string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $stmt->execute([$name]);
        return (int) $stmt->fetchColumn() === 1;
    }
    public function release(string $name): void
    {
        $stmt = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
        $stmt->execute([$name]);
    }
}
