<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Security\AuthRepository;
use PDO;

final class PdoAuthRepository implements AuthRepository
{
    public function __construct(private PDO $pdo) {}
    public function ensure(): void {
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS app_users (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) NOT NULL UNIQUE,
            password_hash VARCHAR(255) NOT NULL,
            display_name VARCHAR(100) NOT NULL DEFAULT '',
            role VARCHAR(20) NOT NULL DEFAULT 'operator',
            failed_logins INT NOT NULL DEFAULT 0,
            locked_until DATETIME NULL,
            is_active TINYINT(1) NOT NULL DEFAULT 1,
            last_login_at DATETIME NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
            id BIGINT PRIMARY KEY AUTO_INCREMENT,
            user_id INT NULL,
            username VARCHAR(50) NULL,
            action VARCHAR(50) NOT NULL,
            target VARCHAR(120) NOT NULL DEFAULT '',
            detail TEXT DEFAULT NULL,
            ip VARCHAR(45) NOT NULL DEFAULT '',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_audit_created (created_at),
            INDEX idx_audit_user (user_id)
        ) ENGINE=InnoDB");
        $this->pdo->exec("CREATE TABLE IF NOT EXISTS rate_limits (
            rate_key VARCHAR(64) NOT NULL,
            window_start BIGINT NOT NULL,
            cnt INT NOT NULL DEFAULT 0,
            PRIMARY KEY (rate_key, window_start)
        ) ENGINE=InnoDB");
    }
    public function activeUser(string $name): ?array {
        $stmt = $this->pdo->prepare('SELECT * FROM app_users WHERE username = ? AND is_active = 1');
        $stmt->execute([$name]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function failedLogin(int $id, int $failures, ?string $lockedUntil): void {
        $this->pdo->prepare('UPDATE app_users SET failed_logins = ?, locked_until = ? WHERE id = ?')->execute([$failures,$lockedUntil,$id]);
    }
    public function successfulLogin(int $id, string $at): void {
        $this->pdo->prepare('UPDATE app_users SET last_login_at = ?, failed_logins = 0, locked_until = NULL WHERE id = ?')->execute([$at,$id]);
    }
    public function incrementRate(string $key, int $window): int {
        $this->pdo->prepare('INSERT INTO rate_limits (rate_key, window_start, cnt) VALUES (?, ?, 1) ON DUPLICATE KEY UPDATE cnt = cnt + 1')->execute([$key,$window]);
        $stmt = $this->pdo->prepare('SELECT cnt FROM rate_limits WHERE rate_key = ? AND window_start = ?');
        $stmt->execute([$key,$window]);
        $value = $stmt->fetchColumn();
        return $value === false ? 1 : (int)$value;
    }
    public function appendAudit(array $entry): void {
        $this->pdo->prepare('INSERT INTO audit_log (user_id,username,action,target,detail,ip,before_json,after_json) VALUES (?,?,?,?,?,?,?,?)')->execute([
            $entry['user_id'],$entry['username'],$entry['action'],$entry['target'],$entry['detail'],$entry['ip'],$entry['before_json'],$entry['after_json'],
        ]);
    }
}
