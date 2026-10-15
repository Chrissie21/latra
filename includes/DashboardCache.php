<?php

class DashboardCache {
    private const DEFAULT_TTL = 60; // seconds
    private static string $cacheDir = '';

    private static function init(): void {
        if (self::$cacheDir !== '') {
            return;
        }
        self::$cacheDir = sys_get_temp_dir() . '/latra_dash_cache';
        if (!is_dir(self::$cacheDir)) {
            @mkdir(self::$cacheDir, 0755, true);
        }
    }

    public static function get(string $key, int $ttl = self::DEFAULT_TTL): ?array {
        self::init();
        $file = self::$cacheDir . '/' . self::safeKey($key) . '.json';
        if (!file_exists($file)) {
            return null;
        }
        $mtime = @filemtime($file);
        if ($mtime === false || (time() - $mtime) > $ttl) {
            @unlink($file);
            return null;
        }
        $content = @file_get_contents($file);
        if ($content === false) {
            return null;
        }
        $data = json_decode($content, true);
        return is_array($data) ? $data : null;
    }

    public static function set(string $key, array $data): void {
        self::init();
        $file = self::$cacheDir . '/' . self::safeKey($key) . '.json';
        $content = json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        if ($content === false) {
            return;
        }
        @file_put_contents($file, $content, LOCK_EX);
    }

    public static function clear(): void {
        self::init();
        if (!is_dir(self::$cacheDir)) {
            return;
        }
        $files = @glob(self::$cacheDir . '/*.json');
        if ($files === false) {
            return;
        }
        foreach ($files as $file) {
            @unlink($file);
        }
    }

    private static function safeKey(string $key): string {
        return hash('sha256', $key);
    }
}
