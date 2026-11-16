<?php
/** Real adapters on an isolated fixture; native execution requires the standard latra_test_* reset guard. */
require __DIR__ . '/bootstrap.php';

class SecurityTestPDO extends LocalTestPDO {
    public function exec(string $statement): int|false {
        $statement = preg_replace('/\b(?:INT|BIGINT) PRIMARY KEY AUTO_INCREMENT\b/', 'INTEGER PRIMARY KEY', $statement);
        $statement = preg_replace('/,\s*INDEX\s+\w+\s*\([^)]*\)/', '', $statement);
        $statement = str_replace([' ENGINE=InnoDB',' ON UPDATE CURRENT_TIMESTAMP'], '', $statement);
        return parent::exec($statement);
    }
    public function prepare(string $query, array $options = []): PDOStatement|false {
        $query = str_replace('ON DUPLICATE KEY UPDATE cnt = cnt + 1', 'ON CONFLICT(rate_key, window_start) DO UPDATE SET cnt = cnt + 1', $query);
        $query = str_replace('ON DUPLICATE KEY UPDATE ciphertext = VALUES(ciphertext)', 'ON CONFLICT(vault_key) DO UPDATE SET ciphertext = excluded.ciphertext', $query);
        return parent::prepare($query, $options);
    }
}
$pdo = getenv('LATRA_TEST_DSN') ? testDatabase() : new SecurityTestPDO();
foreach (['app_users','audit_log','rate_limits','vault'] as $table) $pdo->exec("DROP TABLE IF EXISTS $table");
$auth = new Latra\Infrastructure\Persistence\PdoAuthRepository($pdo);
$auth->ensure(); $auth->ensure();
// These columns are added by the application's existing schema bootstrap/migrations.
$pdo->exec('ALTER TABLE audit_log ADD COLUMN before_json TEXT NULL');
$pdo->exec('ALTER TABLE audit_log ADD COLUMN after_json TEXT NULL');
$hash = password_hash('synthetic password',PASSWORD_DEFAULT);
$pdo->prepare('INSERT INTO app_users (username,password_hash,display_name,role,is_active) VALUES (?,?,?,?,?)')
    ->execute(['synthetic',$hash,'Synthetic','viewer',1]);
$pdo->prepare('INSERT INTO app_users (username,password_hash,is_active) VALUES (?,?,?)')->execute(['disabled',$hash,0]);
$user = $auth->activeUser('synthetic');
expect($user !== null && $auth->activeUser('disabled') === null && $auth->activeUser("' OR 1=1 --") === null, 'Active-user query filtering/binding changed');
$id = (int)$user['id'];
$auth->failedLogin($id,8,'2030-01-01 00:00:00');
expect((int)$auth->activeUser('synthetic')['failed_logins'] === 8, 'Login lockout state not stored');
$auth->successfulLogin($id,'2026-01-01 00:00:00');
$user = $auth->activeUser('synthetic');
expect((int)$user['failed_logins'] === 0 && $user['locked_until'] === null && $user['last_login_at'] === '2026-01-01 00:00:00', 'Successful login state not reset');
expect($auth->incrementRate('synthetic',100) === 1 && $auth->incrementRate('synthetic',100) === 2
    && $auth->incrementRate('synthetic',200) === 1, 'Rate counter upsert or window isolation changed');
$record = new Latra\Application\Security\RecordAuditEntry($auth,static function(string $error): void { throw new RuntimeException($error); });
$record->record('fixture','user','test',['uid'=>0,'username'=>'system'],'127.0.0.1',['n'=>1],['n'=>2]);
$entry = $pdo->query('SELECT * FROM audit_log')->fetch(PDO::FETCH_ASSOC);
expect($entry['user_id'] === null && $entry['username'] === 'system' && $entry['before_json'] === '{"n":1}' && $entry['after_json'] === '{"n":2}', 'Audit insert shape/binding changed');
$secrets = new Latra\Infrastructure\Persistence\PdoSecretRepository($pdo);
$secrets->ensure(); $secrets->ensure();
$cipher = new Latra\Infrastructure\Security\AesGcmSecretCipher(static fn(): string => str_repeat('34',32));
$vault = new Latra\Application\Security\ManageSecrets($secrets,$cipher,static function(): void { throw new RuntimeException('Unexpected secret read failure'); });
$vault->put('fixture', 'synthetic first'); $vault->put('fixture','synthetic second é');
expect($vault->get('fixture') === 'synthetic second é' && $secrets->has('fixture') && !$secrets->has("' OR 1=1 --"), 'Secret upsert or parameter binding changed');
$pdo->beginTransaction();
$secrets->putCiphertext('fixture',$cipher->encrypt('rolled back'));
$auth->failedLogin($id,9,'2030-01-01 00:00:00');
$pdo->rollBack();
expect($vault->get('fixture') === 'synthetic second é' && (int)$auth->activeUser('synthetic')['failed_logins'] === 0, 'Adapters escaped captured transaction');
echo "PASS auth/vault persistence, schema repeatability, query binding, rate upserts, audit and transaction rollback\n";
echo $pdo instanceof LocalTestPDO ? "SQLite SQL/state checks only; native MySQL/MariaDB unverified by this run.\n" : "Disposable native MySQL/MariaDB SQL/state checks passed.\n";
