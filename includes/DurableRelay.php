<?php
/** Temporary durable delivery intent, independent of message_log retention. */
use Latra\Application\Collection\PersistRelayWindow;
use Latra\Application\Collection\RecoverRelayReviews;
use Latra\Application\Collection\RelayWindowCollector;
use Latra\Application\Delivery\DeliverPendingReport;
use Latra\Infrastructure\Persistence\PdoRelayCollectionRepository;
use Latra\Infrastructure\Persistence\PdoRelayDeliveryRepository;
use Latra\Infrastructure\Persistence\PdoRelayReviewRecoveryRepository;

final class DurableRelay implements RelayWindowCollector {
    private ?Closure $fault;
    private DeliverPendingReport $delivery;
    private PersistRelayWindow $collection;
    private RecoverRelayReviews $reviewRecovery;

    public function __construct(PDO $db, int $maxAttempts, callable $backoff,
        int $maxPendingRecords, int $maxPendingBytes, ?callable $fault = null, bool $vtsBatching = false) {
        if (min($maxAttempts, $maxPendingRecords, $maxPendingBytes) < 1) {
            throw new InvalidArgumentException('Explicit positive retry/capacity budgets required');
        }
        $this->fault = $fault === null ? null : Closure::fromCallable($fault);
        $this->collection = new PersistRelayWindow(
            new PdoRelayCollectionRepository($db),
            $maxPendingRecords,
            $maxPendingBytes,
            $fault,
        );
        $this->reviewRecovery = new RecoverRelayReviews(
            new PdoRelayReviewRecoveryRepository($db, $maxPendingBytes, $fault),
        );
        $this->delivery = new DeliverPendingReport(
            new PdoRelayDeliveryRepository($db, fn(string $stage) => $this->hit($stage), $vtsBatching),
            $maxAttempts,
            $backoff,
            fn(string $stage) => $this->hit($stage),
        );
    }

    private function hit(string $stage): void {
        if ($this->fault !== null) ($this->fault)($stage);
    }

    /** A complete bounded window, queue intent and collection watermark commit together. */
    public function collect(string $unit, int $from, int $to, int $pageSize, int $maxRecords,
        int $maxBytes, callable $fetchPage, callable $map): int {
        return $this->collectWindow($unit, $from, $to, $pageSize, $maxRecords, $maxBytes, $fetchPage, $map)['queued'];
    }

    /** Per-window counters distinguish durable review evidence from sendable intent and replay. */
    public function collectWindow(string $unit, int $from, int $to, int $pageSize, int $maxRecords,
        int $maxBytes, callable $fetchPage, callable $map): array {
        return $this->collection->collectWindow(
            $unit,
            $from,
            $to,
            $pageSize,
            $maxRecords,
            $maxBytes,
            $fetchPage,
            $map,
        );
    }

    /** Payload-free progress and queue counts for operators. */
    public function status(string $unit): array {
        return $this->collection->status($unit);
    }

    /** Explicitly revalidate safe, never-attempted mapping-review evidence for one vehicle. */
    public function recoverReviews(string $vehicleUnit, callable $map): array {
        return $this->reviewRecovery->recover($vehicleUnit, $map);
    }

    /** One unit at a time; a failed head blocks only that unit, not collection or other units. */
    public function deliver(string $unit, int $now, callable $send, ?callable $canSend = null): string {
        return $this->delivery->deliver($unit, $now, $send, $canSend);
    }
}
