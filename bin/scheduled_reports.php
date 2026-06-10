#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * Scheduled report runner — execute from cron (Hostinger hPanel → Cron Jobs):
 *   0 6 * * * php /home/USER/domains/example.edu/scheduler/bin/scheduled_reports.php
 *
 * Processes due rows in scheduled_reports: generates the file into
 * storage/reports (internal download center), records it in
 * generated_reports, and emails recipients when delivery includes email.
 */

$root = dirname(__DIR__);
if (is_file($root . '/vendor/autoload.php')) {
    require $root . '/vendor/autoload.php';
} else {
    spl_autoload_register(static function (string $class) use ($root): void {
        if (str_starts_with($class, 'App\\')) {
            $file = $root . '/src/' . str_replace('\\', '/', substr($class, 4)) . '.php';
            if (is_file($file)) {
                require $file;
            }
        }
    });
}

use App\Core\Database as DB;
use App\Services\ExportService;
use App\Services\ForecastService;
use App\Services\NotificationService;

$config = require $root . '/config/config.php';
DB::connect($config['database']);

$export = new ExportService($config['app']);
$notifier = new NotificationService($config['mail'], $config['integrations']);

$storageDir = $root . '/storage/reports';
if (!is_dir($storageDir)) {
    mkdir($storageDir, 0775, true);
}

$due = DB::select(
    'SELECT * FROM scheduled_reports
     WHERE is_active = 1 AND (next_run_at IS NULL OR next_run_at <= NOW())'
);

$term = DB::selectOne(
    'SELECT id, name FROM terms WHERE status IN ("draft","review","approved","published")
     ORDER BY start_date DESC LIMIT 1'
);
if ($term === null) {
    echo "No active term — nothing to report.\n";
    exit(0);
}
$termId = (int) $term['id'];

foreach ($due as $report) {
    echo "Running: {$report['name']} ({$report['report_key']})\n";

    $rows = match ($report['report_key']) {
        'conflicts' => $export->conflictsDataset($termId),
        'workload' => $export->workloadDataset($termId),
        'utilization' => $export->utilizationDataset($termId),
        'forecast' => (new ForecastService())->forecastTerm($termId, persist: false),
        'executive' => [(new \App\Services\AnalyticsService())->schedulingKpis($termId)],
        default => $export->departmentScheduleDataset($termId),
    };

    $content = match ($report['format']) {
        'csv' => $export->toCsv($rows),
        'xlsx' => $export->toCsv($rows), // CSV payload, Excel-openable; native xlsx with phpspreadsheet via web export
        default => $export->toPrintableHtml($rows, (string) $report['report_key'], ['term' => $term['name']]),
    };
    $ext = $report['format'] === 'pdf' ? 'html' : $report['format'];
    $fileName = sprintf('%s_%s.%s', $report['report_key'], date('Ymd_His'), $ext);
    file_put_contents($storageDir . '/' . $fileName, $content);

    DB::insert('generated_reports', [
        'scheduled_report_id' => (int) $report['id'],
        'name' => $report['name'] . ' — ' . $term['name'],
        'format' => $report['format'],
        'file_path' => 'storage/reports/' . $fileName,
    ]);

    if (str_contains((string) $report['delivery'], 'email')) {
        foreach (json_decode((string) ($report['recipients'] ?? '[]'), true) ?: [] as $email) {
            $user = DB::selectOne('SELECT id FROM users WHERE email = ?', [$email]);
            if ($user !== null) {
                $notifier->notify((int) $user['id'], 'scheduled_report',
                    'Scheduled report ready: ' . $report['name'],
                    'Download from the Reports center: ' . $fileName, ['inapp', 'email']);
            }
        }
    }

    $interval = match ($report['frequency']) {
        'daily' => '+1 day',
        'weekly' => '+1 week',
        'monthly' => '+1 month',
    };
    DB::update('scheduled_reports', (int) $report['id'], [
        'last_run_at' => date('Y-m-d H:i:s'),
        'next_run_at' => date('Y-m-d H:i:s', strtotime($interval)),
    ]);
}

echo 'Done: ' . count($due) . " report(s) processed.\n";
