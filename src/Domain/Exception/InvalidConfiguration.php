<?php
declare(strict_types=1);

namespace Latra\Domain\Exception;

/** Deployment configuration that cannot be honoured; reported before any effect. */
final class InvalidConfiguration extends \RuntimeException
{
}
