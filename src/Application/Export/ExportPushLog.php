<?php
namespace Latra\Application\Export;

/** Bounded export use case; a fixed identity ceiling excludes receipts appended mid-export. */
final class ExportPushLog
{
    public function __construct(private PushLogExportRepository $repository) {}
    public function rows(string $status = ''): iterable
    {
        $ceiling = $this->repository->ceiling();
        $cursor = null;
        do {
            $rows = $this->repository->page($status, $ceiling, $cursor, 500);
            foreach ($rows as $row) {
                yield [$row['id'], $row['vehicle_reg_no'] ?? '', $row['activity_name'] ?? $row['activity_id'] ?? '',
                    $row['http_code'] ?? '', $row['response_status'] === '1' ? 'Sent' : ($row['response_status'] ?? 'Pending'),
                    $row['latency_ms'] ?? '', $row['created_at'], mb_substr((string) ($row['response_body'] ?? ''), 0, 500)];
            }
            if ($rows) {
                $last = end($rows);
                $cursor = ['time'=>$last['created_at'], 'id'=>(int) $last['id']];
            }
        } while (count($rows) === 500);
    }
}
