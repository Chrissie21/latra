<?php
namespace Latra\Infrastructure\Persistence;

use PDO;
use PDOStatement;
use PDOException;
use Exception;
use Throwable;

/** Shared connection and SQL primitives; production configuration is supplied by config.php. */
abstract class DatabaseConnection {
    abstract protected static function createConnection(): PDO;
    protected static ?PDO $instance = null;
    protected static bool $sensitiveConfigScrubbed = false;

    public static function getInstance(): PDO {
        if (self::$instance !== null) {
            $inTransaction = self::$instance->inTransaction();
            try {
                self::$instance->query('SELECT 1');
            } catch (PDOException $e) {
                // A replacement connection cannot continue the caller's atomic write.
                if ($inTransaction) throw $e;
                self::$instance = null;
            }
        }
        if (self::$instance === null) {
            try {
                self::$instance = static::createConnection();
            } catch (PDOException $e) {
                error_log("Database connection failed: " . $e->getMessage());
                throw new Exception("Database connection failed. Please check configuration.");
            }
        }
        self::scrubSensitiveConfig();
        return self::$instance;
    }

    private static function scrubSensitiveConfig(): void {
        if (self::$sensitiveConfigScrubbed || self::$instance === null) {
            return;
        }

        self::$sensitiveConfigScrubbed = true;

        try {
            $stmt = self::$instance->prepare(
                "UPDATE config SET config_value = '' WHERE config_key IN (?, ?, ?, ?, ?)"
            );
            $stmt->execute(['wialon_base_url', 'wialon_token', 'latra_api_url', 'latra_username', 'latra_password']);
        } catch (PDOException $e) {
            error_log("Sensitive config scrub skipped: " . $e->getMessage());
        }
    }

    public static function query(string $sql, array $params = []): PDOStatement {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function fetch(string $sql, array $params = []): ?array {
        $stmt = self::query($sql, $params);
        $result = $stmt->fetch();
        return $result ?: null;
    }

    public static function fetchAll(string $sql, array $params = []): array {
        $stmt = self::query($sql, $params);
        return $stmt->fetchAll();
    }

    public static function insert(string $table, array $data): int {
        $pdo = self::getInstance();
        $columns = implode('`, `', array_keys($data));
        $placeholders = implode(', ', array_fill(0, count($data), '?'));
        $sql = "INSERT INTO `$table` (`$columns`) VALUES ($placeholders)";
        $stmt = $pdo->prepare($sql);
        $stmt->execute(array_values($data));
        return (int) $pdo->lastInsertId();
    }

    public static function update(string $table, array $data, string $where, array $whereParams = []): int {
        $sets = [];
        foreach (array_keys($data) as $col) {
            $sets[] = "`$col` = ?";
        }
        $sql = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
        $stmt = self::query($sql, array_merge(array_values($data), $whereParams));
        return $stmt->rowCount();
    }

    public static function delete(string $table, string $where, array $params = []): int {
        $sql = "DELETE FROM `$table` WHERE $where";
        $stmt = self::query($sql, $params);
        return $stmt->rowCount();
    }

    /** Cheap reachability check for boot-time resilience. */
    public static function available(): bool {
        try {
            self::getInstance()->query('SELECT 1');
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    /** Bounded boot-time wait; a briefly unavailable database is not fatal. */
    public static function await(int $attempts, int $delaySeconds): bool {
        $attempts = max(1, $attempts);
        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            if (self::available()) {
                return true;
            }
            if ($attempt < $attempts && $delaySeconds > 0) {
                sleep($delaySeconds);
            }
        }
        return false;
    }
}

