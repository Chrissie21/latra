<?php
namespace Latra\Application\Security;

interface SecretCipher
{
    public function encrypt(string $plain): string;
    public function decrypt(string $blob): string;
}
