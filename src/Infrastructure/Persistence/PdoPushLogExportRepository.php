<?php
namespace Latra\Infrastructure\Persistence;

use Latra\Application\Export\PushLogExportRepository;
use PDO;

final class PdoPushLogExportRepository implements PushLogExportRepository
{
    public function __construct(private PDO $pdo) {}
    public function ceiling(): int { return (int) $this->pdo->query('SELECT MAX(id) AS id FROM push_log')->fetchColumn(); }
    public function page(string $status, int $ceiling, ?array $cursor, int $limit): array
    {
        $conditions = ['sent'=>"pl.response_status = '1'", 'pending'=>'pl.response_status IS NULL',
            'failed'=>"pl.response_status IS NOT NULL AND pl.response_status <> '1'"];
        $condition = $conditions[$status] ?? '1=1';
        $params = [$ceiling];
        $cursorSql = '';
        if ($cursor !== null) {
            if ($cursor['time'] === null) {
                $cursorSql = ' AND pl.created_at IS NULL AND pl.id < ?';
                $params[] = $cursor['id'];
            } else {
                $cursorSql = ' AND (pl.created_at IS NULL OR pl.created_at < ? OR (pl.created_at = ? AND pl.id < ?))';
                $params = array_merge($params, [$cursor['time'], $cursor['time'], $cursor['id']]);
            }
        }
        $limit = max(1, $limit);
        $stmt = $this->pdo->prepare("SELECT pl.id, v.vehicle_reg_no, ad.activity_name, pl.activity_id,
                    pl.http_code, pl.response_status, pl.latency_ms, pl.created_at, pl.response_body
                FROM push_log pl LEFT JOIN vehicles v ON pl.vehicle_id = v.id
                LEFT JOIN activity_definitions ad ON pl.activity_id = ad.id
                WHERE pl.id <= ? AND ($condition) $cursorSql
                ORDER BY pl.created_at DESC, pl.id DESC LIMIT $limit");
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
