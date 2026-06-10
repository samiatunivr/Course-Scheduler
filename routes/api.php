<?php

declare(strict_types=1);

/**
 * REST API v1. All endpoints require authentication (session or Bearer
 * token) and the permission listed on each route. JSON in, JSON out.
 */

use App\Core\Auth;
use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Services\AIService;
use App\Services\AnalyticsService;
use App\Services\ExportService;
use App\Services\ForecastService;
use App\Services\ImportService;
use App\Services\NotificationService;
use App\Services\ScenarioService;
use App\Services\SchedulingEngine;
use App\Services\WorkflowService;
use App\Services\WorkloadService;

return function (Router $r, array $config): void {
    $engine = new SchedulingEngine();
    $workload = new WorkloadService();
    $analytics = new AnalyticsService();
    $forecast = new ForecastService();
    $ai = new AIService($config['ai']);
    $export = new ExportService($config['app']);
    $import = new ImportService();
    $workflow = new WorkflowService($engine);
    $scenario = new ScenarioService($engine, $analytics);
    $notifier = new NotificationService($config['mail'], $config['integrations']);

    // ---------------- Auth ----------------
    $r->post('/api/v1/auth/login', function (Request $req) {
        $email = (string) $req->input('email');
        $password = (string) $req->input('password');
        $result = Auth::attempt($email, $password);
        if ($result === 'fail') {
            Response::error('Invalid credentials', 401);
        }
        if ($result === 'mfa') {
            $otp = (string) $req->input('otp', '');
            if ($otp === '') {
                Response::error('MFA code required — retry with an "otp" field', 401, ['code' => 'mfa_required']);
            }
            if (!Auth::verifyMfa($otp)) {
                Response::error('Invalid MFA code', 401, ['code' => 'mfa_invalid']);
            }
        }
        $token = Auth::issueApiToken((int) Auth::id(), 'api-login', date('Y-m-d H:i:s', strtotime('+30 days')));
        Response::json(['token' => $token, 'user' => Auth::user(), 'permissions' => Auth::permissions()]);
    });

    $r->get('/api/v1/me', function () {
        Response::json(['user' => Auth::user(), 'permissions' => Auth::permissions()]);
    }, 'schedule.view');

    // ---------------- Reference data ----------------
    $r->get('/api/v1/terms', function () {
        Response::json(DB::select('SELECT * FROM terms ORDER BY start_date DESC'));
    }, 'schedule.view');

    $r->get('/api/v1/departments', function () {
        Response::json(DB::select('SELECT * FROM departments WHERE is_active = 1 ORDER BY code'));
    }, 'schedule.view');

    $r->get('/api/v1/courses', function (Request $req) {
        $deptId = $req->query['department_id'] ?? null;
        $rows = $deptId !== null
            ? DB::select('SELECT * FROM courses WHERE is_active = 1 AND department_id = ? ORDER BY code', [(int) $deptId])
            : DB::select('SELECT * FROM courses WHERE is_active = 1 ORDER BY code');
        Response::json($rows);
    }, 'schedule.view');

    $r->get('/api/v1/rooms', function () {
        Response::json(DB::select(
            'SELECT r.*, b.code building FROM rooms r LEFT JOIN buildings b ON b.id = r.building_id
             WHERE r.is_active = 1 ORDER BY r.code'
        ));
    }, 'rooms.view');

    $r->get('/api/v1/meeting-patterns', function () {
        Response::json(DB::select('SELECT * FROM meeting_patterns ORDER BY days, start_time'));
    }, 'schedule.view');

    // ---------------- Faculty ----------------
    $r->get('/api/v1/faculty', function (Request $req) {
        $deptId = $req->query['department_id'] ?? null;
        $sql = 'SELECT f.*, d.code department FROM faculty f JOIN departments d ON d.id = f.department_id';
        $rows = $deptId !== null
            ? DB::select($sql . ' WHERE f.department_id = ? ORDER BY f.last_name', [(int) $deptId])
            : DB::select($sql . ' ORDER BY f.last_name');
        Response::json($rows);
    }, 'faculty.view');

    $r->get('/api/v1/faculty/{id}', function (Request $req) {
        $id = (int) $req->param('id');
        $faculty = DB::selectOne('SELECT * FROM faculty WHERE id = ?', [$id]);
        if ($faculty === null) {
            Response::error('Faculty not found', 404);
        }
        $faculty['availability'] = DB::select(
            'SELECT day, start_time, end_time, preference FROM faculty_availability WHERE faculty_id = ?', [$id]);
        $faculty['course_qualifications'] = DB::select(
            'SELECT c.code, c.title, q.level, q.times_taught FROM faculty_course_qualifications q
             JOIN courses c ON c.id = q.course_id WHERE q.faculty_id = ?', [$id]);
        $faculty['teaching_history'] = DB::select(
            'SELECT t.code term, c.code course, s.section_no FROM sections s
             JOIN terms t ON t.id = s.term_id JOIN courses c ON c.id = s.course_id
             WHERE s.faculty_id = ? ORDER BY t.start_date DESC LIMIT 40', [$id]);
        Response::json($faculty);
    }, 'faculty.view');

    $r->post('/api/v1/faculty', function (Request $req) {
        $required = ['department_id', 'first_name', 'last_name', 'email'];
        foreach ($required as $f) {
            if (!$req->input($f)) {
                Response::error("Missing field: $f", 422);
            }
        }
        $id = DB::insert('faculty', array_intersect_key($req->body, array_flip([
            'department_id', 'first_name', 'last_name', 'email', 'rank', 'contract_type',
            'max_credit_hours', 'min_credit_hours', 'max_contact_hours',
            'research_release_hours', 'admin_release_hours',
        ])));
        Audit::log('create', 'faculty', $id, null, $req->body);
        Response::json(['id' => $id], 201);
    }, 'faculty.edit');

    // ---------------- Sections & schedule ----------------
    $r->get('/api/v1/terms/{termId}/sections', function (Request $req) {
        Response::json(DB::select(
            'SELECT s.id, s.section_no, s.status, s.capacity, s.enrolled, s.waitlisted, s.delivery_mode,
                    c.code course, c.title, c.credit_hours, c.department_id,
                    CONCAT(f.first_name, " ", f.last_name) instructor, s.faculty_id,
                    m.id meeting_id, m.day, m.start_time, m.end_time, m.kind, m.room_id, r.code room
             FROM sections s
             JOIN courses c ON c.id = s.course_id
             LEFT JOIN faculty f ON f.id = s.faculty_id
             LEFT JOIN section_meetings m ON m.section_id = s.id
             LEFT JOIN rooms r ON r.id = m.room_id
             WHERE s.term_id = ? AND s.status <> "cancelled"
             ORDER BY c.code, s.section_no',
            [(int) $req->param('termId')]
        ));
    }, 'schedule.view');

    $r->post('/api/v1/sections', function (Request $req) {
        foreach (['course_id', 'term_id'] as $f) {
            if (!$req->input($f)) {
                Response::error("Missing field: $f", 422);
            }
        }
        $id = DB::insert('sections', [
            'course_id' => $req->int('course_id'),
            'term_id' => $req->int('term_id'),
            'section_no' => (string) $req->input('section_no', '01'),
            'faculty_id' => $req->input('faculty_id') ? $req->int('faculty_id') : null,
            'capacity' => $req->int('capacity', 30),
            'delivery_mode' => (string) $req->input('delivery_mode', 'on_campus'),
            'status' => 'draft',
        ]);
        foreach ((array) $req->input('meetings', []) as $m) {
            DB::insert('section_meetings', [
                'section_id' => $id,
                'room_id' => !empty($m['room_id']) ? (int) $m['room_id'] : null,
                'day' => $m['day'],
                'start_time' => $m['start_time'],
                'end_time' => $m['end_time'],
                'kind' => $m['kind'] ?? 'lecture',
            ]);
        }
        Audit::log('create', 'section', $id, null, $req->body);
        Response::json(['id' => $id], 201);
    }, 'schedule.edit');

    $r->put('/api/v1/sections/{id}', function (Request $req) use ($notifier) {
        $id = (int) $req->param('id');
        $old = DB::selectOne('SELECT * FROM sections WHERE id = ?', [$id]);
        if ($old === null) {
            Response::error('Section not found', 404);
        }
        $fields = array_intersect_key($req->body, array_flip([
            'faculty_id', 'capacity', 'status', 'delivery_mode', 'notes', 'section_no',
        ]));
        if ($fields !== []) {
            DB::update('sections', $id, $fields);
        }
        // Notify the newly assigned instructor (if linked to a user account).
        if (isset($fields['faculty_id']) && (int) $fields['faculty_id'] !== (int) $old['faculty_id']) {
            $fac = DB::selectOne('SELECT user_id FROM faculty WHERE id = ?', [(int) $fields['faculty_id']]);
            if (!empty($fac['user_id'])) {
                $course = DB::selectOne(
                    'SELECT c.code FROM courses c JOIN sections s ON s.course_id = c.id WHERE s.id = ?', [$id]);
                $notifier->notify((int) $fac['user_id'], 'assignment', 'New course assignment',
                    sprintf('You have been assigned to %s section %s.', $course['code'] ?? '?', $old['section_no']),
                    ['inapp', 'email']);
            }
        }
        Audit::log('update', 'section', $id, $old, $fields);
        Response::json(['updated' => true]);
    }, 'schedule.edit');

    // Drag-and-drop reschedule of a single meeting
    $r->put('/api/v1/meetings/{id}', function (Request $req) use ($engine) {
        $id = (int) $req->param('id');
        $old = DB::selectOne('SELECT * FROM section_meetings WHERE id = ?', [$id]);
        if ($old === null) {
            Response::error('Meeting not found', 404);
        }
        $fields = array_intersect_key($req->body, array_flip(['day', 'start_time', 'end_time', 'room_id']));
        if ($fields !== []) {
            DB::update('section_meetings', $id, $fields);
        }
        Audit::log('update', 'section_meeting', $id, $old, $fields);

        // Real-time conflict feedback for the moved section's term.
        $term = DB::selectOne(
            'SELECT s.term_id FROM sections s JOIN section_meetings m ON m.section_id = s.id WHERE m.id = ?', [$id]);
        $conflicts = $engine->detectConflicts((int) $term['term_id']);
        Response::json(['updated' => true, 'open_conflicts' => count($conflicts)]);
    }, 'schedule.edit');

    $r->delete('/api/v1/sections/{id}', function (Request $req) {
        $id = (int) $req->param('id');
        $old = DB::selectOne('SELECT * FROM sections WHERE id = ?', [$id]);
        if ($old === null) {
            Response::error('Section not found', 404);
        }
        DB::update('sections', $id, ['status' => 'cancelled']);
        Audit::log('cancel', 'section', $id, $old);
        Response::json(['cancelled' => true]);
    }, 'schedule.edit');

    // ---------------- AI engine ----------------
    $r->post('/api/v1/terms/{termId}/generate', function (Request $req) use ($engine) {
        Response::json($engine->generateSchedule(
            (int) $req->param('termId'),
            $req->input('department_id') ? $req->int('department_id') : null,
            [
                'replace' => (bool) $req->input('replace', false),
                'dry_run' => (bool) $req->input('dry_run', false),
                'enrollment_delta_pct' => (float) $req->input('enrollment_delta_pct', 0),
            ]
        ));
    }, 'schedule.generate');

    $r->post('/api/v1/terms/{termId}/detect-conflicts', function (Request $req) use ($engine) {
        $conflicts = $engine->detectConflicts((int) $req->param('termId'));
        Response::json(['count' => count($conflicts), 'conflicts' => $conflicts]);
    }, 'schedule.view');

    $r->get('/api/v1/terms/{termId}/conflicts', function (Request $req) {
        Response::json(DB::select(
            'SELECT * FROM schedule_conflicts WHERE term_id = ? AND status = "open" ORDER BY severity, type',
            [(int) $req->param('termId')]
        ));
    }, 'schedule.view');

    $r->post('/api/v1/terms/{termId}/recommendations', function (Request $req) use ($ai) {
        Response::json($ai->generateRecommendations((int) $req->param('termId')));
    }, 'schedule.generate');

    $r->get('/api/v1/terms/{termId}/recommendations', function (Request $req) {
        Response::json(DB::select(
            'SELECT * FROM ai_recommendations WHERE term_id = ? AND status = "open" ORDER BY category',
            [(int) $req->param('termId')]
        ));
    }, 'schedule.view');

    $r->post('/api/v1/terms/{termId}/forecast', function (Request $req) use ($forecast) {
        Response::json($forecast->forecastTerm((int) $req->param('termId')));
    }, 'schedule.generate');

    $r->post('/api/v1/ai/chat', function (Request $req) use ($ai) {
        $message = trim((string) $req->input('message'));
        if ($message === '') {
            Response::error('message is required', 422);
        }
        $sessionId = (string) $req->input('session_id', session_id() ?: 'default');
        $termId = $req->int('term_id');
        Response::json($ai->chat($message, $sessionId, $termId));
    }, 'ai.chat');

    // ---------------- Workload ----------------
    $r->get('/api/v1/terms/{termId}/workloads', function (Request $req) use ($workload) {
        Response::json($workload->facultyWorkloads(
            (int) $req->param('termId'),
            $req->input('department_id') ? $req->int('department_id') : null
        ));
    }, 'workload.view');

    $r->get('/api/v1/terms/{termId}/workloads/analytics', function (Request $req) use ($workload) {
        Response::json($workload->departmentAnalytics((int) $req->param('termId')));
    }, 'workload.view');

    $r->get('/api/v1/terms/{termId}/workloads/rebalance', function (Request $req) use ($workload) {
        Response::json($workload->rebalancingSuggestions((int) $req->param('termId')));
    }, 'workload.view');

    $r->post('/api/v1/workload-activities', function (Request $req) {
        foreach (['faculty_id', 'term_id', 'type', 'description'] as $f) {
            if (!$req->input($f)) {
                Response::error("Missing field: $f", 422);
            }
        }
        $id = DB::insert('workload_activities', [
            'faculty_id' => $req->int('faculty_id'),
            'term_id' => $req->int('term_id'),
            'type' => (string) $req->input('type'),
            'description' => (string) $req->input('description'),
            'credit_hour_equivalent' => (float) $req->input('credit_hour_equivalent', 0),
        ]);
        Audit::log('create', 'workload_activity', $id, null, $req->body);
        Response::json(['id' => $id], 201);
    }, 'workload.edit');

    // ---------------- Analytics ----------------
    $r->get('/api/v1/terms/{termId}/kpis', function (Request $req) use ($analytics) {
        $termId = (int) $req->param('termId');
        Response::json([
            'scheduling' => $analytics->schedulingKpis($termId),
            'rooms' => $analytics->roomUtilization($termId),
            'enrollment' => $analytics->enrollmentKpis($termId),
            'heatmap' => $analytics->scheduleHeatmap($termId),
        ]);
    }, 'reports.view');

    $r->get('/api/v1/enrollment/trends', function (Request $req) use ($analytics) {
        Response::json($analytics->enrollmentTrends(
            $req->input('course_id') ? $req->int('course_id') : null
        ));
    }, 'reports.view');

    // ---------------- Exports (one-click) ----------------
    // /api/v1/export/{report}/{format}?term_id=&department_id=&faculty_id=
    $r->get('/api/v1/export/{report}/{format}', function (Request $req) use ($export) {
        $termId = $req->int('term_id');
        if ($termId === 0) {
            Response::error('term_id query parameter is required', 422);
        }
        $term = DB::selectOne('SELECT name FROM terms WHERE id = ?', [$termId]);
        $meta = ['term' => $term['name'] ?? $termId];
        $report = $req->param('report');
        $rows = match ($report) {
            'faculty-schedule' => $export->facultyScheduleDataset(
                $termId, $req->input('faculty_id') ? $req->int('faculty_id') : null),
            'department-schedule' => $export->departmentScheduleDataset(
                $termId, $req->input('department_id') ? $req->int('department_id') : null),
            'workload' => $export->workloadDataset(
                $termId, $req->input('department_id') ? $req->int('department_id') : null),
            'utilization' => $export->utilizationDataset($termId),
            'conflicts' => $export->conflictsDataset($termId),
            default => Response::error("Unknown report: $report", 404),
        };
        $export->send($rows, $req->param('format'), str_replace('-', '_', $report), $meta);
    }, 'reports.export');

    // ---------------- Imports ----------------
    $r->post('/api/v1/import/{entity}/validate', function (Request $req) use ($import) {
        if (empty($_FILES['file']['tmp_name'])) {
            Response::error('No file uploaded (multipart field "file")', 422);
        }
        $rows = $import->parseCsv($_FILES['file']['tmp_name']);
        $result = $import->validate($req->param('entity'), (string) $_FILES['file']['name'], $rows);
        $_SESSION['import_' . $result['batch_id']] = $result['valid_rows'];
        unset($result['valid_rows']);
        Response::json($result);
    }, 'imports.run');

    $r->post('/api/v1/import/batches/{id}/commit', function (Request $req) use ($import) {
        $batchId = (int) $req->param('id');
        $validRows = $_SESSION['import_' . $batchId] ?? null;
        if ($validRows === null) {
            Response::error('Validated rows expired — re-upload the file', 410);
        }
        $result = $import->commit($batchId, $validRows);
        unset($_SESSION['import_' . $batchId]);
        Response::json($result);
    }, 'imports.run');

    $r->post('/api/v1/import/batches/{id}/rollback', function (Request $req) use ($import) {
        Response::json(['deleted' => $import->rollback((int) $req->param('id'))]);
    }, 'imports.run');

    // ---------------- Scenarios ----------------
    $r->post('/api/v1/terms/{termId}/scenarios', function (Request $req) use ($scenario) {
        Response::json($scenario->simulate(
            (int) $req->param('termId'),
            (string) $req->input('name', 'Untitled scenario'),
            (array) $req->input('parameters', [])
        ));
    }, 'scenarios.run');

    $r->get('/api/v1/terms/{termId}/scenarios', function (Request $req) {
        Response::json(DB::select(
            'SELECT id, name, description, parameters, result, status, created_at
             FROM scenarios WHERE term_id = ? ORDER BY id DESC',
            [(int) $req->param('termId')]
        ));
    }, 'scenarios.run');

    $r->post('/api/v1/scenarios/{id}/apply', function (Request $req) use ($scenario) {
        Response::json($scenario->apply((int) $req->param('id')));
    }, 'schedule.generate');

    // ---------------- Workflow ----------------
    $r->get('/api/v1/terms/{termId}/workflow/{deptId}', function (Request $req) use ($workflow) {
        $termId = (int) $req->param('termId');
        $deptId = (int) $req->param('deptId');
        Response::json([
            'workflow' => $workflow->getOrCreate($termId, $deptId),
            'steps' => WorkflowService::STEPS,
            'history' => $workflow->history($termId, $deptId),
        ]);
    }, 'schedule.view');

    $r->post('/api/v1/terms/{termId}/workflow/{deptId}/advance', function (Request $req) use ($workflow) {
        Response::json($workflow->advance(
            (int) $req->param('termId'), (int) $req->param('deptId'),
            (string) $req->input('comment', '')
        ));
    }, 'schedule.edit');

    $r->post('/api/v1/terms/{termId}/workflow/{deptId}/reject', function (Request $req) use ($workflow) {
        Response::json($workflow->reject(
            (int) $req->param('termId'), (int) $req->param('deptId'),
            (string) $req->input('comment', 'Rejected')
        ));
    }, 'schedule.approve');

    // ---------------- Notifications ----------------
    $r->get('/api/v1/notifications', function () {
        Response::json(DB::select(
            'SELECT * FROM notifications WHERE user_id = ? ORDER BY id DESC LIMIT 50',
            [(int) Auth::id()]
        ));
    }, 'schedule.view');

    $r->post('/api/v1/notifications/{id}/read', function (Request $req) {
        DB::execute('UPDATE notifications SET read_at = NOW() WHERE id = ? AND user_id = ?',
            [(int) $req->param('id'), (int) Auth::id()]);
        Response::json(['read' => true]);
    }, 'schedule.view');
};
