<?php
namespace Latra\Infrastructure\Wialon;

use Closure;
use RuntimeException;
use Latra\Application\Operations\LatestVehicleSource;

final class WialonLatestVehicleSource implements LatestVehicleSource
{
    private Closure $source;
    public function __construct(callable $source) { $this->source=Closure::fromCallable($source); }
    public function latest(int $unit,int $from,int $to): ?array
    {
        $source=($this->source)();
        if (!$source->login()) throw new RuntimeException('Wialon login failed');
        try {
            $metadata=null;
            foreach ($source->getUnits() as $row) if ((int)($row['id'] ?? 0)===$unit) { $metadata=$row;break; }
            $messages=$source->getMessages($unit,$from,$to,1);
            return isset($messages[0]) ? ['unit'=>$metadata,'message'=>$messages[0]] : null;
        } finally { $source->logout(); }
    }
}
