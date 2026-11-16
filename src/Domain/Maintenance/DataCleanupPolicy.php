<?php
namespace Latra\Domain\Maintenance;

use InvalidArgumentException;

final class DataCleanupPolicy
{
    /** Generated/log tables whitelisted for an explicit full reset. */
    public const RESET_ORDER = [
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

    /** Configuration tables are never truncated. Only derived bucket counters are reset. */
    public const PROTECTED = [
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


    public static function criteria(string $category,array $filters,int $now): array {
        $spec=self::categories()[$category] ?? null;
        if ($spec===null) throw new InvalidArgumentException("Unknown data category: $category");
        $allowed=$spec['filters'];
        if (in_array('vehicle',$allowed,true) && !in_array('imei',$allowed,true)) $allowed[]='imei';
        foreach ($filters as $field=>$value) {
            if ($value!=='' && $value!==null && !in_array($field,$allowed,true)) {
                throw new InvalidArgumentException("Unsupported filter '$field' for $category");
            }
        }
        $criteria=[];
        foreach ($allowed as $field) {
            $value=(string)($filters[$field] ?? '');
            if ($value==='') continue;
            if ($field==='older_than_days') {
                if ((int)$value>0) $criteria['older_than']=gmdate('Y-m-d H:i:s',$now-(int)$value*86400);
            } elseif ($field==='from' || $field==='to') {
                $criteria[$field]=trim($value).($field==='to' ? ' 23:59:59' : ' 00:00:00');
            } else $criteria[$field]=$value;
        }
        return $criteria;
    }
}
