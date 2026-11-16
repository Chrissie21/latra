<?php
/** Run deterministic retention, cleanup and export regressions without external services. */
require __DIR__ . '/bootstrap.php';
$pdo = testDatabase();
fixture($pdo);

// Cleanup preserves pending/failed dependencies, recent history and failed evidence.
$old = '2000-01-01 00:00:00';
for ($i = 1; $i <= 5; $i++) {
    [, , $id] = queueMessage($i);
    Database::query("UPDATE message_log SET latra_status='sent', pushed_at=? WHERE id=?", [$old, $i]);
    Database::query("UPDATE outbox SET status='sent',updated_at=?,created_at=? WHERE id=?", [$old, $old, $id]);
}
$pdo->exec("UPDATE outbox SET status='pending' WHERE id=1");
$pdo->exec("UPDATE outbox SET status='failed' WHERE id=3");
$pdo->exec("INSERT INTO wialon_events VALUES (1,4,'failed','2000-01-01 00:00:00')");
Database::query('UPDATE message_log SET pushed_at=? WHERE id=5', [gmdate(DATE_FORMAT)]);
$pdo->exec("INSERT INTO emergency_queue VALUES (1,'pending','2000-01-01 00:00:00'),(2,'sent','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO push_log (id,message_log_id,response_status,created_at) VALUES
    (1,1,'1','2000-01-01 00:00:00'),(2,2,'0','2000-01-01 00:00:00'),(3,2,'1','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO push_log (id,message_log_id,response_status,created_at) VALUES (4,2,'1',CURRENT_TIMESTAMP)");
$pdo->exec("INSERT INTO automation_runs VALUES (1,'completed','2000-01-01 00:00:00','2000-01-01 00:00:00'),(2,'completed','2000-01-01 00:00:00','2000-01-01 00:00:00')");
$pdo->exec("INSERT INTO automation_run_items VALUES
    (1,1,2,'sent','2000-01-01 00:00:00'),(2,2,1,'pending','2000-01-01 00:00:00')");
for ($i = 0; $i < 8; $i++) Retention::maintain();
$survivors = array_map('intval', $pdo->query('SELECT id FROM message_log ORDER BY id')->fetchAll(PDO::FETCH_COLUMN));
expect($survivors === [1,3,4,5], 'cleanup removed a dependent or recent source');
expect((int) $pdo->query('SELECT COUNT(*) FROM outbox WHERE id=2')->fetchColumn() === 0, 'completed orphan tombstone not pruned');
expect((int) $pdo->query('SELECT COUNT(*) FROM emergency_queue WHERE id=1')->fetchColumn() === 1, 'unresolved emergency lost');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=1')->fetchColumn() === 1, 'unresolved evidence lost');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=2')->fetchColumn() === 0, 'resolved failed attempt ignored the original CLI age window');
expect((int) $pdo->query('SELECT COUNT(*) FROM push_log WHERE id=3')->fetchColumn() === 0, 'old successful receipt not pruned');
expect($pdo->query('SELECT message_log_id FROM push_log WHERE id=4')->fetchColumn() === null,
    'retained receipt did not survive source deletion with SET NULL');
expect((int) $pdo->query('SELECT COUNT(*) FROM automation_runs WHERE id=2')->fetchColumn() === 1,
    'parent cascade erased unresolved run items');
expect((int) $pdo->query('SELECT COUNT(*) FROM automation_runs WHERE id=1')->fetchColumn() === 0,
    'completed parent history was not pruned after its bounded child page');
echo "PASS paged cleanup and unresolved dependency preservation\n";

// A missing table must report failure and roll back rather than claiming successful maintenance.
$pdo->exec('DROP TABLE webhook_events');
$failed = false;
try { Retention::maintain(); } catch (PDOException $e) { $failed = true; }
expect($failed && !$pdo->inTransaction(), 'maintenance failure was hidden or transaction leaked');
echo "PASS maintenance failure visibility\n";

// Keyset pages preserve tied timestamps and filtering without buffering the whole history.
fixture($pdo);
$stmt = $pdo->prepare('INSERT INTO push_log (id,vehicle_id,activity_id,response_status,created_at,response_body) VALUES (?,1,2,?,?,?)');
for ($i = 1; $i <= 1250; $i++) $stmt->execute([$i, $i % 2 ? '1' : '0', '2020-01-01 00:00:00', str_repeat('x', 800)]);
for ($i = 1251; $i <= 1850; $i++) $stmt->execute([$i, '1', null, 'synthetic NULL timestamp']);
$ids = [];
foreach (Export::pushLogRows('sent') as $row) $ids[] = (int) $row[0];
expect(count($ids) === 1225 && count(array_unique($ids)) === 1225, 'paged CSV lost or repeated tied rows');
expect($ids[0] === 1249 && $ids[624] === 1 && end($ids) === 1251, 'CSV cursor order changed across pages');
echo "PASS paged export across timestamp ties\n";

// Hour-based purging must keep its own configured window instead of using retention_days.
fixture($pdo);
queueMessage(1);
$old = gmdate(DATE_FORMAT, time()-36*3600);
Database::query("UPDATE message_log SET latra_status='sent',pushed_at=? WHERE id=1", [$old]);
Database::query("UPDATE outbox SET status='sent',updated_at=? WHERE id=1", [$old]);
$pdo->exec("INSERT INTO config VALUES ('delivered_retention_hours','48','test'),('retention_days','90','test')");
expect(Retention::purgeDelivered() === 0, 'hour-based purge ignored the chosen 48-hour window');
$pdo->exec("UPDATE config SET config_value='24' WHERE config_key='delivered_retention_hours'");
expect(Retention::purgeDelivered() === 2, 'original 24-hour purge did not remove completed source/queue');
echo "PASS original hour-based purge setting\n";

// The CLI must continue honoring MAINTAIN_STREAM_DAYS independently of the scheduler.
fixture($pdo);
queueMessage(1);
$old = gmdate(DATE_FORMAT, time()-16*86400);
Database::query("UPDATE message_log SET latra_status='sent',pushed_at=? WHERE id=1", [$old]);
Database::query("UPDATE outbox SET status='sent',created_at=? WHERE id=1", [$old]);
$previous = getenv('MAINTAIN_STREAM_DAYS');
try {
    putenv('MAINTAIN_STREAM_DAYS=20');
    expect(Retention::maintain() === 0, 'CLI ignored the chosen 20-day window');
    putenv('MAINTAIN_STREAM_DAYS=14');
    expect(Retention::maintain() === 2, 'original CLI 14-day retention changed');
} finally { putenv($previous === false ? 'MAINTAIN_STREAM_DAYS' : 'MAINTAIN_STREAM_DAYS='.$previous); }
echo "PASS original CLI retention environment setting\n";

// Daily archival keeps its 90-day setting and copies rows before deleting them.
fixture($pdo);
queueMessage(1); queueMessage(2); queueMessage(3);
$old = gmdate(DATE_FORMAT, time()-100*86400);
$recent = gmdate(DATE_FORMAT, time()-80*86400);
Database::query("UPDATE message_log SET latra_status='sent',created_at=?,pushed_at=? WHERE id=1", [$old,$old]);
Database::query("UPDATE message_log SET latra_status='sent',created_at=?,pushed_at=? WHERE id=2", [$recent,$recent]);
Database::query('UPDATE message_log SET created_at=? WHERE id=3', [$old]);
$pdo->exec("UPDATE outbox SET status='sent' WHERE id IN (1,2)");
$before = $pdo->query('SELECT * FROM message_log WHERE id=1')->fetch(PDO::FETCH_ASSOC);
expect(Retention::archiveMessages(90) === 1, 'archive did not honor the original 90-day cutoff');
expect($pdo->query('SELECT * FROM message_log_archive WHERE id=1')->fetch(PDO::FETCH_ASSOC) === $before,
    'archive did not preserve the complete source row');
expect((int) $pdo->query('SELECT COUNT(*) FROM message_log WHERE id IN (2,3)')->fetchColumn() === 2,
    'archive removed recent or unresolved source data');
echo "PASS original daily archive window and copy-before-delete\n";

echo $pdo instanceof LocalTestPDO
    ? "Local SQLite SQL/state checks passed; MySQL row locks and plans remain unverified.\n"
    : "Disposable MySQL SQL/state/connection-lock checks passed; production load remains unverified.\n";
