<?php
namespace Latra\Infrastructure\Wialon;

use Latra\Application\Operations\RetainedMessageSource;

final class WialonRetainedMessageSource implements RetainedMessageSource
{
    public function __construct(private \WialonAPI $source) {}
    public function open(): bool { return $this->source->login(); }
    public function close(): void { $this->source->logout(); }
    public function messages(int $unit,int $from,int $to,int $limit): array { return $this->source->getMessages($unit,$from,$to,$limit); }
}
