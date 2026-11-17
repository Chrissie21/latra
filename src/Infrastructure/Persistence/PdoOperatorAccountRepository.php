<?php
declare(strict_types=1);

namespace Latra\Infrastructure\Persistence;

use Latra\Application\Security\OperatorAccountRepository;
use PDO;

/** Operator account provisioning on the same connection the command composed. */
final class PdoOperatorAccountRepository implements OperatorAccountRepository
{
    public function __construct(private PDO $pdo) {}

    public function findIdByName(string $username): ?int
    {
        $statement = $this->pdo->prepare('SELECT id FROM app_users WHERE username = ?');
        $statement->execute([$username]);
        $id = $statement->fetchColumn();
        return $id === false ? null : (int) $id;
    }

    public function create(array $account): void
    {
        $columns = array_keys($account);
        $statement = $this->pdo->prepare(
            'INSERT INTO app_users (`' . implode('`, `', $columns) . '`) VALUES ('
            . implode(', ', array_fill(0, count($columns), '?')) . ')'
        );
        $statement->execute(array_values($account));
    }

    public function update(int $id, array $fields): void
    {
        $sets = [];
        foreach (array_keys($fields) as $column) {
            $sets[] = '`' . $column . '` = ?';
        }
        $statement = $this->pdo->prepare('UPDATE app_users SET ' . implode(', ', $sets) . ' WHERE id = ?');
        $statement->execute(array_merge(array_values($fields), [$id]));
    }
}
