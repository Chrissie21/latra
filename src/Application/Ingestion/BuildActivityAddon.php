<?php
declare(strict_types=1);
namespace Latra\Application\Ingestion;

use Closure;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

/** Mapping diagnostics are effects; payload values remain the domain policy's decision. */
final class BuildActivityAddon
{
    private Closure $log;
    public function __construct(callable $log) { $this->log = Closure::fromCallable($log); }

    public function build(int $activityId, array $parameters = [], array $custom = []): ?array
    {
        if (in_array($activityId, [9, 10], true)) {
            $external = $custom['extVolt'] ?? $parameters['pwr_ext'] ?? $parameters['ext_power'] ?? $parameters['io_66'] ?? null;
            $internal = $custom['intVolt'] ?? $parameters['pwr_int'] ?? $parameters['int_battery'] ?? $parameters['io_67'] ?? null;
            foreach (['ext_power_voltage' => $external, 'int_battery_voltage' => $internal] as $field => $value) {
                if ($value === null || $value === '') ($this->log)('voltage.skip', ['field' => $field, 'reason' => 'no_live_value']);
            }
        }
        return LatraPayloadPolicy::buildAddonInfo($activityId, $parameters, $custom);
    }
}
