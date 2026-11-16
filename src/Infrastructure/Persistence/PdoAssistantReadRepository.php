<?php
namespace Latra\Infrastructure\Persistence;

use Closure;
use PDO;
use Latra\Application\AI\AssistantReadQuery;
use Latra\Application\AI\AssistantReadRepository;

final class PdoAssistantReadRepository implements AssistantReadRepository
{
    private Closure $connection;
    public function __construct(callable $connection) { $this->connection=Closure::fromCallable($connection); }
    public function one(AssistantReadQuery $query,array $params=[],bool $filtered=false): ?array {
        return $this->statement($query,$params,$filtered)->fetch(PDO::FETCH_ASSOC) ?: null;
    }
    public function many(AssistantReadQuery $query,array $params=[],bool $filtered=false): array {
        return $this->statement($query,$params,$filtered)->fetchAll(PDO::FETCH_ASSOC);
    }
    private function statement(AssistantReadQuery $query,array $params,bool $filtered): \PDOStatement {
        $condition=match ($query) {
            AssistantReadQuery::GetRecentLatraSubmissions1 => ($filtered ? 'WHERE pl.vehicle_id = ?' : ''),
            AssistantReadQuery::GetRecentEvents1 => 'm.activity_id <> ? AND m.wialon_msg_time >= ?' . ($filtered ? ' AND m.activity_id = ?' : ''),
            AssistantReadQuery::GetSchedulerRuns1 => ($filtered ? 'WHERE job_key = ?' : ''),
            AssistantReadQuery::GetGpsHealth1 => ($filtered ? 'WHERE v.id = ?' : ''),
            default => '',
        };
        $sql=match ($query) {
            AssistantReadQuery::Vehicle1 => "SELECT id, wialon_unit_id, vehicle_reg_no, name, imei, hw_type, phone, is_active, last_sync_at FROM vehicles WHERE id = ?",
            AssistantReadQuery::SearchVehicle1 => "SELECT id, vehicle_reg_no, name, imei, is_active FROM vehicles
             WHERE vehicle_reg_no LIKE ? OR name LIKE ? OR imei LIKE ?
             ORDER BY vehicle_reg_no LIMIT 10",
            AssistantReadQuery::GetVehicleDetails1 => "SELECT ad.id, ad.activity_name, am.is_enabled, am.push_count, am.fail_count
             FROM activity_mappings am JOIN activity_definitions ad ON ad.id = am.activity_id
             WHERE am.vehicle_id = ? ORDER BY ad.id",
            AssistantReadQuery::GetVehicleStatus1 => "SELECT * FROM device_status WHERE vehicle_id = ?",
            AssistantReadQuery::GetVehicleLocation1 => "SELECT lat, lng, altitude, speed, bearing, satellite_count, hdop, last_message_time FROM vehicles WHERE id = ?",
            AssistantReadQuery::GetVehicleLocation2 => "SELECT wialon_msg_time FROM message_log WHERE vehicle_id = ? ORDER BY wialon_msg_time DESC LIMIT 1",
            AssistantReadQuery::GetVehicleEvents1 => "SELECT activity_id, COUNT(*) c FROM message_log
             WHERE vehicle_id = ? AND wialon_msg_time >= ? AND activity_id <> ? GROUP BY activity_id ORDER BY c DESC",
            AssistantReadQuery::GetVehicleEvents2 => "SELECT wialon_msg_time, activity_id, speed, latra_status FROM message_log
             WHERE vehicle_id = ? AND wialon_msg_time >= ? AND activity_id <> ?
             ORDER BY wialon_msg_time DESC LIMIT 15",
            AssistantReadQuery::GetVehicleSubmissionStatus1 => "SELECT pl.*, ad.activity_name FROM push_log pl
             LEFT JOIN activity_definitions ad ON ad.id = pl.activity_id
             WHERE pl.vehicle_id = ? ORDER BY pl.created_at DESC LIMIT 1",
            AssistantReadQuery::GetVehicleSubmissionStatus2 => "SELECT COUNT(*) c FROM message_log WHERE vehicle_id = ? AND latra_status IN ('pending','rejected','failed')",
            AssistantReadQuery::GetVehicleSubmissionStatus3 => "SELECT COUNT(*) c, http_code FROM push_log WHERE vehicle_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) GROUP BY http_code",
            AssistantReadQuery::GetRecentLatraSubmissions1 => "SELECT pl.vehicle_id, v.vehicle_reg_no, pl.activity_id, ad.activity_name, pl.http_code, pl.response_status,
                    pl.latency_ms, pl.created_at, pl.attempt
             FROM push_log pl
             JOIN vehicles v ON v.id = pl.vehicle_id
             LEFT JOIN activity_definitions ad ON ad.id = pl.activity_id
             $condition ORDER BY pl.created_at DESC LIMIT ?",
            AssistantReadQuery::GetFailedLatraSubmissions1 => "SELECT http_code, COUNT(*) c FROM push_log
             WHERE http_code <> 200 AND created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY http_code ORDER BY c DESC LIMIT 10",
            AssistantReadQuery::GetFailedLatraSubmissions2 => "SELECT pl.vehicle_id, v.vehicle_reg_no, COUNT(*) c
             FROM push_log pl JOIN vehicles v ON v.id = pl.vehicle_id
             WHERE pl.http_code <> 200 AND pl.created_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY pl.vehicle_id, v.vehicle_reg_no ORDER BY c DESC LIMIT 15",
            AssistantReadQuery::GetLatraSubmissionStatistics1 => "SELECT COUNT(*) total, SUM(http_code = 200) success, SUM(http_code <> 200 AND http_code <> 0) failed,
                    SUM(http_code = 0) down, AVG(latency_ms) avg_latency_ms
             FROM push_log WHERE created_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            AssistantReadQuery::GetLatraSubmissionStatistics2 => "SELECT COUNT(*) c FROM message_log WHERE latra_status = 'pending'",
            AssistantReadQuery::GetLatraSubmissionStatistics3 => "SELECT COUNT(*) c FROM message_log WHERE latra_status = 'rejected'",
            AssistantReadQuery::GetActivitySubmissionStatus1 => "SELECT http_code, response_status, lat_lng_status, created_at FROM push_log
             WHERE vehicle_id = ? AND activity_id = ? ORDER BY created_at DESC LIMIT 10",
            AssistantReadQuery::GetRecentEvents1 => "SELECT m.vehicle_id, v.vehicle_reg_no, m.activity_id, ad.activity_name, m.wialon_msg_time, m.speed, m.latra_status
             FROM message_log m
             JOIN vehicles v ON v.id = m.vehicle_id
             LEFT JOIN activity_definitions ad ON ad.id = m.activity_id
             WHERE $condition ORDER BY m.wialon_msg_time DESC LIMIT ?",
            AssistantReadQuery::GetEventStatistics1 => "SELECT activity_id, COUNT(*) c FROM message_log
             WHERE wialon_msg_time >= ? AND activity_id <> ? GROUP BY activity_id ORDER BY c DESC",
            AssistantReadQuery::GetEmergencyEvents1 => "SELECT e.id, e.vehicle_id, v.vehicle_reg_no, e.event_type, e.activity_id, e.status, e.started_at, e.ended_at,
                    e.trigger_reason, e.max_speed_kmh, e.record_count
             FROM emergency_events e JOIN vehicles v ON v.id = e.vehicle_id
             WHERE e.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY e.started_at DESC LIMIT ?",
            AssistantReadQuery::GetSchedulerStatus1 => "SELECT job_key, label, interval_seconds, is_enabled, last_run_at, next_run_at, locked_until, last_status, last_error, run_count
             FROM jobs ORDER BY job_key",
            AssistantReadQuery::GetSchedulerStatus2 => "SELECT COUNT(*) c, job_key FROM job_runs WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) GROUP BY job_key",
            AssistantReadQuery::GetSchedulerRuns1 => "SELECT id, job_key, status, started_at, finished_at, duration_ms, items_processed, error
             FROM job_runs $condition ORDER BY started_at DESC LIMIT ?",
            AssistantReadQuery::GetFailedSchedulerRuns1 => "SELECT job_key, COUNT(*) c FROM job_runs
             WHERE status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY job_key ORDER BY c DESC LIMIT 20",
            AssistantReadQuery::GetFailedSchedulerRuns2 => "SELECT id, job_key, started_at, error FROM job_runs
             WHERE status = 'failed' AND started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY started_at DESC LIMIT 10",
            AssistantReadQuery::GetWorkerStatus1 => "SELECT COUNT(*) c FROM job_runs WHERE status = 'running'",
            AssistantReadQuery::GetWorkerStatus2 => "SELECT COUNT(*) c FROM job_runs WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE)",
            AssistantReadQuery::GetWorkerStatus3 => "SELECT id, job_key, started_at FROM job_runs WHERE status = 'running' AND started_at < DATE_SUB(NOW(), INTERVAL 30 MINUTE) LIMIT 10",
            AssistantReadQuery::GetAutomationRuns1 => "SELECT ar.id, ar.bucket_id, b.bucket_name, ar.status, ar.started_at, ar.finished_at, ar.total_count,
                    ar.success_count, ar.failed_count, ar.error_message, ar.triggered_by, ar.duration_seconds
             FROM automation_runs ar LEFT JOIN automation_buckets b ON b.id = ar.bucket_id
             ORDER BY ar.started_at DESC LIMIT ?",
            AssistantReadQuery::GetFailedAutomationRuns1 => "SELECT ar.status, COUNT(*) c FROM automation_runs ar
             WHERE ar.status IN ('failed','completed_failed') AND ar.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             GROUP BY ar.status",
            AssistantReadQuery::GetFailedAutomationRuns2 => "SELECT ar.id, b.bucket_name, ar.status, ar.started_at, ar.failed_count, ar.error_message
             FROM automation_runs ar LEFT JOIN automation_buckets b ON b.id = ar.bucket_id
             WHERE ar.status IN ('failed','completed_failed') AND ar.started_at >= DATE_SUB(NOW(), INTERVAL ? HOUR)
             ORDER BY ar.started_at DESC LIMIT 10",
            AssistantReadQuery::GetRunningAutomationRuns1 => "SELECT ar.id, b.bucket_name, ar.status, ar.started_at, ar.total_count, ar.success_count, ar.failed_count
             FROM automation_runs ar LEFT JOIN automation_buckets b ON b.id = ar.bucket_id
             WHERE ar.status IN ('running','orphaned') ORDER BY ar.started_at DESC LIMIT 20",
            AssistantReadQuery::GetAutomationStatistics1 => "SELECT COUNT(*) total,
                    SUM(status = 'completed') success,
                    SUM(status IN ('failed','completed_failed')) failed,
                    SUM(status = 'running') running,
                    SUM(status = 'orphaned') orphaned,
                    SUM(total_count) vehicles,
                    SUM(success_count) submissions_ok,
                    SUM(failed_count) submissions_failed
             FROM automation_runs WHERE started_at >= DATE_SUB(NOW(), INTERVAL ? DAY)",
            AssistantReadQuery::GetSystemHealth1 => "SELECT 1",
            AssistantReadQuery::GetSystemHealth2 => "SELECT MAX(last_sync_at) last FROM vehicles",
            AssistantReadQuery::GetSystemHealth3 => "SELECT MAX(created_at) last FROM push_log",
            AssistantReadQuery::GetSystemHealth4 => "SELECT COUNT(*) c FROM push_log WHERE http_code <> 200 AND created_at >= CURDATE()",
            AssistantReadQuery::GetSystemHealth5 => "SELECT COUNT(*) c FROM message_log WHERE latra_status = 'pending'",
            AssistantReadQuery::GetOutboxStatus1 => "SELECT status, COUNT(*) c FROM outbox GROUP BY status",
            AssistantReadQuery::GetOutboxStatus2 => "SELECT MIN(created_at) oldest FROM outbox WHERE status = 'pending'",
            AssistantReadQuery::GetGpsHealth1 => "SELECT v.id, v.vehicle_reg_no, v.last_message_time, v.lat, v.lng, v.is_active, d.is_online
             FROM vehicles v LEFT JOIN device_status d ON d.vehicle_id = v.id
             $condition ORDER BY v.last_message_time DESC LIMIT ?",
            AssistantReadQuery::GetDeviceHealth1 => "SELECT d.*, v.vehicle_reg_no FROM device_status d JOIN vehicles v ON v.id = d.vehicle_id WHERE d.vehicle_id = ?",
            AssistantReadQuery::GetDeviceHealth2 => "SELECT d.*, v.vehicle_reg_no FROM device_status d JOIN vehicles v ON v.id = d.vehicle_id
                 WHERE d.is_online = 0 OR d.internal_battery_low = 1 OR d.external_power_disconnected = 1 OR d.tampered = 1
                 ORDER BY d.is_online ASC, d.last_comm_at ASC LIMIT ?",
            AssistantReadQuery::GetComplianceSummary1 => "SELECT COUNT(*) c FROM vehicles WHERE is_active = 1 AND (last_message_time IS NULL OR last_message_time < ?)",
            AssistantReadQuery::GetComplianceSummary2 => "SELECT COUNT(*) c FROM push_log WHERE http_code <> 200 AND created_at >= CURDATE()",
            AssistantReadQuery::GetComplianceSummary3 => "SELECT COUNT(*) c FROM device_status WHERE internal_battery_low = 1",
            AssistantReadQuery::GetComplianceSummary4 => "SELECT COUNT(*) c FROM device_status WHERE external_power_disconnected = 1",
        };
        $stmt=($this->connection)()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
}
