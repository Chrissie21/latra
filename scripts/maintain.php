#!/usr/bin/env php
<?php
/**
 * Database retention. Safe to run daily from cron (also invoked by cleanup.sh).
 *
 * Rules:
 *   - Streaming data (fetched from Wialon and posted to LATRA) is pruned after
 *     MAINTAIN_STREAM_DAYS (default 14). Constant/reference data is never touched.
 *   - Pending/failed/unresolved rows are never deleted (retry-queue integrity).
 *   - Operational history has its own windows (all overridable via env):
 *       MAINTAIN_STREAM_DAYS  message_log(sent)/push_log/wialon_events/outbox(sent)/webhook_events/rate_limits  (14)
 *       MAINTAIN_RUN_DAYS     automation_runs + job_runs (60)
 *       MAINTAIN_AUDIT_DAYS   audit_log (365)
 *       MAINTAIN_AI_DAYS      ai_conversations + ai_chat_log (180)
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/Database.php';

if (PHP_SAPI !== 'cli' && empty($_GET['key'])) {
    http_response_code(403);
    exit("Forbidden");
}

if (!Database::available()) {
    fwrite(STDERR, "maintain: database unavailable; skipping DB retention.\n");
    exit(0);
}

$streamDays = (int) envValue('MAINTAIN_STREAM_DAYS', '14');
$runDays    = (int) envValue('MAINTAIN_RUN_DAYS', '60');
$auditDays  = (int) envValue('MAINTAIN_AUDIT_DAYS', '365');
$aiDays     = (int) envValue('MAINTAIN_AI_DAYS', '180');

$t = [];

// Streaming: sent/acked rows pushed to LATRA older than the stream window.
$t['message_log (sent, ' . $streamDays . 'd) => '] = Database::query(
    "DELETE FROM message_log
     WHERE latra_status = 'sent' AND pushed_at IS NOT NULL
       AND pushed_at < NOW() - INTERVAL ? DAY",
    [$streamDays]
)->rowCount();

$t['push_log (' . $streamDays . 'd) => '] = Database::query(
    'DELETE FROM push_log WHERE created_at < NOW() - INTERVAL ? DAY',
    [$streamDays]
)->rowCount();

$t['wialon_events (' . $streamDays . 'd) => '] = Database::query(
    'DELETE FROM wialon_events WHERE created_at < NOW() - INTERVAL ? DAY',
    [$streamDays]
)->rowCount();

// Outbox: only delivered rows; pending/failed/manual are never removed.
$t['outbox (sent, ' . $streamDays . 'd) => '] = Database::query(
    "DELETE FROM outbox WHERE status = 'sent' AND created_at < NOW() - INTERVAL ? DAY",
    [$streamDays]
)->rowCount();

$t['webhook_events (' . $streamDays . 'd) => '] = Database::query(
    'DELETE FROM webhook_events WHERE created_at < NOW() - INTERVAL ? DAY',
    [$streamDays]
)->rowCount();

// Rate-limit windows store epoch seconds (BIGINT).
$t['rate_limits (' . $streamDays . 'd) => '] = Database::query(
    'DELETE FROM rate_limits WHERE window_start < ?',
    [time() - $streamDays * 86400]
)->rowCount();

// Operational history.
$t['automation_runs (' . $runDays . 'd) => '] = Database::query(
    'DELETE FROM automation_runs WHERE started_at < NOW() - INTERVAL ? DAY',
    [$runDays]
)->rowCount();

$t['job_runs (' . $runDays . 'd) => '] = Database::query(
    'DELETE FROM job_runs WHERE started_at < NOW() - INTERVAL ? DAY',
    [$runDays]
)->rowCount();

$t['audit_log (' . $auditDays . 'd) => '] = Database::query(
    'DELETE FROM audit_log WHERE created_at < NOW() - INTERVAL ? DAY',
    [$auditDays]
)->rowCount();

// AI conversation history (chat log rows first, then conversations).
$t['ai_chat_log (' . $aiDays . 'd) => '] = Database::query(
    'DELETE FROM ai_chat_log WHERE created_at < NOW() - INTERVAL ? DAY',
    [$aiDays]
)->rowCount();

$t['ai_conversations (' . $aiDays . 'd) => '] = Database::query(
    'DELETE FROM ai_conversations WHERE updated_at < NOW() - INTERVAL ? DAY',
    [$aiDays]
)->rowCount();

$summary = 'maintain: ' . implode(' | ', array_map(
    static fn(string $k, $v) => $k . $v,
    array_keys($t),
    array_values($t)
));
echo $summary . PHP_EOL;