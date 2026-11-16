<?php
require_once __DIR__ . '/autoload.php';

/** Compatibility parser name for retained audit callers. */
class DeviceDetect {
    public static function parse(string $ua): array { return \Latra\Domain\Security\ClientDevicePolicy::parse($ua); }
}
