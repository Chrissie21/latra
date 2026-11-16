<?php
declare(strict_types=1);

namespace Latra\Application\Collection;

/**
 * One HTTP POST to the Wialon API. Implementations return the decoded response,
 * or null when the transport itself failed (connection, HTTP status or an
 * exceeded response-byte bound). Application-level `error` codes are returned
 * to the client so it can keep its existing last-error reporting.
 */
interface WialonTransport
{
    public function post(string $url, string $encodedParams, ?int $maxResponseBytes): ?array;
}
