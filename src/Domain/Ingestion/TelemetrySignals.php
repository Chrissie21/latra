<?php
declare(strict_types=1);

namespace Latra\Domain\Ingestion;

/**
 * Immutable canonical evidence decoded from one source message.
 *
 * Values are profile-neutral and missing evidence stays null. Strict and loose flag evidence are
 * kept separately because named activities accept only explicit on-values while the legacy
 * detector follows first-present-key truthiness. Raw vendor keys and bit syntax stay with the
 * source adapter.
 */
final class TelemetrySignals
{
    /** @param array<string,bool> $strictFlags @param array<string,?bool> $looseFlags */
    public function __construct(
        private ?string $eventName = null,
        private ?string $eventKey = null,
        private ?string $rawEventName = null,
        private array $strictFlags = [],
        private array $looseFlags = [],
        private bool $driverPresent = false,
        private bool $invalidScanPresent = false,
        private bool $fuelPresent = false,
        private bool $fuelMeasured = false,
        private ?float $gForce = null,
        private ?bool $engineOn = null,
        private ?float $speedKmh = null,
        private ?bool $externalPowerPresent = null,
        private ?float $externalPowerVolts = null,
        private ?float $internalBatteryVolts = null,
        private ?float $externalPowerMv = null,
        private ?float $internalBatteryMv = null,
        private ?float $batteryPct = null,
        private bool $deviceTampered = false,
        private ?string $fmbManeuver = null,
        private bool $fmbPowerCut = false,
        private bool $fmbExcessiveIdle = false,
        private ?bool $detectorEngineOn = null,
    ) {
    }

    /** Detector event name for this evidence view, already case-normalised. */
    public function eventName(): ?string
    {
        return $this->eventName;
    }

    /** Recognised event-vocabulary key after trimming and case normalisation. */
    public function eventKey(): ?string
    {
        return $this->eventKey;
    }

    /** Recognised event-vocabulary value without trimming, matching the mapper's exact override. */
    public function rawEventName(): ?string
    {
        return $this->rawEventName;
    }

    public function strictFlag(string $signal): bool
    {
        return $this->strictFlags[$signal] ?? false;
    }

    public function looseFlag(string $signal): ?bool
    {
        return $this->looseFlags[$signal] ?? null;
    }

    public function driverPresent(): bool
    {
        return $this->driverPresent;
    }

    public function invalidScanPresent(): bool
    {
        return $this->invalidScanPresent;
    }

    public function fuelPresent(): bool
    {
        return $this->fuelPresent;
    }

    /** A numeric fuel measurement, including a reported zero. */
    public function fuelMeasured(): bool
    {
        return $this->fuelMeasured;
    }

    public function gForce(): ?float
    {
        return $this->gForce;
    }

    public function engineOn(): ?bool
    {
        return $this->engineOn;
    }

    /** Engine evidence after the activity detector's caller-specific filtering. */
    public function detectorEngineOn(): ?bool
    {
        return $this->detectorEngineOn;
    }

    public function speedKmh(): ?float
    {
        return $this->speedKmh;
    }

    public function externalPowerPresent(): ?bool
    {
        return $this->externalPowerPresent;
    }

    public function externalPowerVolts(): ?float
    {
        return $this->externalPowerVolts;
    }

    public function internalBatteryVolts(): ?float
    {
        return $this->internalBatteryVolts;
    }

    public function externalPowerMv(): ?float
    {
        return $this->externalPowerMv;
    }

    public function internalBatteryMv(): ?float
    {
        return $this->internalBatteryMv;
    }

    public function batteryPct(): ?float
    {
        return $this->batteryPct;
    }

    public function deviceTampered(): bool
    {
        return $this->deviceTampered;
    }

    /** Profile-specific harsh manoeuvre: acceleration, braking or turning. */
    public function fmbManeuver(): ?string
    {
        return $this->fmbManeuver;
    }

    public function fmbPowerCut(): bool
    {
        return $this->fmbPowerCut;
    }

    public function fmbExcessiveIdle(): bool
    {
        return $this->fmbExcessiveIdle;
    }
}
