<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use PDO;
use RuntimeException;

/**
 * Explicit delivery-index migration. It never runs from request startup, holds
 * the named advisory lock for its whole run and skips existing indexes.
 */
final class DeliveryIndexMigration
{
    public function __construct(private PDO $pdo) {}

    /** @return list<string> Applied "table.index" names. */
    public function migrate(string $sqlFile, string $lockName): array
    {
        $lock = $this->pdo->prepare('SELECT GET_LOCK(?, 0)');
        $lock->execute([$lockName]);
        if ((int) $lock->fetchColumn() !== 1) {
            throw new RuntimeException('Delivery migration is already running');
        }
        try {
            return $this->apply($sqlFile);
        } finally {
            $release = $this->pdo->prepare('SELECT RELEASE_LOCK(?)');
            $release->execute([$lockName]);
        }
    }

    /** @return list<string> Applied "table.index" names. */
    private function apply(string $sqlFile): array
    {
        $sql = preg_replace('/^--.*$/m', '', (string) file_get_contents($sqlFile));
        $applied = [];
        foreach (explode(';', $sql) as $statement) {
            if (!preg_match('/ALTER TABLE (\w+) ADD INDEX (\w+)/', $statement, $match)) {
                continue;
            }
            $check = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.statistics
                 WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ?'
            );
            $check->execute([$match[1], $match[2]]);
            if ((int) $check->fetchColumn() > 0) {
                continue;
            }
            $this->pdo->exec(trim($statement));
            $applied[] = $match[1] . '.' . $match[2];
        }
        return $applied;
    }
}
