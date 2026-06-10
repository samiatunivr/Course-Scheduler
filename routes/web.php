<?php

declare(strict_types=1);

/** Web (HTML) routes — Bootstrap 5 UI rendered server-side. */

use App\Core\Auth;
use App\Core\Database as DB;
use App\Core\Tenancy;
use App\Core\Request;
use App\Core\Response;
use App\Core\Router;
use App\Core\View;
use App\Services\AnalyticsService;
use App\Services\SamlService;
use App\Services\TotpService;
use App\Services\WorkloadService;

return function (Router $r, array $config): void {
    $analytics = new AnalyticsService();
    $workload = new WorkloadService();
    $saml = new SamlService($config['saml']);
    $totp = new TotpService();

    $currentTerm = function (Request $req): array {
        $tenantId = Tenancy::requireId();
        $termId = (int) ($req->query['term_id'] ?? 0);
        $term = $termId > 0
            ? DB::selectOne('SELECT * FROM terms WHERE id = ? AND tenant_id = ?', [$termId, $tenantId])
            : DB::selectOne('SELECT * FROM terms WHERE tenant_id = ? AND status IN ("draft","planning","review") ORDER BY start_date LIMIT 1', [$tenantId]);
        $term ??= DB::selectOne('SELECT * FROM terms WHERE tenant_id = ? ORDER BY start_date DESC LIMIT 1', [$tenantId]);

        return $term ?? ['id' => 0, 'name' => 'No terms defined', 'code' => '—'];
    };
    $allTerms = fn () => DB::select(
        'SELECT id, code, name, status FROM terms WHERE tenant_id = ? ORDER BY start_date DESC',
        [Tenancy::requireId()]
    );

    // ---------------- Auth pages ----------------
    $loginView = fn (?string $error, int $status = 200) => Response::html(
        View::render('login', [
            'app' => $config['app'],
            'error' => $error,
            'samlEnabled' => $saml->isConfigured(),
        ], null),
        $status
    );

    $r->get('/login', function () use ($loginView) {
        if (Auth::user() !== null) {
            Response::redirect('/');
        }
        $loginView(null);
    });

    $r->post('/login', function (Request $req) use ($loginView) {
        $result = Auth::attempt((string) $req->input('email'), (string) $req->input('password'));
        match ($result) {
            'ok' => Response::redirect('/'),
            'mfa' => Response::redirect('/mfa'),
            default => $loginView('Invalid email or password.', 401),
        };
    });

    // ---- MFA challenge (password already verified) ----
    $r->get('/mfa', function () use ($config) {
        if (!isset($_SESSION['mfa_pending_user'])) {
            Response::redirect('/login');
        }
        Response::html(View::render('mfa', ['app' => $config['app'], 'error' => null], null));
    });

    $r->post('/mfa', function (Request $req) use ($config) {
        if (!isset($_SESSION['mfa_pending_user'])) {
            Response::redirect('/login');
        }
        if (Auth::verifyMfa((string) $req->input('code'))) {
            Response::redirect('/');
        }
        Response::html(View::render('mfa', [
            'app' => $config['app'],
            'error' => 'Invalid or already-used code. Try the next code from your app, or a recovery code.',
        ], null), 401);
    });

    $r->get('/logout', function () {
        Auth::logout();
        Response::redirect('/login');
    });

    // ---- SAML 2.0 SSO ----
    $r->get('/auth/saml', function () use ($saml) {
        if (!$saml->isConfigured()) {
            Response::redirect('/login');
        }
        [$url, $requestId] = $saml->buildLoginRedirect('/');
        $_SESSION['saml_request_id'] = $requestId;
        Response::redirect($url);
    });

    $r->post('/auth/saml/acs', function (Request $req) use ($saml, $config, $loginView) {
        if (!$saml->isConfigured()) {
            Response::redirect('/login');
        }
        try {
            $identity = $saml->consumeResponse(
                (string) ($_POST['SAMLResponse'] ?? ''),
                $_SESSION['saml_request_id'] ?? null
            );
        } catch (\RuntimeException $e) {
            error_log('SAML login failed: ' . $e->getMessage());
            $loginView('Single sign-on failed: ' . $e->getMessage(), 401);
        }
        unset($_SESSION['saml_request_id']);

        $user = DB::selectOne('SELECT id, is_active FROM users WHERE email = ?', [$identity['email']]);
        if ($user === null && $config['saml']['auto_provision']) {
            // Tenant comes from the email domain (tenants.domain); refuse
            // provisioning when the domain maps to no institution.
            $tenantId = Tenancy::resolveByEmailDomain($identity['email']);
            if ($tenantId === null) {
                $loginView('Single sign-on succeeded but the email domain of '
                    . $identity['email'] . ' is not registered to any institution.', 403);
            }
            $name = $identity['attributes']['displayName'][0]
                ?? $identity['attributes']['http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name'][0]
                ?? $identity['email'];
            $userId = DB::insert('users', [
                'tenant_id' => $tenantId,
                'email' => $identity['email'],
                'name' => $name,
                'sso_provider' => 'saml',
                'sso_subject' => $identity['name_id'],
            ]);
            DB::execute(
                'INSERT IGNORE INTO user_roles (user_id, role_id, department_id)
                 SELECT ?, id, NULL FROM roles WHERE code = ?',
                [$userId, $config['saml']['default_role']]
            );
            $user = ['id' => $userId, 'is_active' => 1];
        }
        if ($user === null || (int) $user['is_active'] !== 1) {
            $loginView('Single sign-on succeeded but no active account exists for '
                . $identity['email'] . '. Ask an administrator to create one.', 403);
        }
        Auth::loginAs((int) $user['id'], 'saml');
        $relay = (string) ($_POST['RelayState'] ?? '/');
        Response::redirect(str_starts_with($relay, '/') && !str_starts_with($relay, '//') ? $relay : '/');
    });

    $r->get('/auth/saml/metadata', function () use ($saml) {
        header('Content-Type: application/xml; charset=utf-8');
        echo $saml->metadataXml();
        exit;
    });

    // ---- Account security (MFA self-service, any authenticated user) ----
    $requireLogin = function (): array {
        $user = Auth::user();
        if ($user === null) {
            Response::redirect('/login');
        }

        return $user;
    };

    $r->get('/security', function (Request $req) use ($config, $requireLogin, $totp, $allTerms) {
        $user = $requireLogin();
        $row = DB::selectOne('SELECT mfa_enabled, mfa_recovery_codes FROM users WHERE id = ?', [(int) $user['id']]);

        // Stage a fresh secret in the session until the user confirms a code.
        $pendingSecret = null;
        $pendingUri = null;
        if ((int) $row['mfa_enabled'] !== 1) {
            $pendingSecret = $_SESSION['mfa_setup_secret'] ??= $totp->generateSecret();
            $pendingUri = $totp->provisioningUri($pendingSecret, (string) $user['email'], (string) $config['app']['name']);
        }

        Response::html(View::render('security', [
            'app' => $config['app'],
            'title' => 'Account Security',
            'active' => 'security',
            'term' => DB::selectOne('SELECT * FROM terms WHERE tenant_id = ? ORDER BY start_date DESC LIMIT 1',
                [Tenancy::requireId()]) ?? ['id' => 0, 'name' => '—'],
            'terms' => $allTerms(),
            'user' => $user,
            'mfaEnabled' => (int) $row['mfa_enabled'] === 1,
            'recoveryCodesLeft' => count(json_decode((string) ($row['mfa_recovery_codes'] ?? '[]'), true) ?: []),
            'pendingSecret' => $pendingSecret,
            'pendingUri' => $pendingUri,
            'flash' => $_SESSION['flash'] ?? null,
            'newRecoveryCodes' => $_SESSION['new_recovery_codes'] ?? null,
        ]));
        unset($_SESSION['flash'], $_SESSION['new_recovery_codes']);
    });

    $r->post('/security/mfa/enable', function (Request $req) use ($requireLogin, $totp) {
        $user = $requireLogin();
        $secret = $_SESSION['mfa_setup_secret'] ?? null;
        if ($secret === null || !$totp->verify($secret, (string) $req->input('code'))) {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Code did not match — scan the QR again and enter the current code.'];
            Response::redirect('/security');
        }
        $codes = $totp->generateRecoveryCodes();
        DB::update('users', (int) $user['id'], [
            'mfa_secret' => $secret,
            'mfa_enabled' => 1,
            'mfa_recovery_codes' => json_encode($codes['hashes']),
            'mfa_last_counter' => null,
        ]);
        unset($_SESSION['mfa_setup_secret']);
        $_SESSION['flash'] = ['type' => 'success', 'text' => 'Two-factor authentication is now enabled.'];
        $_SESSION['new_recovery_codes'] = $codes['plain'];
        \App\Core\Audit::log('mfa_enabled', 'user', (int) $user['id']);
        Response::redirect('/security');
    });

    $r->post('/security/mfa/disable', function (Request $req) use ($requireLogin) {
        $user = $requireLogin();
        $row = DB::selectOne('SELECT password_hash FROM users WHERE id = ?', [(int) $user['id']]);
        // Re-authenticate before weakening account security.
        if (empty($row['password_hash'])
            || !password_verify((string) $req->input('password'), (string) $row['password_hash'])) {
            $_SESSION['flash'] = ['type' => 'danger', 'text' => 'Password confirmation failed — MFA unchanged.'];
            Response::redirect('/security');
        }
        DB::update('users', (int) $user['id'], [
            'mfa_secret' => null, 'mfa_enabled' => 0,
            'mfa_recovery_codes' => null, 'mfa_last_counter' => null,
        ]);
        $_SESSION['flash'] = ['type' => 'warning', 'text' => 'Two-factor authentication has been disabled.'];
        \App\Core\Audit::log('mfa_disabled', 'user', (int) $user['id']);
        Response::redirect('/security');
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
        $tenantId = Tenancy::requireId();

        return [
            'departments' => DB::select('SELECT * FROM departments WHERE is_active = 1 AND tenant_id = ? ORDER BY code', [$tenantId]),
            'rooms' => DB::select('SELECT id, code, capacity, type FROM rooms WHERE is_active = 1 AND tenant_id = ? ORDER BY code', [$tenantId]),
            'patterns' => DB::select('SELECT * FROM meeting_patterns WHERE tenant_id = ? ORDER BY start_time', [$tenantId]),
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
                 WHERE f.tenant_id = ?
                 ORDER BY d.code, f.last_name', [Tenancy::requireId()]),
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
                 WHERE r.tenant_id = ?
                 ORDER BY r.code', [Tenancy::requireId()]),
            'utilization' => $analytics->roomUtilization((int) $term['id']),
        ];
    }), 'rooms.view');

    // ---------------- Reports & exports ----------------
    $r->get('/reports', $page('reports', 'Reports', function (Request $req, array $term) {
        $tenantId = Tenancy::requireId();

        return [
            'departments' => DB::select('SELECT * FROM departments WHERE is_active = 1 AND tenant_id = ? ORDER BY code', [$tenantId]),
            'facultyList' => DB::select(
                'SELECT id, CONCAT(first_name, " ", last_name) name FROM faculty WHERE tenant_id = ? ORDER BY last_name', [$tenantId]),
            'scheduledReports' => DB::select('SELECT * FROM scheduled_reports WHERE tenant_id = ? ORDER BY id DESC', [$tenantId]),
        ];
    }), 'reports.view');

    // ---------------- Imports ----------------
    $r->get('/imports', $page('imports', 'Bulk Import', function () {
        return [
            'batches' => DB::select(
                'SELECT b.*, u.name user FROM import_batches b JOIN users u ON u.id = b.user_id
                 WHERE b.tenant_id = ?
                 ORDER BY b.id DESC LIMIT 20', [Tenancy::requireId()]),
        ];
    }), 'imports.run');

    // ---------------- Scenarios ----------------
    $r->get('/scenarios', $page('scenarios', 'Scenario Planning', function (Request $req, array $term) {
        return [
            'scenarios' => DB::select(
                'SELECT * FROM scenarios WHERE term_id = ? ORDER BY id DESC', [(int) $term['id']]),
            'facultyList' => DB::select(
                'SELECT id, CONCAT(first_name, " ", last_name) name FROM faculty
                 WHERE status = "active" AND tenant_id = ? ORDER BY last_name', [Tenancy::requireId()]),
            'roomList' => DB::select('SELECT id, code FROM rooms WHERE is_active = 1 AND tenant_id = ? ORDER BY code', [Tenancy::requireId()]),
        ];
    }), 'scenarios.run');

    // ---------------- Workflow ----------------
    $r->get('/workflow', $page('workflow', 'Approvals', function (Request $req, array $term) {
        $departments = DB::select(
            'SELECT * FROM departments WHERE is_active = 1 AND tenant_id = ? ORDER BY code',
            [Tenancy::requireId()]);
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

    // ---------------- Audit trail (admin) ----------------
    $r->get('/audit', $page('audit', 'Audit Trail', function (Request $req) {
        $params = [Tenancy::requireId()];
        $filters = '';
        if (!empty($req->query['action'])) {
            $filters .= ' AND a.action = ?';
            $params[] = (string) $req->query['action'];
        }
        if (!empty($req->query['entity_type'])) {
            $filters .= ' AND a.entity_type = ?';
            $params[] = (string) $req->query['entity_type'];
        }

        return [
            'logs' => DB::select(
                "SELECT a.*, u.name actor FROM audit_logs a
                 LEFT JOIN users u ON u.id = a.user_id
                 WHERE a.tenant_id = ?$filters
                 ORDER BY a.id DESC LIMIT 200",
                $params),
            'actions' => DB::select(
                'SELECT DISTINCT action FROM audit_logs WHERE tenant_id = ? ORDER BY action',
                [Tenancy::requireId()]),
            'entityTypes' => DB::select(
                'SELECT DISTINCT entity_type FROM audit_logs WHERE tenant_id = ? ORDER BY entity_type',
                [Tenancy::requireId()]),
        ];
    }), 'admin.audit');
};
