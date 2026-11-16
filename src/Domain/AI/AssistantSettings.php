<?php
namespace Latra\Domain\AI;

final class AssistantSettings
{
    public function __construct(public readonly int $rateMax, public readonly int $maxTokens,
        public readonly string $appName, public readonly string $appVersion, public readonly string $timezone,
        public readonly array $activities) {}
}
