<?php

declare(strict_types=1);

/** Web (HTML) routes — Bootstrap 5 UI rendered server-side. */

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Services\AnalyticsService;
use App\Services\WorkloadService;

return function (Router $r, array $config): void {
    $analytics = new AnalyticsService();
    $workload = new WorkloadService();

    $currentTerm = function (Request $req): array {
        $termId = (int) ($req->query['term_id'] ?? 0);
        $term = $termId > 0
            ? DB::selectOne('SELECT * FROM terms WHERE id = ?', [$termId])
            : DB::selectOne('SELECT * FROM terms WHERE status IN ("draft","planning","review") ORDER BY start_date LIMIT 1');
        $term ??= DB::selectOne('SELECT * FROM terms ORDER BY start_date DESC LIMIT 1');

        return $term ?? ['id' => 0, 'name' => 'No terms defined', 'code' => '—'];
    };
    $allTerms = fn () => DB::select('SELECT id, code, name, status FROM terms ORDER BY start_date DESC');

    // ---------------- Auth pages ----------------
    $r->get('/login', function () use ($config) {
        if (Auth::user() !== null) {
            Response::redirect('/');
        }
        Response::html(View::render('login', ['app' => $config['app'], 'error' => null], null));
    });

    $r->post('/login', function (Request $req) use ($config) {
        if (Auth::attempt((string) $req->input('email'), (string) $req->input('password'))) {
            Response::redirect('/');
        }
        Response::html(View::render('login', ['app' => $config['app'], 'error' => 'Invalid email or password.'], null), 401);
    });

    $r->get('/logout', function () {
        Auth::logout();
        Response::redirect('/login');
    });

    $page = function (string $view, string $title, callable $data) use ($config, $currentTerm, $allTerms) {
        return function (Request $req) use ($view, $title, $data, $config, $currentTerm, $allTerms) {
            $term = $currentTerm($req);
            Response::html(View::render($view, [
                'app' => $config['app'],
                'title' => $title,
                'active' => $view,
                'term' => $term,
                'terms' => $allTerms(),
                'user' => Auth::user(),
            ] + $data($req, $term)));
        };
    };

    // ---------------- Dashboard ----------------
    $r->get('/', $page('dashboard', 'Dashboard', function (Request $req, array $term) use ($analytics, $workload) {
        $termId = (int) $term['id'];

        return [
            'kpis' => $analytics->schedulingKpis($termId),
            'rooms' => $analytics->roomUtilization($termId),
            'enrollment' => $analytics->enrollmentKpis($termId),
            'heatmap' => $analytics->scheduleHeatmap($termId),
            'deptAnalytics' => $workload->departmentAnalytics($termId),
            'recommendations' => DB::select(
                'SELECT * FROM ai_recommendations WHERE term_id = ? AND status = "open" ORDER BY id DESC LIMIT 8',
                [$termId]),
        ];
    }), 'reports.view');

    // ---------------- Scheduler (drag & drop board) ----------------
    $r->get('/scheduler', $page('scheduler', 'Scheduler', function (Request $req, array $term) {
        return [
            'departments' => DB::select('SELECT * FROM departments WHERE is_active = 1 ORDER BY code'),
            'rooms' => DB::select('SELECT id, code, capacity, type FROM rooms WHERE is_active = 1 ORDER BY code'),
            'patterns' => DB::select('SELECT * FROM meeting_patterns ORDER BY start_time'),
            'conflicts' => DB::select(
                'SELECT * FROM schedule_conflicts WHERE term_id = ? AND status = "open" ORDER BY severity',
                [(int) $term['id']]),
        ];
    }), 'schedule.view');

    // ---------------- Faculty & workload ----------------
    $r->get('/faculty', $page('faculty', 'Faculty', function () {
        return [
            'faculty' => DB::select(
                'SELECT f.*, d.code department,
                        (SELECT COUNT(*) FROM faculty_course_qualifications q WHERE q.faculty_id = f.id) qualifications_count
                 FROM faculty f JOIN departments d ON d.id = f.department_id
                 ORDER BY d.code, f.last_name'),
        ];
    }), 'faculty.view');

    $r->get('/workload', $page('workload', 'Workload', function (Request $req, array $term) use ($workload) {
        $termId = (int) $term['id'];

        return [
            'workloads' => $workload->facultyWorkloads($termId),
            'deptAnalytics' => $workload->departmentAnalytics($termId),
            'suggestions' => $workload->rebalancingSuggestions($termId),
        ];
    }), 'workload.view');

    // ---------------- Rooms ----------------
    $r->get('/rooms', $page('rooms', 'Rooms', function (Request $req, array $term) use ($analytics) {
        return [
            'rooms' => DB::select(
                'SELECT r.*, b.code building, c.code campus FROM rooms r
                 LEFT JOIN buildings b ON b.id = r.building_id
                 LEFT JOIN campuses c ON c.id = b.campus_id
                 ORDER BY r.code'),
            'utilization' => $analytics->roomUtilization((int) $term['id']),
        ];
    }), 'rooms.view');

    // ---------------- Reports & exports ----------------
    $r->get('/reports', $page('reports', 'Reports', function (Request $req, array $term) {
        return [
            'departments' => DB::select('SELECT * FROM departments WHERE is_active = 1 ORDER BY code'),
            'facultyList' => DB::select(
                'SELECT id, CONCAT(first_name, " ", last_name) name FROM faculty ORDER BY last_name'),
            'scheduledReports' => DB::select('SELECT * FROM scheduled_reports ORDER BY id DESC'),
        ];
    }), 'reports.view');

    // ---------------- Imports ----------------
    $r->get('/imports', $page('imports', 'Bulk Import', function () {
        return [
            'batches' => DB::select(
                'SELECT b.*, u.name user FROM import_batches b JOIN users u ON u.id = b.user_id
                 ORDER BY b.id DESC LIMIT 20'),
        ];
    }), 'imports.run');

    // ---------------- Scenarios ----------------
    $r->get('/scenarios', $page('scenarios', 'Scenario Planning', function (Request $req, array $term) {
        return [
            'scenarios' => DB::select(
                'SELECT * FROM scenarios WHERE term_id = ? ORDER BY id DESC', [(int) $term['id']]),
            'facultyList' => DB::select(
                'SELECT id, CONCAT(first_name, " ", last_name) name FROM faculty WHERE status = "active" ORDER BY last_name'),
            'roomList' => DB::select('SELECT id, code FROM rooms WHERE is_active = 1 ORDER BY code'),
        ];
    }), 'scenarios.run');

    // ---------------- Workflow ----------------
    $r->get('/workflow', $page('workflow', 'Approvals', function (Request $req, array $term) {
        $departments = DB::select('SELECT * FROM departments WHERE is_active = 1 ORDER BY code');
        $workflows = DB::select(
            'SELECT w.*, d.code department FROM approval_workflows w
             JOIN departments d ON d.id = w.department_id WHERE w.term_id = ?',
            [(int) $term['id']]);

        return ['departments' => $departments, 'workflows' => $workflows];
    }), 'schedule.view');

    // ---------------- AI assistant ----------------
    $r->get('/assistant', $page('assistant', 'AI Assistant', function () use ($config) {
        return ['aiConfigured' => ($config['ai']['api_key'] ?? '') !== ''];
    }), 'ai.chat');
};
