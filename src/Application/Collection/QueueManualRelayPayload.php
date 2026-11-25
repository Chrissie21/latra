<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

use Latra\Domain\Exception\InvalidSubmission;
use Latra\Domain\Ingestion\LatraPayloadPolicy;

/** Manual submissions create durable intent and never invoke a transport. */
final class QueueManualRelayPayload
{
    public function __construct(private RelayWindowCollector $collector, private int $maxBytes) {}
    public function queue(array $vehicle, array $payload, ?array $sourceEvidence=null): array
    {
        foreach(['vehicle_reg_no','imei'] as $identity) {
            if(isset($payload[$identity]) && $payload[$identity]!=='' && ($payload[$identity]!==($vehicle[$identity]??null))) {
                throw new InvalidSubmission('Payload vehicle identity mismatch');
            }
        }
        $errors=LatraPayloadPolicy::validatePayload($payload);
        $timestamp=$payload['items'][0]['timestamp'] ?? null;
        // Receipt time is only the review record's local ordering key, never an outgoing GPS time.
        $time=is_string($timestamp) && ctype_digit($timestamp) && (float)$timestamp>=946684800000
            ? intdiv((int)$timestamp,1000) : time();
        $raw=['t'=>$time,'payload'=>$payload];
        if($sourceEvidence!==null) $raw['source_evidence']=$sourceEvidence;
        $stream = 'manual:wialon:' . $vehicle['wialon_unit_id'] . ':'
            . substr(hash('sha256', json_encode($sourceEvidence===null ? $payload : [$payload,$sourceEvidence], JSON_THROW_ON_ERROR)), 0, 24);
        $result = $this->collector->collectWindow($stream, $time, $time, 1, 1, $this->maxBytes,
            static fn(): array => ['count'=>1,'messages'=>[$raw]],
            static function(array $message,array $state) use($errors):array {
                if($errors) throw new \Latra\Domain\Exception\RequiresReview(implode('; ',$errors));
                return ['payload'=>$message['payload'],'state'=>$state];
            });
        return ['success'=>!$errors,'review'=>(bool)$errors,'errors'=>$errors,'queued'=>$result['queued'],
            'message'=>$errors ? 'Saved for review; missing or invalid required data blocks delivery.' : 'Saved to the durable relay. Real LATRA delivery is blocked.'];
    }
}
