<?php
/**
 * Stream a CSV download. Set headers, encode, and exit.
 */
class Export
{
    public static function stream(string $filename, array $headers, iterable $rows): void
    {
        $filename = preg_replace('/[^A-Za-z0-9_.-]/', '_', $filename);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        header('Pragma: no-cache');
        header('Expires: 0');

        $out = fopen('php://output', 'w');
        // BOM for Excel UTF-8 detection
        fwrite($out, "\xEF\xBB\xBF");
        fputcsv($out, $headers);
        foreach ($rows as $row) {
            fputcsv($out, $row);
        }
        fclose($out);
        exit;
    }
}