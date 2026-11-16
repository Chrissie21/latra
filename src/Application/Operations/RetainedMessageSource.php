<?php
namespace Latra\Application\Operations;

interface RetainedMessageSource
{
    public function open(): bool;
    public function close(): void;
    public function messages(int $unit,int $from,int $to,int $limit): array;
}
