<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Tenancy;

/**
 * Bulk CSV/Excel import with validation, preview, error reporting and
 * rollback. Flow: validate() returns a preview + errors and stores the
 * batch; commit() inserts valid rows and records created ids; rollback()
 * deletes them again.
 */
final class ImportService
{
    private const ENTITIES = ['faculty', 'courses', 'rooms', 'enrollment', 'availability'];

    /** Parse an uploaded CSV (or Excel-exported CSV) into rows. */
    public function parseCsv(string $tmpPath): array
    {
        $handle = fopen($tmpPath, 'r');
        if ($handle === false) {
            throw new \RuntimeException('Cannot read uploaded file');
        }
        $header = fgetcsv($handle, null, ',', '"', '');
        if ($header === false) {
            fclose($handle);

            return [];
        }
        // Strip BOM and normalize headers.
        $header = array_map(
            fn ($h) => strtolower(trim(str_replace("\u{FEFF}", '', (string) $h))),
            $header
        );
        $rows = [];
        while (($line = fgetcsv($handle, null, ',', '"', '')) !== false) {
            if (count(array_filter($line, fn ($v) => trim((string) $v) !== '')) === 0) {
                continue;
            }
            $rows[] = array_combine($header, array_pad($line, count($header), null));
        }
        fclose($handle);

        return $rows;
    }

    /** Validate rows and persist a batch record. Returns preview + errors. */
    public function validate(string $entityType, string $fileName, array $rows): array
    {
        if (!in_array($entityType, self::ENTITIES, true)) {
            throw new \InvalidArgumentException("Unknown import entity: $entityType");
        }

        $errors = [];
        $valid = [];
        foreach ($rows as $i => $row) {
            $rowErrors = $this->validateRow($entityType, $row);
            if ($rowErrors === []) {
                $valid[] = $row;
            } else {
                $errors[] = ['row' => $i + 2, 'errors' => $rowErrors, 'data' => $row]; // +2 = header + 1-index
            }
        }

        $batchId = DB::insert('import_batches', [
            'tenant_id' => Tenancy::requireId(),
            'user_id' => Auth::id() ?? 0,
            'entity_type' => $entityType,
            'file_name' => $fileName,
            'total_rows' => count($rows),
            'valid_rows' => count($valid),
            'error_rows' => count($errors),
            'errors' => json_encode(array_slice($errors, 0, 200)),
            'status' => 'validated',
        ]);

        return [
            'batch_id' => $batchId,
            'total' => count($rows),
            'valid' => count($valid),
            'invalid' => count($errors),
            'errors' => $errors,
            'preview' => array_slice($valid, 0, 10),
            'valid_rows' => $valid,
        ];
    }

