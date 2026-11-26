<?php
require_once __DIR__.'/autoload.php';

/** Database connection facade. */
class Database extends \Latra\Infrastructure\Persistence\DatabaseConnection {
    protected static function createConnection(): PDO {
        if (!defined('DB_HOST') || !defined('DB_NAME') || !defined('DB_USER') || !defined('DB_PASS') || !defined('DB_CHARSET')) {
            throw new LogicException('Database configuration is not initialized');
        }
        return (new \Latra\Infrastructure\Persistence\MysqlConnectionFactory(
            DB_HOST,DB_NAME,DB_USER,DB_PASS,DB_CHARSET, defined('DB_PORT') ? (string) DB_PORT : ''
        ))->connect();
    }
}
