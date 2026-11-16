<?php
namespace Latra\Application\AI;

use Closure;
use Exception;
use Throwable;
use Latra\Domain\AI\ApprovedToolCatalog;
use Latra\Domain\AI\OperationalToolPolicy;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

/** Approved operational reads: bounded inputs, explicit read ports, no SQL, paths or provider credentials. */
final class ReadOperationalTools implements AssistantTools
{
    private Closure $now;
    public function __construct(private AssistantReadRepository $repository,private AssistantRuntime $runtime,
        private AssistantLogReader $logs,private string $timezone,private string $format,callable $now)
    { $this->now=Closure::fromCallable($now); }
    public function schemas(): array { return ApprovedToolCatalog::schemas(); }
    private function timeText(mixed $value): string { return OperationalToolPolicy::timeText($value,$this->timezone,$this->format); }
    public function execute(string $name, array $args): array {
        $method = 'tool_' . str_replace('-', '_', $name);
        if (!method_exists(self::class, $method)) {
            return ['ok' => false, 'error' => "Unknown tool: $name"];
        }
        try {
            $result = $this->$method($args);
            return ['ok' => true, 'data' => $result];
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => 'Tool failed: ' . $e->getMessage()];
        }
    }

    private static function intArg(array $args, string $key, int $default = 0): int {
        $v = $args[$key] ?? $default;
        return is_numeric($v) ? (int) $v : $default;
    }

    private static function clamp(int $v, int $min, int $max): int {
        return max($min, min($max, $v));
    }

    private function vehicle(int $id): ?array {
        return $this->repository->one(AssistantReadQuery::Vehicle1, [$id]);
    }

    private function tool_search_vehicle(array $args): array {
        $term = trim((string) ($args['term'] ?? ''));
        if ($term === '') {
            return [];
        }
        $like = '%' . $term . '%';
        return array_map(function ($r) {
            return [
                'vehicle_id' => (int) $r['id'],
                'vehicle_reg_no' => $r['vehicle_reg_no'],
                'name' => $r['name'] ?? '',
                'imei' => $r['imei'] ?? '',
                'is_active' => (bool) $r['is_active'],
            ];
        }, $this->repository->many(AssistantReadQuery::SearchVehicle1, [$like, $like, $like]));
    }

    private function tool_get_vehicle_details(array $args): array {
        $vehicle = $this->vehicle(self::intArg($args, 'vehicle_id'));
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $mappings = $this->repository->many(AssistantReadQuery::GetVehicleDetails1, [(int) $vehicle['id']]);
        return [
            'vehicle_id' => (int) $vehicle['id'],
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'name' => $vehicle['name'] ?? '',
            'imei' => $vehicle['imei'] ?? '',
            'hw_type' => $vehicle['hw_type'] ?? '',
            'phone' => $vehicle['phone'] ?? '',
            'wialon_unit_id' => $vehicle['wialon_unit_id'] ?? null,
            'is_active' => (bool) $vehicle['is_active'],
            'last_sync_at' => $this->timeText($vehicle['last_sync_at']),
            'mapped_activities' => array_map(function ($m) {
                return ['activity_id' => (int) $m['id'], 'name' => $m['activity_name'], 'enabled' => (bool) $m['is_enabled'], 'pushes' => (int) ($m['push_count'] ?? 0), 'failures' => (int) ($m['fail_count'] ?? 0)];
            }, $mappings),
        ];
    }

    private function tool_get_vehicle_status(array $args): array {
        $vehicle = $this->vehicle(self::intArg($args, 'vehicle_id'));
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $dev = $this->repository->one(AssistantReadQuery::GetVehicleStatus1, [(int) $vehicle['id']]);
        return [
            'vehicle_id' => (int) $vehicle['id'],
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'is_active' => (bool) $vehicle['is_active'],
            'is_online' => $dev ? (bool) $dev['is_online'] : false,
            'last_comm_at' => $dev ? $this->timeText($dev['last_comm_at']) : 'n/a',
            'internal_battery_low' => $dev ? (bool) $dev['internal_battery_low'] : false,
            'internal_battery_pct' => $dev ? (isset($dev['internal_battery_pct']) ? (float) $dev['internal_battery_pct'] : null) : null,
            'external_power_disconnected' => $dev ? (bool) $dev['external_power_disconnected'] : false,
            'tampered' => $dev ? (bool) $dev['tampered'] : false,
        ];
    }

    private function tool_get_vehicle_location(array $args): array {
        $vehicle = $this->vehicle(self::intArg($args, 'vehicle_id'));
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $full = $this->repository->one(AssistantReadQuery::GetVehicleLocation1, [(int) $vehicle['id']]);
        $lastMsg = $this->repository->one(AssistantReadQuery::GetVehicleLocation2, [(int) $vehicle['id']]);
        return [
            'vehicle_id' => (int) $vehicle['id'],
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'latitude' => $full && $full['lat'] !== null ? (float) $full['lat'] : null,
            'longitude' => $full && $full['lng'] !== null ? (float) $full['lng'] : null,
            'altitude_m' => $full && $full['altitude'] !== null ? (int) $full['altitude'] : null,
            'speed_kmh' => $full && $full['speed'] !== null ? (float) $full['speed'] : null,
            'bearing' => $full && $full['bearing'] !== null ? (int) $full['bearing'] : null,
            'satellite_count' => $full && $full['satellite_count'] !== null ? (int) $full['satellite_count'] : null,
            'hdop' => $full && $full['hdop'] !== null ? (float) $full['hdop'] : null,
            'last_message_time' => $full && $full['last_message_time'] ? $this->timeText($full['last_message_time']) : 'n/a',
            'age_seconds' => $full && $full['last_message_time'] ? max(0, ($this->now)() - (int) $full['last_message_time']) : null,
        ];
    }

    private function tool_get_vehicle_events(array $args): array {
        $vehicle = $this->vehicle(self::intArg($args, 'vehicle_id'));
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $days = self::clamp(self::intArg($args, 'days', 1), 1, 7);
        $from = ($this->now)() - $days * 86400;
        $counts = $this->repository->many(AssistantReadQuery::GetVehicleEvents1, [(int) $vehicle['id'], $from, LatraPayloadPolicy::ACTIVITY_MOVEMENT]);
        $recent = $this->repository->many(AssistantReadQuery::GetVehicleEvents2, [(int) $vehicle['id'], $from, LatraPayloadPolicy::ACTIVITY_MOVEMENT]);
        return [
            'vehicle_id' => (int) $vehicle['id'],
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'days' => $days,
            'event_type_counts' => array_map(function ($r) {
                return ['activity_id' => (int) $r['activity_id'], 'activity_name' => LatraPayloadPolicy::getActivityName((int) $r['activity_id']), 'count' => (int) $r['c']];
            }, $counts),
            'recent_events' => array_map(function ($r) {
                return ['time' => $this->timeText($r['wialon_msg_time']), 'activity_id' => (int) $r['activity_id'], 'activity_name' => LatraPayloadPolicy::getActivityName((int) $r['activity_id']), 'speed_kmh' => $r['speed'] !== null ? (float) $r['speed'] : null];
            }, $recent),
        ];
    }

    private function tool_get_vehicle_submission_status(array $args): array {
        $vehicle = $this->vehicle(self::intArg($args, 'vehicle_id'));
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $last = $this->repository->one(AssistantReadQuery::GetVehicleSubmissionStatus1, [(int) $vehicle['id']]);
        if (!$last) {
            $pending = $this->repository->one(AssistantReadQuery::GetVehicleSubmissionStatus2, [(int) $vehicle['id']]);
            return ['vehicle_id' => (int) $vehicle['id'], 'vehicle_reg_no' => $vehicle['vehicle_reg_no'], 'no_submissions' => true, 'pending_messages' => (int) ($pending['c'] ?? 0)];
        }
        $recent = $this->repository->many(AssistantReadQuery::GetVehicleSubmissionStatus3, [(int) $vehicle['id']]);
        return [
            'vehicle_id' => (int) $vehicle['id'],
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'activity_id' => (int) $last['activity_id'],
            'activity_name' => $last['activity_name'] ?? LatraPayloadPolicy::getActivityName((int) $last['activity_id']),
            'attempted_at' => $this->timeText($last['created_at']),
            'http_status' => (int) $last['http_code'],
            'status' => $last['response_status'] ?? null,
            'attempt' => (int) ($last['attempt'] ?? 1),
            'latency_ms' => (int) ($last['latency_ms'] ?? 0),
            'response_body_preview' => $last['response_body'] !== null ? mb_substr((string) $last['response_body'], 0, 300) : '',
            'attempts_last_24h' => array_map(function ($r) {
                return ['http_status' => (int) $r['http_code'], 'count' => (int) $r['c']];
            }, $recent),
        ];
    }

    private function tool_get_recent_latra_submissions(array $args): array {
        $vid = self::intArg($args, 'vehicle_id');
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 50);
        $params = $vid > 0 ? [$vid, $limit] : [$limit];
        $rows = $this->repository->many(AssistantReadQuery::GetRecentLatraSubmissions1, $params, $vid > 0);
        return array_map(function ($r) {
            return [
                'vehicle_id' => (int) $r['vehicle_id'],
                'vehicle_reg_no' => $r['vehicle_reg_no'],
                'activity_id' => (int) $r['activity_id'],
                'activity_name' => $r['activity_name'] ?? LatraPayloadPolicy::getActivityName((int) $r['activity_id']),
                'http_status' => (int) $r['http_code'],
                'submitted_at' => $this->timeText($r['created_at']),
            ];
        }, $rows);
    }

    private function tool_get_failed_latra_submissions(array $args): array {
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $grouped = $this->repository->many(AssistantReadQuery::GetFailedLatraSubmissions1, [$hours]);
        $byVehicle = $this->repository->many(AssistantReadQuery::GetFailedLatraSubmissions2, [$hours]);
        return [
            'hours' => $hours,
            'total_failures' => array_sum(array_column($grouped, 'c')),
            'by_http_status' => array_map(function ($r) {
                return ['http_status' => (int) $r['http_code'], 'count' => (int) $r['c']];
            }, $grouped),
            'worst_vehicles' => array_map(function ($r) {
                return ['vehicle_id' => (int) $r['vehicle_id'], 'vehicle_reg_no' => $r['vehicle_reg_no'], 'failures' => (int) $r['c']];
            }, $byVehicle),
        ];
    }

    private function tool_get_latra_submission_statistics(array $args): array {
        $days = self::clamp(self::intArg($args, 'days', 1), 1, 30);
        $totals = $this->repository->one(AssistantReadQuery::GetLatraSubmissionStatistics1, [$days]);
        $pending = $this->repository->one(AssistantReadQuery::GetLatraSubmissionStatistics2, []);
        $rejected = $this->repository->one(AssistantReadQuery::GetLatraSubmissionStatistics3, []);
        return [
            'days' => $days,
            'total_submissions' => (int) ($totals['total'] ?? 0),
            'success' => (int) ($totals['success'] ?? 0),
            'failed_http' => (int) ($totals['failed'] ?? 0),
            'unreachable' => (int) ($totals['down'] ?? 0),
            'avg_latency_ms' => round((float) ($totals['avg_latency_ms'] ?? 0), 1),
            'pending_in_queue' => (int) ($pending['c'] ?? 0),
            'rejected_in_queue' => (int) ($rejected['c'] ?? 0),
        ];
    }

    private function tool_get_activity_submission_status(array $args): array {
        $vid = self::intArg($args, 'vehicle_id');
        $aid = self::intArg($args, 'activity_id');
        $vehicle = $this->vehicle($vid);
        if (!$vehicle) {
            throw new Exception('Vehicle not found');
        }
        $rows = $this->repository->many(AssistantReadQuery::GetActivitySubmissionStatus1, [$vid, $aid]);
        return [
            'vehicle_id' => $vid,
            'vehicle_reg_no' => $vehicle['vehicle_reg_no'],
            'activity_id' => $aid,
            'activity_name' => LatraPayloadPolicy::getActivityName($aid),
            'recent_attempts' => array_map(function ($r) {
                return ['at' => $this->timeText($r['created_at']), 'http_status' => (int) $r['http_code'], 'status' => $r['response_status'] ?? null];
            }, $rows),
        ];
    }

    private function tool_get_recent_events(array $args): array {
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $aid = self::intArg($args, 'activity_id');
        $limit = self::clamp(self::intArg($args, 'limit', 30), 1, 50);
        $params = [LatraPayloadPolicy::ACTIVITY_MOVEMENT, ($this->now)() - $hours * 3600];
        if ($aid > 0) {
            $params[] = $aid;
        }
        $params[] = $limit;
        $rows = $this->repository->many(AssistantReadQuery::GetRecentEvents1, $params, $aid > 0);
        return [
            'hours' => $hours,
            'events' => array_map(function ($r) {
                return [
                    'vehicle_id' => (int) $r['vehicle_id'],
                    'vehicle_reg_no' => $r['vehicle_reg_no'],
                    'activity_id' => (int) $r['activity_id'],
                    'activity_name' => $r['activity_name'] ?? LatraPayloadPolicy::getActivityName((int) $r['activity_id']),
                    'time' => $this->timeText($r['wialon_msg_time']),
                    'speed_kmh' => $r['speed'] !== null ? (float) $r['speed'] : null,
                ];
            }, $rows),
        ];
    }

    private function tool_get_event_statistics(array $args): array {
        $days = self::clamp(self::intArg($args, 'days', 1), 1, 30);
        $rows = $this->repository->many(AssistantReadQuery::GetEventStatistics1, [($this->now)() - $days * 86400, LatraPayloadPolicy::ACTIVITY_MOVEMENT]);
        return array_map(function ($r) {
            return ['activity_id' => (int) $r['activity_id'], 'activity_name' => LatraPayloadPolicy::getActivityName((int) $r['activity_id']), 'count' => (int) $r['c']];
        }, $rows);
    }

    private function tool_get_emergency_events(array $args): array {
        $hours = self::clamp(self::intArg($args, 'hours', 168), 1, 168);
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 50);
        $rows = $this->repository->many(AssistantReadQuery::GetEmergencyEvents1, [$hours, $limit]);
        return array_map(function ($r) {
            return [
                'emergency_event_id' => (int) $r['id'],
                'vehicle_id' => (int) $r['vehicle_id'],
                'vehicle_reg_no' => $r['vehicle_reg_no'],
                'event_type' => $r['event_type'] ?? '',
                'activity_id' => (int) $r['activity_id'],
                'status' => $r['status'] ?? '',
                'started_at' => $this->timeText($r['started_at']),
                'ended_at' => $this->timeText($r['ended_at']),
                'trigger_reason' => $r['trigger_reason'] ?? '',
                'record_count' => (int) $r['record_count'],
            ];
        }, $rows);
    }

    private function tool_get_scheduler_status(array $args): array {
        $enabled = $this->runtime->config('scheduler_enabled', '1') === '1';
        $jobs = $this->repository->many(AssistantReadQuery::GetSchedulerStatus1, []);
        $stuck = $this->repository->one(AssistantReadQuery::GetSchedulerStatus2, []);
        return [
            'scheduler_enabled' => $enabled,
            'jobs' => array_map(function ($j) {
                return [
                    'job_key' => $j['job_key'],
                    'label' => $j['label'],
                    'is_enabled' => (bool) $j['is_enabled'],
                    'interval_seconds' => (int) $j['interval_seconds'],
                    'last_run_at' => $this->timeText($j['last_run_at']),
                    'next_run_at' => $this->timeText($j['next_run_at']),
                    'locked_until' => $this->timeText($j['locked_until']),
                    'last_status' => $j['last_status'] ?? '',
                    'last_error' => $j['last_error'] ?? '',
                    'run_count' => (int) $j['run_count'],
                ];
            }, $jobs),
            'running_examples' => $stuck ? ['stuck' => (int) $stuck['c'], 'job_key' => $stuck['job_key']] : [],
        ];
    }

    private function tool_get_scheduler_runs(array $args): array {
        $target = trim((string) ($args['job_key'] ?? ''));
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 50);
        $params = [];
        if ($target !== '') {
            $params[] = $target;
        }
        $params[] = $limit;
        $rows = $this->repository->many(AssistantReadQuery::GetSchedulerRuns1, $params, $target !== '');
        return array_map(function ($r) {
            return [
                'job_key' => $r['job_key'],
                'status' => $r['status'],
                'started_at' => $this->timeText($r['started_at']),
                'finished_at' => $this->timeText($r['finished_at']),
                'duration_ms' => (int) $r['duration_ms'],
                'items_processed' => (int) $r['items_processed'],
                'error' => mb_substr((string) ($r['error'] ?? ''), 0, 300),
            ];
        }, $rows);
    }

    private function tool_get_failed_scheduler_runs(array $args): array {
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $rows = $this->repository->many(AssistantReadQuery::GetFailedSchedulerRuns1, [$hours]);
        $recent = $this->repository->many(AssistantReadQuery::GetFailedSchedulerRuns2, [$hours]);
        return [
            'hours' => $hours,
            'failed_by_job' => array_map(function ($r) {
                return ['job_key' => $r['job_key'], 'failures' => (int) $r['c']];
            }, $rows),
            'recent_failures' => array_map(function ($r) {
                return ['job_key' => $r['job_key'], 'started_at' => $this->timeText($r['started_at']), 'error' => mb_substr((string) ($r['error'] ?? ''), 0, 300)];
            }, $recent),
        ];
    }

    private function tool_get_worker_status(array $args): array {
        $running = $this->repository->one(AssistantReadQuery::GetWorkerStatus1, []);
        $stale = $this->repository->one(AssistantReadQuery::GetWorkerStatus2, []);
        $orphaned = $this->repository->many(AssistantReadQuery::GetWorkerStatus3, []);
        return [
            'active_runs' => (int) ($running['c'] ?? 0),
            'stale_running' => (int) ($stale['c'] ?? 0),
            'scheduler_enabled' => $this->runtime->config('scheduler_enabled', '1') === '1',
            'stale_runs' => array_map(function ($r) {
                return ['job_key' => $r['job_key'], 'started_at' => $this->timeText($r['started_at'])];
            }, $orphaned),
        ];
    }

    private function tool_get_automation_runs(array $args): array {
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 50);
        $rows = $this->repository->many(AssistantReadQuery::GetAutomationRuns1, [$limit]);
        return array_map(function ($r) {
            return [
                'automation_run_id' => (int) $r['id'],
                'bucket' => $r['bucket_name'] ?? ('bucket#' . ($r['bucket_id'] ?? '?')),
                'status' => $r['status'],
                'started_at' => $this->timeText($r['started_at']),
                'finished_at' => $this->timeText($r['finished_at']),
                'total' => (int) $r['total_count'],
                'success' => (int) $r['success_count'],
                'failed' => (int) $r['failed_count'],
                'triggered_by' => $r['triggered_by'] ?? '',
                'error' => mb_substr((string) ($r['error_message'] ?? ''), 0, 300),
            ];
        }, $rows);
    }

    private function tool_get_failed_automation_runs(array $args): array {
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $rows = $this->repository->many(AssistantReadQuery::GetFailedAutomationRuns1, [$hours]);
        $recent = $this->repository->many(AssistantReadQuery::GetFailedAutomationRuns2, [$hours]);
        return [
            'hours' => $hours,
            'counts' => array_map(function ($r) {
                return ['status' => $r['status'], 'count' => (int) $r['c']];
            }, $rows),
            'recent' => array_map(function ($r) {
                return ['automation_run_id' => (int) $r['id'], 'bucket' => $r['bucket_name'] ?? '', 'status' => $r['status'], 'started_at' => $this->timeText($r['started_at']), 'failed' => (int) $r['failed_count'], 'error' => mb_substr((string) ($r['error_message'] ?? ''), 0, 300)];
            }, $recent),
        ];
    }

    private function tool_get_running_automation_runs(array $args): array {
        $rows = $this->repository->many(AssistantReadQuery::GetRunningAutomationRuns1, []);
        return array_map(function ($r) {
            return ['automation_run_id' => (int) $r['id'], 'bucket' => $r['bucket_name'] ?? '', 'status' => $r['status'], 'started_at' => $this->timeText($r['started_at']), 'total' => (int) $r['total_count'], 'success' => (int) $r['success_count'], 'failed' => (int) $r['failed_count']];
        }, $rows);
    }

    private function tool_get_automation_statistics(array $args): array {
        $days = self::clamp(self::intArg($args, 'days', 1), 1, 30);
        $row = $this->repository->one(AssistantReadQuery::GetAutomationStatistics1, [$days]);
        return [
            'days' => $days,
            'total_runs' => (int) ($row['total'] ?? 0),
            'success_runs' => (int) ($row['success'] ?? 0),
            'failed_runs' => (int) ($row['failed'] ?? 0),
            'running_runs' => (int) ($row['running'] ?? 0),
            'orphaned_runs' => (int) ($row['orphaned'] ?? 0),
            'vehicles_processed' => (int) ($row['vehicles'] ?? 0),
            'submissions_succeeded' => (int) ($row['submissions_ok'] ?? 0),
            'submissions_failed' => (int) ($row['submissions_failed'] ?? 0),
        ];
    }

    private function tool_get_system_health(array $args): array {
        $dbOk = false;
        try {
            $this->repository->one(AssistantReadQuery::GetSystemHealth1, []);
            $dbOk = true;
        } catch (Throwable $e) {
            $dbOk = false;
        }
        $lastSync = $this->repository->one(AssistantReadQuery::GetSystemHealth2, []);
        $lastPushAll = $this->repository->one(AssistantReadQuery::GetSystemHealth3, []);
        $forwarded = $this->runtime->config('latra_ip_allowlist', '');
        $device = $this->runtime->deviceSummary();
        $failedToday = $this->repository->one(AssistantReadQuery::GetSystemHealth4, []);
        $queued = $this->repository->one(AssistantReadQuery::GetSystemHealth5, []);
        return [
            'database_ok' => $dbOk,
            'wialon' => [
                'last_successful_sync' => $this->timeText($lastSync['last'] ?? null),
                'configured' => $this->runtime->sourceConfigured('wialon'),
            ],
            'latra' => [
                'configured' => $this->runtime->sourceConfigured('latra'),
                'api_url_allowlist' => $forwarded !== '',
                'last_submission_attempt' => $this->timeText($lastPushAll['last'] ?? null),
            ],
            'scheduler' => [
                'master_enabled' => $this->runtime->config('scheduler_enabled', '1') === '1',
            ],
            'failures' => [
                'failed_submissions_today' => (int) ($failedToday['c'] ?? 0),
                'pending_in_queue' => (int) ($queued['c'] ?? 0),
            ],
            'devices' => $device,
        ];
    }

    private function tool_get_outbox_status(array $args): array {
        $rows = $this->repository->many(AssistantReadQuery::GetOutboxStatus1, []);
        $oldest = $this->repository->one(AssistantReadQuery::GetOutboxStatus2, []);
        return [
            'by_status' => array_map(function ($r) {
                return ['status' => $r['status'], 'count' => (int) $r['c']];
            }, $rows),
            'oldest_pending_age_hours' => $oldest && $oldest['oldest'] ? round((($this->now)() - strtotime($oldest['oldest'])) / 3600, 1) : null,
        ];
    }

    private function tool_get_error_logs(array $args): array {
        $limit = self::clamp(self::intArg($args, 'limit', 40), 1, 100);
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $lines = $this->logs->lines($limit, $hours, null);
        return ['log' => 'error.log', 'lines' => $lines];
    }

    private function tool_search_logs(array $args): array {
        $term = trim((string) ($args['term'] ?? ''));
        if ($term === '') {
            return [];
        }
        $limit = self::clamp(self::intArg($args, 'limit', 30), 1, 50);
        $hours = self::clamp(self::intArg($args, 'hours', 24), 1, 168);
        $lines = $this->logs->lines($limit * 4, $hours, $term);
        return ['term' => $term, 'matched_lines' => array_slice($lines, 0, $limit)];
    }

    private function tool_get_gps_health(array $args): array {
        $vid = self::intArg($args, 'vehicle_id');
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 30);
        $minFreq = (int) $this->runtime->config('gps_min_frequency_seconds', '120');
        $maxDelay = (int) $this->runtime->config('gps_max_delay_seconds', '300');
        $offlineAfter = (int) $this->runtime->config('offline_seconds', '900');
        $params = [];
        if ($vid > 0) {
            $params[] = $vid;
        }
        $params[] = $limit;
        $rows = $this->repository->many(AssistantReadQuery::GetGpsHealth1, $params, $vid > 0);
        $now = ($this->now)();
        return [
            'min_expected_frequency_seconds' => $minFreq,
            'max_acceptable_delay_seconds' => $maxDelay,
            'offline_after_seconds' => $offlineAfter,
            'vehicles' => array_map(function ($r) use ($now) {
                $age = $r['last_message_time'] ? max(0, $now - (int) $r['last_message_time']) : null;
                return [
                    'vehicle_id' => (int) $r['id'],
                    'vehicle_reg_no' => $r['vehicle_reg_no'],
                    'last_message_age_seconds' => $age,
                    'stale' => $age !== null && $age > 300,
                    'is_active' => (bool) $r['is_active'],
                    'is_online' => (bool) $r['is_online'],
                ];
            }, $rows),
        ];
    }

    private function tool_get_device_health(array $args): array {
        $vid = self::intArg($args, 'vehicle_id');
        $limit = self::clamp(self::intArg($args, 'limit', 20), 1, 50);
        if ($vid > 0) {
            $rows = $this->repository->many(AssistantReadQuery::GetDeviceHealth1, [$vid]);
        } else {
            $rows = $this->repository->many(AssistantReadQuery::GetDeviceHealth2, [$limit]);
        }
        return [
            'at_risk_devices' => array_map(function ($r) {
                return [
                    'vehicle_id' => (int) $r['vehicle_id'],
                    'vehicle_reg_no' => $r['vehicle_reg_no'],
                    'is_online' => (bool) $r['is_online'],
                    'last_comm_at' => $this->timeText($r['last_comm_at']),
                    'battery_low' => (bool) $r['internal_battery_low'],
                    'battery_pct' => isset($r['internal_battery_pct']) ? (float) $r['internal_battery_pct'] : null,
                    'external_power_disconnected' => (bool) $r['external_power_disconnected'],
                    'tampered' => (bool) $r['tampered'],
                ];
            }, $rows),
        ];
    }

    private function tool_get_compliance_summary(array $args): array {
        $device = $this->runtime->deviceSummary();
        $stale = $this->repository->one(AssistantReadQuery::GetComplianceSummary1, [($this->now)() - 600]);
        $failed = $this->repository->one(AssistantReadQuery::GetComplianceSummary2, []);
        $lowBattery = $this->repository->one(AssistantReadQuery::GetComplianceSummary3, []);
        $powerOff = $this->repository->one(AssistantReadQuery::GetComplianceSummary4, []);
        return [
            'gps_min_frequency_seconds' => (int) $this->runtime->config('gps_min_frequency_seconds', '120'),
            'gps_max_delay_seconds' => (int) $this->runtime->config('gps_max_delay_seconds', '300'),
            'offline_seconds' => (int) $this->runtime->config('offline_seconds', '900'),
            'battery_low_percent' => (int) $this->runtime->config('battery_low_pct', '20'),
            'devices' => $device,
            'active_vehicles_stale_10min' => (int) ($stale['c'] ?? 0),
            'failed_submissions_today' => (int) ($failed['c'] ?? 0),
            'devices_battery_low' => (int) ($lowBattery['c'] ?? 0),
            'devices_external_power_off' => (int) ($powerOff['c'] ?? 0),
        ];
    }
}
