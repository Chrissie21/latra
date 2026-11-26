<?php
/** Wialon API client. */
require_once __DIR__ . '/autoload.php';

class WialonAPI extends \Latra\Infrastructure\Wialon\WialonApiClient {
    public function __construct(string $baseUrl = WIALON_BASE_URL, string $token = WIALON_TOKEN) {
        parent::__construct($baseUrl, $token, new \Latra\Infrastructure\Wialon\CurlWialonTransport());
    }
}
