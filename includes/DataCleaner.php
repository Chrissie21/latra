<?php

class DataCleaner {

    /** Generated/log tables, listed in FK-safe order for a full reset. */
    const RESET_ORDER = [
        'message_log',
        'push_log',
        'validation_errors',
        'emergency_queue',
        'emergency_events',
        'device_status',
        'gps_health',
        'vehicle_state',
        'automation_run_items',
        'automation_runs',
        'audit_log',
        'rate_limits',
    ];

    /** Tables never touched by any operation. */
    const PROTECTED = [
        'vehicles', 'activity_definitions', 'activity_mappings',
        'app_users', 'config', 'automation_buckets', 'bucket_vehicles',
        'automation_jobs', 'overspeed_config', 'geofence_definitions',
        'hardware_fault_codes',
    ];

    
    public static function categories(): array {
        return [
            'message_log' => [
                'label'   => 'Submission records (message_log)',
                'filters' => ['vehicle', 'imei', 'activity', 'status', 'from', 'to', 'older_than_days'],
            ],
            'validation_errors' => [
                'label'   => 'Validation errors',
                'filters' => ['vehicle', 'from', 'to', 'older_than_days'],
            ],
            'emergency_events' => [
                'label'   => 'Emergency events',
                'filters' => ['vehicle', 'event_type', 'status', 'from', 'to', 'older_than_days'],
            ],
            'emergency_queue' => [
                'label'   => 'Emergency queue (buffered records)',
                'filters' => ['vehicle', 'event', 'from', 'to'],
            ],
            'device_status' => [
                'label'   => 'Device status',
                'filters' => ['vehicle'],
            ],
            'gps_health' => [
                'label'   => 'GPS frequency health',
                'filters' => ['vehicle'],
            ],
            'push_log' => [
                'label'   => 'LATRA push log',
                'filters' => ['vehicle', 'response_status', 'from', 'to', 'older_than_days'],
            ],
            'audit_log' => [
                'label'   => 'Audit log',
                'filters' => ['older_than_days', 'from', 'to'],
            ],
            'automation_runs' => [
                'label'   => 'Automation runs (and their items)',
                'filters' => ['from', 'to', 'older_than_days'],
            ],
        ];
    }

    
    public static function clear(string $category, array $filters): array {
        $cats = self::categories();
        if (!isset($cats[$category])) {
            throw new InvalidArgumentException("Unknown data category: $category");
        }

        switch ($category) {
            case 'message_log':
                return self::clearMessageLog($filters);
            case 'validation_errors':
                return self::clearTable('validation_errors', 'vehicle_id', self::vehicleIds($filters), 'created_at', $filters);
            case 'emergency_events':
                return self::clearEmergencyEvents($filters);
            case 'emergency_queue':
                return ['deleted' => self::clearQueue($filters)];
            case 'device_status':
                return ['deleted' => self::clearTableRowcount('device_status', 'vehicle_id', self::vehicleIds($filters))];
            case 'gps_health':
                return ['deleted' => self::clearTableRowcount('gps_health', 'vehicle_id', self::vehicleIds($filters))];
            case 'push_log':
                return self::clearTable('push_log', 'vehicle_id', self::vehicleIds($filters), 'created_at', $filters);
            case 'audit_log':
                return ['deleted' => self::clearAuditLog($filters)];
            case 'automation_runs':
                return self::clearAutomationRuns($filters);
        }
        throw new InvalidArgumentException("Unhandled category: $category");
    }


