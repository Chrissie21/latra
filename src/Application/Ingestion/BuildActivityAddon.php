<?php
declare(strict_types=1);
namespace Latra\Application\Ingestion;

use Closure;
use Latra\Domain\Ingestion\LatraPayloadPolicy;
use Latra\Domain\Ingestion\TelemetryMessage;

/** Mapping diagnostics are effects; payload values remain the domain policy's decision. */
final class BuildActivityAddon
{
    private Closure $log;
    public function __construct(callable $log) { $this->log = Closure::fromCallable($log); }

    public function build(int $activityId, ?TelemetryMessage $message = null, array $custom = []): ?array
    {
        $message ??= new TelemetryMessage();
        if (in_array($activityId, [9, 10], true)) {
            foreach (['ext_power_voltage' => $message->externalPowerVolts,
                'int_battery_voltage' => $message->internalBatteryVolts] as $field => $value) {
                if ($value === null) ($this->log)('voltage.skip', ['field' => $field, 'reason' => 'no_live_value']);
            }
        }
        return LatraPayloadPolicy::buildAddonInfo($activityId, $message, $custom);
    }
}
