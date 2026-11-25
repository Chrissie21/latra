<?php
declare(strict_types=1);
namespace Latra\Domain\Delivery;


/** Persisted batch metadata is local only; request items are never copied or padded. */
final class VtsBatchPolicy
{
    public const META = '__relay_batch_v1';
    public const MAX_ITEMS = 198; // largest multiple of three below the IDD's 200 ceiling

    /** Select the oldest complete prefix; an incomplete tail stays in the queue. */
    public static function assemble(array $reports, int $now): ?array
    {
        $payload = null;
        $members = [];
        $complete = null;
        foreach ($reports as $report) {
            if ($report['status'] !== 'pending' || (int) $report['attempts'] !== 0
                || (int) $report['next_attempt'] > $now) break;
            $one = json_decode($report['payload'], true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($one) || !is_array($one['items'] ?? null) || !$one['items']
                || !array_is_list($one['items']) || isset($one[self::META])) break;
            if ($payload !== null) {
                foreach (['vehicle_reg_no','imei','type'] as $field) {
                    if (($one[$field] ?? null) !== ($payload[$field] ?? null)) break 2;
                }
            }
            if (count($payload['items'] ?? []) + count($one['items']) > self::MAX_ITEMS) break;
            if ($payload === null) $payload = $one;
            else $payload['items'] = array_merge($payload['items'], $one['items']);
            if (strlen(json_encode($payload, JSON_THROW_ON_ERROR)) > 524288) break;
            $members[] = ['id'=>(int) $report['id'], 'delivery_key'=>$report['delivery_key'],
                'source_time'=>(int) $report['source_time']];
            if (count($payload['items']) % 3 === 0) {
                $complete = $payload;
                $complete[self::META] = $members;
            }
        }
        return $complete;
    }

    public static function wireBody(string $stored): string
    {
        $payload = json_decode($stored, true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($payload) || !isset($payload[self::META])) return $stored;
        unset($payload[self::META]);
        return json_encode($payload, JSON_THROW_ON_ERROR);
    }

    public static function members(array $report): array
    {
        $payload = json_decode($report['payload'], true, 512, JSON_THROW_ON_ERROR);
        return $payload[self::META] ?? [['id'=>(int) $report['id'],
            'delivery_key'=>$report['delivery_key'], 'source_time'=>(int) $report['source_time']]];
    }
}