    public static function resetAll(): array {
        $total = 0;
        foreach (self::RESET_ORDER as $table) {
            $total += self::truncateSafe($table);
        }
        // Reset player/derived counters on retained config tables.
        Database::query("UPDATE automation_buckets SET run_count = 0, fail_count = 0, consecutive_failures = 0, last_error = NULL, last_run_at = NULL, next_run_at = NULL");
        Database::query("UPDATE vehicles SET is_active = 1");
        return ['deleted' => $total];
    }

   
    private static function clearMessageLog(array $f): array {
        $conds = [];
        $p = [];
        $ids = self::vehicleIds($f);
        if ($ids !== null) {
            $conditions = implode(', ', array_fill(0, count($ids), '?'));
            $conds[] = "vehicle_id IN ($conditions)";
            $p = array_merge($p, $ids);
        }
        if (($f['activity'] ?? '') !== '') { $conds[] = 'activity_id = ?'; $p[] = (int) $f['activity']; }
        if (($f['status'] ?? '') !== '') { $conds[] = 'latra_status = ?'; $p[] = $f['status']; }
        $sd = self::dateSql('data_processed_at', $f, 'from');
        if ($sd) { $conds[] = $sd[0]; $p = array_merge($p, $sd[1]); }
        $ed = self::dateSql('data_processed_at', $f, 'to');
        if ($ed) { $conds[] = $ed[0]; $p = array_merge($p, $ed[1]); }
        $old = self::olderThanSql('data_processed_at', $f['older_than_days'] ?? '');
        if ($old) { $conds[] = $old[0]; $p = array_merge($p, $old[1]); }

        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $deleted = self::deleteRowcount('message_log', $where, $p);
        return ['deleted' => $deleted];
    }

    private static function clearEmergencyEvents(array $f): array {
        $conds = [];
        $p = [];
        $ids = self::vehicleIds($f);
        if ($ids !== null) {
            $conditions = implode(', ', array_fill(0, count($ids), '?'));
            $conds[] = "vehicle_id IN ($conditions)";
            $p = array_merge($p, $ids);
        }
        if (($f['event_type'] ?? '') !== '') { $conds[] = 'event_type = ?'; $p[] = $f['event_type']; }
        if (($f['status'] ?? '') !== '') { $conds[] = 'status = ?'; $p[] = $f['status']; }
        $sd = self::dateSql('started_at', $f, 'from');
        if ($sd) { $conds[] = $sd[0]; $p = array_merge($p, $sd[1]); }
        $ed = self::dateSql('started_at', $f, 'to');
        if ($ed) { $conds[] = $ed[0]; $p = array_merge($p, $ed[1]); }
        $old = self::olderThanSql('started_at', $f['older_than_days'] ?? '');
        if ($old) { $conds[] = $old[0]; $p = array_merge($p, $old[1]); }

        // Capture event ids before deleting so we can purge their queue rows.
        $evtWhere = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        $eventIds = array_column(Database::fetchAll("SELECT id FROM emergency_events $evtWhere", $p), 'id');
        $queueDeleted = 0;
        if ($eventIds) {
            $in = implode(', ', array_fill(0, count($eventIds), '?'));
            $queueDeleted = Database::query("DELETE FROM emergency_queue WHERE emergency_event_id IN ($in)", $eventIds)->rowCount();
        }
        $deleted = self::deleteRowcount('emergency_events', $evtWhere, $p);
        return ['deleted' => $deleted, 'queue_deleted' => $queueDeleted];
    }

    private static function clearQueue(array $f): int {
        $conds = [];
        $p = [];
        $ids = self::vehicleIds($f);
        if ($ids !== null) {
            $conditions = implode(', ', array_fill(0, count($ids), '?'));
            $conds[] = "vehicle_id IN ($conditions)";
            $p = array_merge($p, $ids);
        }
        if (($f['event'] ?? '') !== '') { $conds[] = 'event_id = ?'; $p[] = $f['event']; }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return self::deleteRowcount('emergency_queue', $where, $p);
    }

    private static function clearAutomationRuns(array $f): array {
        $conds = [];
        $p = [];
        $old = self::olderThanSql('started_at', $f['older_than_days'] ?? '');
        if ($old) { $conds[] = $old[0]; $p = array_merge($p, $old[1]); }
        $sd = self::dateSql('started_at', $f, 'from');
        if ($sd) { $conds[] = $sd[0]; $p = array_merge($p, $sd[1]); }
        $ed = self::dateSql('started_at', $f, 'to');
        if ($ed) { $conds[] = $ed[0]; $p = array_merge($p, $ed[1]); }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        // run_items cascade-delete with runs, so only delete runs.
        $deleted = self::deleteRowcount('automation_runs', $where, $p);
        return ['deleted' => $deleted];
    }

