<?php
/**
 * Param resolver.
 */
class ParamResolver {
    private static ?array $map = null;

    public static function resolve(array $params, ?string $deviceType = null): array {
        $out = $params;
        foreach (self::aliases() as $row) {
            if (!$row['is_enabled']) {
                continue;
            }
            $type = $row['device_type'];
            if ($type !== '*' && $deviceType && strcasecmp($type, $deviceType) !== 0) {
                continue;
            }
            $from = $row['param_key'];
            $to = $row['canonical_key'];
            if (array_key_exists($from, $params) && !array_key_exists($to, $out)) {
                $out[$to] = $params[$from];
            }
        }
        return $out;
    }

    public static function pick(array $canonical, string $key, array $fallbacks = []) {
        if (array_key_exists($key, $canonical) && $canonical[$key] !== '' && $canonical[$key] !== null) {
            return $canonical[$key];
        }
        foreach ($fallbacks as $f) {
            if (array_key_exists($f, $canonical) && $canonical[$f] !== '' && $canonical[$f] !== null) {
                return $canonical[$f];
            }
        }
        return null;
    }

    private static function aliases(): array {
        if (self::$map === null) {
            try {
                self::$map = Database::fetchAll("SELECT * FROM param_aliases WHERE is_enabled = 1");
            } catch (Throwable $e) {
                self::$map = [];
            }
        }
        return self::$map;
    }
}
