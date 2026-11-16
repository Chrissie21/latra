<?php
namespace Latra\Domain\AI;

use DateTime;
use DateTimeZone;
use Throwable;

final class OperationalToolPolicy
{
    public static function timeText($value,string $timezone,string $format): string {
        if ($value === null || $value === '') {
            return 'n/a';
        }
        if (is_numeric($value)) {
            $ts = (int) $value;
            if ($ts > 10000000000) {
                $ts = (int) ($ts / 1000);
            }
        } else {
            $ts = strtotime((string) $value);
            if (!$ts) {
                return (string) $value;
            }
        }
        try {
            $d = new DateTime('@' . $ts);
            $d->setTimezone(new DateTimeZone($timezone));
            return $d->format($format);
        } catch (Throwable $e) {
            return (string) $ts;
        }
    }
}
