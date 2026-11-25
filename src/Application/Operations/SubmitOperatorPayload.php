<?php
declare(strict_types=1);

namespace Latra\Application\Operations;

use Closure;
use RuntimeException;
use Throwable;

/**
 * Operator submissions become durable relay intent; no transport is invoked here.
 *
 * Payload mapping and validation are injected because the submission shapes are
 * owned by the LATRA payload policy and its compatibility adapter.
 */
final class SubmitOperatorPayload
{
    private Closure $mapper;
    private Closure $validator;
    private Closure $queue;

    public function __construct(private OperatorActionRepository $repository,
        callable $mapper, callable $validator, callable $queue)
    {
        $this->mapper = Closure::fromCallable($mapper);
        $this->validator = Closure::fromCallable($validator);
        $this->queue = Closure::fromCallable($queue);
    }

    /** Rebuild the mapped payload for a stored source message, then queue it. */
    public function fromMessage(int $messageId): array
    {
        $message = $this->repository->messageForRelay($messageId);
        if (!$message) throw new RuntimeException('Message not found');
        $payload = ($this->mapper)(
            $message,
            $message,
            (int) $message['activity_id'],
            json_decode($message['addon_info'] ?? '[]', true),
            json_decode($message['fuel_info'] ?? '[]', true),
        );
        return $this->submitForVehicle((int) $message['vehicle_id'], $payload);
    }

    /** Queue an operator-edited payload that belongs to a stored source message. */
    public function fromMessagePayload(int $messageId, string $json): array
    {
        $message = $this->repository->messageForSubmission($messageId);
        if (!$message) throw new RuntimeException('Message not found');
        $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload)) throw new RuntimeException('Payload must be a JSON object');
        return $this->submitForVehicle((int) $message['vehicle_id'], $payload);
    }

    /** Queue an operator-edited payload for an explicitly selected vehicle. */
    public function fromCustomPayload(int $vehicleId, string $json): array
    {
        $payload = json_decode($json, true);
        if (!is_array($payload)) throw new RuntimeException('Invalid JSON payload');
        return $this->submitForVehicle($vehicleId, $payload);
    }

    /** Replay stored delivery intent without rebuilding or mutating the payload. */
    public function fromStoredPayload(int $pushLogId): array
    {
        $log = $this->repository->pushLog($pushLogId);
        if (!$log) throw new RuntimeException('Log entry not found');
        $payload = json_decode($log['payload'] ?? '', true);
        if (!is_array($payload)) throw new RuntimeException('Stored payload is invalid JSON. Use Edit to rebuild it.');
        return $this->submitForVehicle((int) $log['vehicle_id'], $payload);
    }

    private function submitForVehicle(int $vehicleId, array $payload): array
    {
        $vehicle = $this->repository->vehicle($vehicleId);
        if (!$vehicle) throw new RuntimeException('Vehicle not found');
        $errors=($this->validator)($payload);
        try {
            $result = ($this->queue)($vehicle, $payload);
        } catch (Throwable $error) {
            return ['success' => false, 'message' => $error->getMessage()];
        }
        if($errors) return array_merge($result ?: [],['success'=>false,'review'=>true,'errors'=>$errors,
            'message'=>'Saved for review; missing or invalid required data blocks delivery.']);
        return $result ?: ['success' => true, 'message' => 'Saved to the durable relay. Real LATRA delivery is blocked.'];
    }
}
