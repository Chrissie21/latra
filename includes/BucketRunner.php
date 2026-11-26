<?php
/** Automation bucket runner. */
class BucketRunner {

    /** Run one bucket through the relay collection use case. */
    public static function run(array $bucket): array {
        return RelayRuntime::bucket($bucket);
    }
}
