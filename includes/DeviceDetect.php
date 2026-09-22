<?php
/**
 * Lightweight User-Agent parser for login-attempt device logging.
 * Returns os, os_version, browser, browser_version and device class.
 */
class DeviceDetect {
    public static function parse(string $ua = ''): array {
        $ua = trim((string) $ua);
        $out = ['os' => '', 'os_version' => '', 'browser' => '', 'browser_version' => '', 'device' => 'desktop'];

        if ($ua === '') {
            $out['browser'] = 'Unknown';
            return $out;
        }

        $out['device'] = (preg_match('/Mobile|Android|iPhone|iPod|Windows Phone|BlackBerry/i', $ua)
            ? 'mobile'
            : (preg_match('/iPad|Tablet|Kindle|Silk|Nexus 10|PlayBook/i', $ua) ? 'tablet' : 'desktop'));

        // Operating system ------------------------------------------------
        if (preg_match('/Windows NT (\d+\.\d+)/i', $ua, $m)) {
            $out['os'] = 'Windows';
            $map = [
                '10.0' => '10/11',
                '6.3'  => '8.1',
                '6.2'  => '8',
                '6.1'  => '7',
                '6.0'  => 'Vista',
                '5.2'  => 'Server 2003/XP x64',
                '5.1'  => 'XP',
            ];
            $out['os_version'] = $map[$m[1]] ?? $m[1];
        } elseif (preg_match('/Android (\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['os'] = 'Android';
            $out['os_version'] = $m[1];
        } elseif (preg_match('/iPhone OS (\d+[_\d]*)/i', $ua, $m)) {
            $out['os'] = 'iOS';
            $out['os_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/iPad;.*?OS (\d+[_\d]*)/i', $ua, $m)) {
            $out['os'] = 'iOS';
            $out['os_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Mac OS X ([\d_]+)/i', $ua, $m)) {
            $out['os'] = 'macOS';
            $out['os_version'] = str_replace('_', '.', $m[1]);
        } elseif (preg_match('/Windows Phone/i', $ua)) {
            $out['os'] = 'Windows Phone';
        } elseif (preg_match('/CrOS/i', $ua)) {
            $out['os'] = 'ChromeOS';
        } elseif (preg_match('/BlackBerry|BB10/i', $ua)) {
            $out['os'] = 'BlackBerry';
        } elseif (preg_match('/Linux/i', $ua)) {
            $out['os'] = 'Linux';
        }

        // Browser ----------------------------------------------------------
        if (preg_match('/Edg(?:e|A|iOS)?\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Edge';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/OPR\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Opera';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/Vivaldi\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Vivaldi';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/SamsungBrowser\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Samsung Internet';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/Firefox\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Firefox';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/CriOS\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Chrome iOS';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/Version\/(\d+(?:\.\d+)*).*?Mobile.*?Safari/i', $ua, $m)) {
            $out['browser'] = 'Mobile Safari';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/Chrome\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Chrome';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/Safari\/(\d+(?:\.\d+)*)/i', $ua, $m)) {
            $out['browser'] = 'Safari';
            $out['browser_version'] = $m[1];
        } elseif (preg_match('/curl|wget\/|python|PostmanRuntime|Go-http|Java\/|okhttp/i', $ua)) {
            $out['browser'] = 'HTTP client';
        } else {
            $out['browser'] = 'Unknown';
        }

        return $out;
    }
}