<?php
namespace Latra\Domain\Security;

final class AccessPolicy
{
    public static function roleLabels(): array {
        return [
            'admin'          => 'Administrator',
            'operator'       => 'Operator',
            'viewer'         => 'Viewer (read-only)',
            'latra_user'     => 'LATRA user',
            'latra_viewer'   => 'LATRA viewer',
            'latra_inspector'=> 'LATRA inspector',
            'latra_admin'    => 'LATRA administrator',
        ];
    }

    public static function viewerPermissions(): array {
        return [
            'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
            'gps.view', 'events.view', 'integration.view', 'reports.view',
            'audit.view', 'device.parameters.view',
        ];
    }

    public static function latraPermissions(): array {
        return [
            'latra_user' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'device.parameters.view',
            ],
            'latra_viewer' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'device.parameters.view',
            ],
            'latra_inspector' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'audit.view', 'device.interrogate', 'device.parameters.view',
            ],
            'latra_admin' => [
                'dashboard.view', 'live.view', 'vehicles.view', 'devices.view',
                'gps.view', 'events.view', 'integration.view', 'reports.view',
                'audit.view', 'device.interrogate', 'device.parameters.view',
                'device.parameters.update', 'device.commands.view',
            ],
        ];
    }
    public static function canWrite(string $role): bool { return in_array($role, ['admin','operator'], true); }
    public static function isLatraRole(string $role): bool { return isset(self::latraPermissions()[$role]); }
    public static function hasPermission(string $role, string $permission): bool {
        if (self::canWrite($role)) return true;
        return in_array($permission, $role === 'viewer' ? self::viewerPermissions() : (self::latraPermissions()[$role] ?? []), true);
    }
}
