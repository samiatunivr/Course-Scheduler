<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\Response;

/**
 * Dependency-free export engine: CSV, JSON, Excel-compatible
 * SpreadsheetML (.xls, opens natively in Excel), and print-ready
 * PDF-oriented HTML with institutional branding. When
 * phpoffice/phpspreadsheet or dompdf are installed via Composer they are
 * used transparently for native .xlsx / .pdf output.
 */
final class ExportService
{
    public function __construct(private readonly array $appConfig)
    {
    }

    // -----------------------------------------------------------------
    // Dataset builders
    // -----------------------------------------------------------------

    public function facultyScheduleDataset(int $termId, ?int $facultyId = null): array
    {
        $params = [$termId];
        $filter = '';
        if ($facultyId !== null) {
            $filter = ' AND f.id = ?';
            $params[] = $facultyId;
        }

        return DB::select(
            'SELECT CONCAT(f.first_name, " ", f.last_name) faculty, c.code course, c.title,
                    s.section_no, m.day, TIME_FORMAT(m.start_time, "%H:%i") start_time,
                    TIME_FORMAT(m.end_time, "%H:%i") end_time,
                    COALESCE(r.code, "—") room, c.credit_hours, c.contact_hours
             FROM sections s
             JOIN faculty f ON f.id = s.faculty_id
             JOIN courses c ON c.id = s.course_id
             LEFT JOIN section_meetings m ON m.section_id = s.id
             LEFT JOIN rooms r ON r.id = m.room_id
             WHERE s.term_id = ? AND s.status <> "cancelled"' . $filter . '
             ORDER BY faculty, FIELD(m.day, "Mon","Tue","Wed","Thu","Fri","Sat","Sun"), m.start_time',
            $params
        );
    }

    public function departmentScheduleDataset(int $termId, ?int $departmentId = null): array
    {
        $params = [$termId];
        $filter = '';
        if ($departmentId !== null) {
            $filter = ' AND d.id = ?';
            $params[] = $departmentId;
        }

        return DB::select(
            'SELECT d.code department, c.code course, c.title, s.section_no, s.status,
                    CONCAT(COALESCE(f.first_name, "TBA"), " ", COALESCE(f.last_name, "")) instructor,
                    m.day, TIME_FORMAT(m.start_time, "%H:%i") start_time,
                    TIME_FORMAT(m.end_time, "%H:%i") end_time,
                    COALESCE(r.code, "—") room, s.capacity, s.enrolled
             FROM sections s
             JOIN courses c ON c.id = s.course_id
             JOIN departments d ON d.id = c.department_id
             LEFT JOIN faculty f ON f.id = s.faculty_id
             LEFT JOIN section_meetings m ON m.section_id = s.id
             LEFT JOIN rooms r ON r.id = m.room_id
             WHERE s.term_id = ? AND s.status <> "cancelled"' . $filter . '
             ORDER BY d.code, c.code, s.section_no,
                      FIELD(m.day, "Mon","Tue","Wed","Thu","Fri","Sat","Sun"), m.start_time',
            $params
        );
    }

    public function workloadDataset(int $termId, ?int $departmentId = null): array
    {
        $workloads = (new WorkloadService())->facultyWorkloads($termId, $departmentId);

        return array_map(fn ($w) => [
            'faculty' => $w['name'],
            'department' => $w['department'],
            'rank' => $w['rank'],
            'contract' => $w['contract_type'],
            'teaching_credits' => $w['teaching_credits'],
            'contact_hours' => (float) $w['contact_hours'],
            'preps' => (int) $w['preps'],
            'activity_credits' => $w['activity_credits'],
            'total_credits' => $w['total_credits'],
            'max_credits' => (float) $w['max_credit_hours'],
            'utilization_pct' => $w['utilization_pct'],
            'load_status' => $w['load_status'],
            'violations' => implode('; ', array_map(fn ($v) => $v['policy'], $w['violations'])),
        ], $workloads);
    }

