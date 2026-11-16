<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Security\SecretRepository;
use PDO;

final class PdoSecretRepository implements SecretRepository
{
    public function __construct(private PDO $pdo) {}
    public function ensure(): void
    {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS vault (
            vault_key VARCHAR(100) PRIMARY KEY,
            ciphertext TEXT NOT NULL,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
    }
    public function putCiphertext(string $key, string $value): void
    {
        $this->pdo->prepare("INSERT INTO vault (vault_key, ciphertext) VALUES (?, ?)
             ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext)")->execute([$key,$value]);
    }
    public function ciphertext(string $key): ?string
    {
        $stmt = $this->pdo->prepare('SELECT ciphertext FROM vault WHERE vault_key = ?');
        $stmt->execute([$key]);
        $value = $stmt->fetchColumn();
        return $value === false ? null : (string)$value;
    }
    public function has(string $key): bool
    {
        $stmt = $this->pdo->prepare('SELECT vault_key FROM vault WHERE vault_key = ?');
        $stmt->execute([$key]);
        return $stmt->fetchColumn() !== false;
    }
}
