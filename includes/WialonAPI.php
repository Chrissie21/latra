<?php
/**
 * Wialon API client.
 *
 * Compatibility and composition boundary: protocol encoding, session handling
 * and unit/message queries live in the extracted infrastructure client, with
 * bounded-window validation in the domain policy and paging in the application
 * use case. The public method surface and constructor contract are unchanged.
 */
require_once __DIR__ . '/autoload.php';

class WialonAPI extends \Latra\Infrastructure\Wialon\WialonApiClient {
    public function __construct(string $baseUrl = WIALON_BASE_URL, string $token = WIALON_TOKEN) {
        parent::__construct($baseUrl, $token, new \Latra\Infrastructure\Wialon\CurlWialonTransport());
    }
}