    private function validateRow(string $entityType, array $row): array
    {
        $errors = [];
        $require = function (array $fields) use ($row, &$errors) {
            foreach ($fields as $f) {
                if (trim((string) ($row[$f] ?? '')) === '') {
                    $errors[] = "Missing required field: $f";
                }
            }
        };

        switch ($entityType) {
            case 'faculty':
                $require(['first_name', 'last_name', 'email', 'department_code']);
                if (($row['email'] ?? '') !== '' && !filter_var($row['email'], FILTER_VALIDATE_EMAIL)) {
                    $errors[] = 'Invalid email: ' . $row['email'];
                }
                if (($row['department_code'] ?? '') !== '' && $this->departmentId((string) $row['department_code']) === null) {
                    $errors[] = 'Unknown department: ' . $row['department_code'];
                }
                break;
            case 'courses':
                $require(['code', 'title', 'department_code', 'credit_hours']);
                if (isset($row['credit_hours']) && !is_numeric($row['credit_hours'])) {
                    $errors[] = 'credit_hours must be numeric';
                }
                if (($row['department_code'] ?? '') !== '' && $this->departmentId((string) $row['department_code']) === null) {
                    $errors[] = 'Unknown department: ' . $row['department_code'];
                }
                break;
            case 'rooms':
                $require(['code', 'name', 'capacity']);
                if (isset($row['capacity']) && (!is_numeric($row['capacity']) || (int) $row['capacity'] < 1)) {
                    $errors[] = 'capacity must be a positive integer';
                }
                break;
            case 'enrollment':
                $require(['course_code', 'term_code', 'enrolled']);
                if (($row['course_code'] ?? '') !== ''
                    && DB::selectOne('SELECT id FROM courses WHERE code = ? AND tenant_id = ?',
                        [$row['course_code'], Tenancy::requireId()]) === null) {
                    $errors[] = 'Unknown course: ' . $row['course_code'];
                }
                if (($row['term_code'] ?? '') !== ''
                    && DB::selectOne('SELECT id FROM terms WHERE code = ? AND tenant_id = ?',
                        [$row['term_code'], Tenancy::requireId()]) === null) {
                    $errors[] = 'Unknown term: ' . $row['term_code'];
                }
                break;
            case 'availability':
                $require(['faculty_email', 'day', 'start_time', 'end_time']);
                if (!in_array($row['day'] ?? '', ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'], true)) {
                    $errors[] = 'day must be one of Mon..Sun';
                }
                break;
        }

        return $errors;
    }

    /** Insert the valid rows of a validated batch. */
    public function commit(int $batchId, array $validRows): array
    {
        $batch = DB::selectOne('SELECT * FROM import_batches WHERE id = ?', [$batchId]);
        if ($batch === null || $batch['status'] !== 'validated') {
            throw new \RuntimeException('Batch not found or not in validated state');
        }

        $createdIds = DB::transaction(function () use ($batch, $validRows) {
            $ids = [];
            foreach ($validRows as $row) {
                $ids[] = $this->insertRow((string) $batch['entity_type'], $row);
            }

            return $ids;
        });

        DB::update('import_batches', $batchId, [
            'status' => 'committed',
            'created_ids' => json_encode($createdIds),
        ]);
        Audit::log('import_commit', 'import_batch', $batchId, null, ['created' => count($createdIds)]);

        return ['created' => count($createdIds), 'ids' => $createdIds];
    }

    private function insertRow(string $entityType, array $row): array
    {
        switch ($entityType) {
            case 'faculty':
                $id = DB::insert('faculty', [
                    'tenant_id' => Tenancy::requireId(),
                    'department_id' => $this->departmentId((string) $row['department_code']),
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'email' => $row['email'],
                    'rank' => $row['rank'] ?? 'lecturer',
                    'contract_type' => $row['contract_type'] ?? 'full_time',
                    'max_credit_hours' => (float) ($row['max_credit_hours'] ?? 12),
                ]);

                return ['table' => 'faculty', 'id' => $id];
            case 'courses':
                $id = DB::insert('courses', [
                    'tenant_id' => Tenancy::requireId(),
                    'department_id' => $this->departmentId((string) $row['department_code']),
                    'code' => strtoupper((string) $row['code']),
                    'title' => $row['title'],
                    'credit_hours' => (float) $row['credit_hours'],
                    'contact_hours' => (float) ($row['contact_hours'] ?? $row['credit_hours']),
                    'default_capacity' => (int) ($row['capacity'] ?? 30),
                ]);

                return ['table' => 'courses', 'id' => $id];
            case 'rooms':
                $id = DB::insert('rooms', [
                    'tenant_id' => Tenancy::requireId(),
                    'code' => strtoupper((string) $row['code']),
                    'name' => $row['name'],
                    'type' => $row['type'] ?? 'classroom',
                    'capacity' => (int) $row['capacity'],
                    'equipment' => isset($row['equipment'])
                        ? json_encode(array_map('trim', explode('|', (string) $row['equipment'])))
                        : null,
                ]);

                return ['table' => 'rooms', 'id' => $id];
            case 'enrollment':
                $course = DB::selectOne('SELECT id FROM courses WHERE code = ? AND tenant_id = ?',
                    [$row['course_code'], Tenancy::requireId()]);
                $term = DB::selectOne('SELECT id FROM terms WHERE code = ? AND tenant_id = ?',
                    [$row['term_code'], Tenancy::requireId()]);
                $id = DB::insert('enrollment_history', [
                    'course_id' => (int) $course['id'],
                    'term_id' => (int) $term['id'],
                    'enrolled' => (int) $row['enrolled'],
                    'waitlisted' => (int) ($row['waitlisted'] ?? 0),
                    'sections_offered' => (int) ($row['sections_offered'] ?? 1),
                ]);

                return ['table' => 'enrollment_history', 'id' => $id];
            case 'availability':
                $faculty = DB::selectOne('SELECT id FROM faculty WHERE email = ? AND tenant_id = ?',
                    [$row['faculty_email'], Tenancy::requireId()]);
                if ($faculty === null) {
                    throw new \RuntimeException('Unknown faculty: ' . $row['faculty_email']);
                }
                $id = DB::insert('faculty_availability', [
                    'faculty_id' => (int) $faculty['id'],
                    'day' => $row['day'],
                    'start_time' => $row['start_time'],
                    'end_time' => $row['end_time'],
                    'preference' => $row['preference'] ?? 'available',
                ]);

                return ['table' => 'faculty_availability', 'id' => $id];
        }

        throw new \InvalidArgumentException("Unknown entity: $entityType");
    }

    /** Delete everything a committed batch created. */
    public function rollback(int $batchId): int
    {
        $batch = DB::selectOne('SELECT * FROM import_batches WHERE id = ?', [$batchId]);
        if ($batch === null || $batch['status'] !== 'committed') {
            throw new \RuntimeException('Batch not found or not committed');
        }
        $created = json_decode((string) $batch['created_ids'], true) ?: [];

        $deleted = DB::transaction(function () use ($created) {
            $count = 0;
            foreach (array_reverse($created) as $ref) {
                if (preg_match('/^[a-z_]+$/', (string) $ref['table'])) {
                    $count += DB::execute("DELETE FROM `{$ref['table']}` WHERE id = ?", [(int) $ref['id']]);
                }
            }

            return $count;
        });

        DB::update('import_batches', $batchId, ['status' => 'rolled_back']);
        Audit::log('import_rollback', 'import_batch', $batchId, null, ['deleted' => $deleted]);

        return $deleted;
    }

    private function departmentId(string $code): ?int
    {
        $row = DB::selectOne('SELECT id FROM departments WHERE code = ? AND tenant_id = ?',
            [$code, Tenancy::requireId()]);

        return $row === null ? null : (int) $row['id'];
    }
}
