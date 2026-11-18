<?php
namespace Latra\Infrastructure\Persistence;

use PDO;

final class MysqlConnectionFactory
{
    public function __construct(private string $host,private string $database,private string $user,
        private string $password,private string $charset,private string $port = '') {}
    public function connect(): PDO
    {
        $dsn='mysql:host='.$this->host
            .($this->port !== '' ? ';port='.$this->port : '')
            .';dbname='.$this->database.';charset='.$this->charset;
        $pdo=new PDO($dsn,
            $this->user,$this->password,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false,
                PDO::ATTR_PERSISTENT=>false]);
        $pdo->exec("SET time_zone = '+00:00'");
        return $pdo;
    }
}