    private static function clearAuditLog(array $f): int {
        $conds = [];
        $p = [];
        $old = self::olderThanSql('created_at', $f['older_than_days'] ?? '');
        if ($old) { $conds[] = $old[0]; $p = array_merge($p, $old[1]); }
        $sd = self::dateSql('created_at', $f, 'from');
        if ($sd) { $conds[] = $sd[0]; $p = array_merge($p, $sd[1]); }
        $ed = self::dateSql('created_at', $f, 'to');
        if ($ed) { $conds[] = $ed[0]; $p = array_merge($p, $ed[1]); }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return self::deleteRowcount('audit_log', $where, $p);
    }

    // ------------------------------------------------------------------
    // Shared helpers
    // ------------------------------------------------------------------

    // Resolve vehicle ids from the filters.
    private static function vehicleIds(array $f): ?array {
        $ids = [];
        $in = [];
        if (($f['vehicle'] ?? '') !== '') {
            $in[] = 'vehicle_reg_no LIKE ?';
            $ids = array_merge($ids, ['%' . $f['vehicle'] . '%']);
        }
        if (($f['imei'] ?? '') !== '') {
            $in[] = 'imei LIKE ?';
            $ids = array_merge($ids, ['%' . $f['imei'] . '%']);
        }
        if (!$in) return null;
        $rows = Database::fetchAll("SELECT id FROM vehicles WHERE " . implode(' OR ', $in), $ids);
        $out = array_column($rows, 'id');
        return $out ?: [0]; // [0] matches nothing, avoiding empty IN()
    }

    // Build a simple date filter.
    private static function dateSql(string $col, array $f, string $bound): ?array {
        $delim = $bound === 'to' ? '23:59:59' : '00:00:00';
        if (($f[$bound] ?? '') === '') return null;
        return [sprintf("%s %s ?", $col, $bound === 'to' ? '<=' : '>='), [trim($f[$bound]) . " $delim"]];
    }

    // Build an age filter.
    private static function olderThanSql(string $col, string $days): ?array {
        $n = (int) $days;
        if ($n <= 0) return null;
        return [$col . ' < NOW() - INTERVAL ? DAY', [$n]];
    }

    // Delete rows and return the count.
    private static function deleteRowcount(string $table, string $where, array $p): int {
        $sql = "DELETE FROM $table $where";
        $stmt = Database::query($sql, $p);
        return $stmt->rowCount();
    }

    /** Generic per-table clear scoped by resolved vehicle ids + date bound. */
    private static function clearTable(string $table, string $vehCol, ?array $ids, string $dateCol, array $f): array {
        $conds = [];
        $p = [];
        if ($ids !== null) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $conds[] = "$vehCol IN ($in)";
            $p = array_merge($p, $ids);
        }
        $sd = self::dateSql($dateCol, $f, 'from');
        if ($sd) { $conds[] = $sd[0]; $p = array_merge($p, $sd[1]); }
        $ed = self::dateSql($dateCol, $f, 'to');
        if ($ed) { $conds[] = $ed[0]; $p = array_merge($p, $ed[1]); }
        $old = self::olderThanSql($dateCol, $f['older_than_days'] ?? '');
        if ($old) { $conds[] = $old[0]; $p = array_merge($p, $old[1]); }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return ['deleted' => self::deleteRowcount($table, $where, $p)];
    }

    private static function clearTableRowcount(string $table, string $vehCol, ?array $ids): int {
        $conds = [];
        $p = [];
        if ($ids !== null) {
            $in = implode(', ', array_fill(0, count($ids), '?'));
            $conds[] = "$vehCol IN ($in)";
            $p = array_merge($p, $ids);
        }
        $where = $conds ? ('WHERE ' . implode(' AND ', $conds)) : '';
        return self::deleteRowcount($table, $where, $p);
    }

    /** Truncate a table (safe: only whitelisted generated tables). */
    private static function truncateSafe(string $table): int {
        $gen = self::RESET_ORDER;
        if (!in_array($table, $gen, true)) return 0;
        Database::query('SET FOREIGN_KEY_CHECKS = 0');
        try {
            $before = (int) (Database::fetch("SELECT COUNT(*) AS c FROM $table")['c'] ?? 0);
            Database::query("TRUNCATE TABLE $table");
            return $before;
        } finally {
            Database::query('SET FOREIGN_KEY_CHECKS = 1');
        }
    }
}
