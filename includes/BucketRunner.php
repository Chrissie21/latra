<?php
/**
 * Automation bucket runner.
 *
 * Delivery is relay-only: a due bucket is executed by the relay bucket use case
 * (CollectRelayBucket). The former retained-source bucket path — Wialon fetch,
 * legacy batch ingestion, newest-message selection, direct LATRA submit and
 * outbox retry — was removed with the legacy direct-send delivery path.
 */
class BucketRunner {

    /** Run one bucket through the relay collection use case. */
    public static function run(array $bucket): array {
        return RelayRuntime::bucket($bucket);
    }
}
