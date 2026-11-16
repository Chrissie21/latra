<?php
if (PHP_SAPI !== 'cli') exit(1);
require __DIR__.'/../includes/autoload.php';
function databaseAssert(bool $ok,string $why): void { if (!$ok) throw new RuntimeException($why); }
databaseAssert(is_subclass_of(Database::class,Latra\Infrastructure\Persistence\DatabaseConnection::class), 'Database compatibility name does not delegate to Infrastructure');
$pdo=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$pdo);
(new ReflectionProperty(Database::class,'sensitiveConfigScrubbed'))->setValue(null,true);
Database::query('CREATE TABLE fixture (id INTEGER PRIMARY KEY,label TEXT)');
$pdo->beginTransaction();$id=Database::insert('fixture',['label'=>'synthetic']);
databaseAssert($id===1 && Database::fetch('SELECT label FROM fixture WHERE id=?',[$id])===['label'=>'synthetic'], 'Captured connection insert/read shape changed');
$pdo->rollBack();
databaseAssert(Database::fetchAll('SELECT * FROM fixture')===[] && Database::available(), 'Database helper escaped rollback or reachability changed');
$failed=new class extends PDO {
    public function __construct() {}
    public function inTransaction(): bool { return true; }
    public function query(string $query,?int $fetchMode=null,mixed ...$fetchModeArgs): PDOStatement|false { throw new PDOException('synthetic connection loss'); }
};
(new ReflectionProperty(Database::class,'instance'))->setValue(null,$failed);
try { Database::getInstance();throw new LogicException('Lost transaction session was replaced'); }
catch (PDOException $error) { databaseAssert($error->getMessage()==='synthetic connection loss','Wrong connection failure'); }
databaseAssert((new ReflectionProperty(Database::class,'instance'))->getValue() === $failed, 'Connection replacement crossed transaction ownership');
echo "PASS database compatibility, captured CRUD/rollback and refusal to reconnect a lost transaction\n";