    public function utilizationDataset(int $termId): array
    {
        return (new AnalyticsService())->roomUtilization($termId);
    }

    public function conflictsDataset(int $termId): array
    {
        return DB::select(
            'SELECT type, severity, description, suggestion, status, detected_at
             FROM schedule_conflicts WHERE term_id = ? ORDER BY severity, type',
            [$termId]
        );
    }

    // -----------------------------------------------------------------
    // Format renderers
    // -----------------------------------------------------------------

    /** Stream a dataset in the requested format. */
    public function send(array $rows, string $format, string $name, array $meta = []): never
    {
        Audit::log('export', 'report', null, null, ['name' => $name, 'format' => $format, 'rows' => count($rows)]);
        $filename = $name . '_' . date('Ymd_His');

        match ($format) {
            'csv' => Response::download($this->toCsv($rows), "$filename.csv", 'text/csv; charset=utf-8'),
            'json' => Response::download(
                json_encode(['meta' => $meta + ['generated_at' => date('c')], 'data' => $rows],
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE),
                "$filename.json", 'application/json'
            ),
            'xml' => Response::download($this->toXml($rows, $name), "$filename.xml", 'application/xml'),
            'xlsx', 'excel' => $this->sendExcel($rows, $filename, $name),
            'pdf', 'print' => Response::html($this->toPrintableHtml($rows, $name, $meta)),
            default => Response::error("Unsupported export format: $format", 422),
        };
    }

    public function toCsv(array $rows): string
    {
        if ($rows === []) {
            return '';
        }
        $out = fopen('php://temp', 'r+');
        fwrite($out, "\u{FEFF}"); // UTF-8 BOM so Excel reads accents correctly
        fputcsv($out, array_keys($rows[0]), ',', '"', '');
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($v) => is_array($v) ? json_encode($v) : $v, array_values($row)), ',', '"', '');
        }
        rewind($out);

        return (string) stream_get_contents($out);
    }

    private function toXml(array $rows, string $rootName): string
    {
        $safe = preg_replace('/[^a-z0-9_]/i', '_', $rootName);
        $xml = new \SimpleXMLElement("<{$safe}/>");
        foreach ($rows as $row) {
            $item = $xml->addChild('record');
            foreach ($row as $key => $value) {
                $item->addChild(
                    preg_replace('/[^a-z0-9_]/i', '_', (string) $key),
                    htmlspecialchars((string) (is_array($value) ? json_encode($value) : $value))
                );
            }
        }

        return (string) $xml->asXML();
    }

    private function sendExcel(array $rows, string $filename, string $title): never
    {
        if (class_exists(\PhpOffice\PhpSpreadsheet\Spreadsheet::class)) {
            $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
            $sheet = $spreadsheet->getActiveSheet();
            $sheet->setTitle(substr($title, 0, 31));
            $sheet->fromArray($rows === [] ? [[]] : array_merge([array_keys($rows[0])], array_map('array_values', $rows)));
            ob_start();
            (new \PhpOffice\PhpSpreadsheet\Writer\Xlsx($spreadsheet))->save('php://output');
            Response::download((string) ob_get_clean(), "$filename.xlsx",
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        }

        // Fallback: SpreadsheetML 2003 — opens natively in Excel.
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n"
            . '<Workbook xmlns="urn:schemas-microsoft-com:office:spreadsheet" '
            . 'xmlns:ss="urn:schemas-microsoft-com:office:spreadsheet">'
            . '<Worksheet ss:Name="' . htmlspecialchars(substr($title, 0, 31)) . '"><Table>';
        if ($rows !== []) {
            $xml .= '<Row>';
            foreach (array_keys($rows[0]) as $h) {
                $xml .= '<Cell><Data ss:Type="String">' . htmlspecialchars((string) $h) . '</Data></Cell>';
            }
            $xml .= '</Row>';
            foreach ($rows as $row) {
                $xml .= '<Row>';
                foreach ($row as $v) {
                    $type = is_numeric($v) ? 'Number' : 'String';
                    $v = is_array($v) ? json_encode($v) : (string) $v;
                    $xml .= "<Cell><Data ss:Type=\"$type\">" . htmlspecialchars($v) . '</Data></Cell>';
                }
                $xml .= '</Row>';
            }
        }
        $xml .= '</Table></Worksheet></Workbook>';
        Response::download($xml, "$filename.xls", 'application/vnd.ms-excel');
    }

    /**
     * Print-ready branded report: logo placeholder, institution name,
     * timestamp, page numbers via CSS paged media. Browsers (and dompdf,
     * if installed) convert this to PDF.
     */
    public function toPrintableHtml(array $rows, string $title, array $meta = []): string
    {
        $appName = htmlspecialchars((string) $this->appConfig['name']);
        $titleSafe = htmlspecialchars(ucwords(str_replace('_', ' ', $title)));
        $generated = date('Y-m-d H:i');
        $metaHtml = '';
        foreach ($meta as $k => $v) {
            $metaHtml .= '<span class="meta-item"><strong>' . htmlspecialchars(ucfirst((string) $k))
                . ':</strong> ' . htmlspecialchars((string) $v) . '</span> ';
        }

        $thead = $tbody = '';
        if ($rows !== []) {
            $thead = '<tr>' . implode('', array_map(
                fn ($h) => '<th>' . htmlspecialchars(ucwords(str_replace('_', ' ', (string) $h))) . '</th>',
                array_keys($rows[0])
            )) . '</tr>';
            foreach ($rows as $row) {
                $tbody .= '<tr>' . implode('', array_map(
                    fn ($v) => '<td>' . htmlspecialchars((string) (is_array($v) ? json_encode($v) : $v)) . '</td>',
                    array_values($row)
                )) . '</tr>';
            }
        }
        $summary = sprintf('This report contains %d records, generated automatically by the %s on %s.',
            count($rows), $appName, $generated);

        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>$titleSafe — $appName</title>
<style>
  @page { margin: 2cm 1.5cm; @bottom-right { content: "Page " counter(page) " of " counter(pages); } }
  body { font-family: 'Segoe UI', Arial, sans-serif; color: #1a1a2e; margin: 0; }
  .report-header { display: flex; align-items: center; gap: 16px; border-bottom: 3px solid #1f4e79;
                   padding: 16px 0; margin-bottom: 12px; }
  .logo { width: 56px; height: 56px; background: #1f4e79; color: #fff; border-radius: 8px;
          display: flex; align-items: center; justify-content: center; font-size: 26px; font-weight: 700; }
  h1 { font-size: 20px; margin: 0; } .sub { color: #555; font-size: 12px; }
  .meta { font-size: 12px; color: #444; margin-bottom: 10px; }
  .meta-item { margin-right: 14px; }
  .summary { background: #f0f4fa; border-left: 4px solid #1f4e79; padding: 10px 14px;
             font-size: 12px; margin-bottom: 14px; }
  table { width: 100%; border-collapse: collapse; font-size: 11px; }
  th { background: #1f4e79; color: #fff; text-align: left; padding: 6px 8px; }
  td { border-bottom: 1px solid #d8dee9; padding: 5px 8px; }
  tr:nth-child(even) td { background: #f7f9fc; }
  .footer { margin-top: 16px; font-size: 10px; color: #888; text-align: center; }
  .no-print { margin: 12px 0; }
  @media print { .no-print { display: none; } }
</style>
</head>
<body>
<div class="no-print"><button onclick="window.print()">🖨 Print / Save as PDF</button></div>
<div class="report-header">
  <div class="logo">U</div>
  <div>
    <h1>$titleSafe</h1>
    <div class="sub">$appName · Generated $generated</div>
  </div>
</div>
<div class="meta">$metaHtml</div>
<div class="summary"><strong>Executive summary:</strong> $summary</div>
<table><thead>$thead</thead><tbody>$tbody</tbody></table>
<div class="footer">$appName — Confidential. Generated $generated.</div>
</body>
</html>
HTML;
    }
}
