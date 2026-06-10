<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;
use App\Core\Tenancy;

/**
 * Faculty workload computation, configurable policy validation
 * (rules engine), and equity analytics.
 */
final class WorkloadService
{
    /**
     * Full workload picture for every active faculty member in a term.
     *
     * @return array<int, array<string, mixed>>
     */
    public function facultyWorkloads(int $termId, ?int $departmentId = null): array
    {
        $params = [$termId, $termId, $termId, $termId, Tenancy::requireId()];
        $deptFilter = ' AND f.tenant_id = ?';
        if ($departmentId !== null) {
            $deptFilter .= ' AND f.department_id = ?';
            $params[] = $departmentId;
        }

        $rows = DB::select(
            'SELECT f.id, CONCAT(f.first_name, " ", f.last_name) name, f.email, f.rank,
                    f.contract_type, f.status, d.code department, d.id department_id,
                    f.max_credit_hours, f.min_credit_hours, f.max_contact_hours,
                    f.research_release_hours, f.admin_release_hours,
                    COALESCE((SELECT SUM(c.credit_hours) FROM sections s JOIN courses c ON c.id = s.course_id
                              WHERE s.faculty_id = f.id AND s.term_id = ? AND s.status <> "cancelled"), 0) teaching_credits,
                    COALESCE((SELECT SUM(c.contact_hours) FROM sections s JOIN courses c ON c.id = s.course_id
                              WHERE s.faculty_id = f.id AND s.term_id = ? AND s.status <> "cancelled"), 0) contact_hours,
                    COALESCE((SELECT COUNT(DISTINCT s.course_id) FROM sections s
                              WHERE s.faculty_id = f.id AND s.term_id = ? AND s.status <> "cancelled"), 0) preps,
                    COALESCE((SELECT SUM(wa.credit_hour_equivalent) FROM workload_activities wa
                              WHERE wa.faculty_id = f.id AND wa.term_id = ?), 0) activity_credits
             FROM faculty f
             JOIN departments d ON d.id = f.department_id
             WHERE f.status IN ("active","sabbatical","leave")' . $deptFilter . '
             ORDER BY d.code, name',
            $params
        );

        foreach ($rows as &$r) {
            $r['teaching_credits'] = (float) $r['teaching_credits'];
            $r['activity_credits'] = (float) $r['activity_credits'];
            $r['total_credits'] = $r['teaching_credits'] + $r['activity_credits'];
            // Standing releases (faculty table) reduce capacity; term duties
            // (workload_activities) count toward the load instead.
            $effectiveMax = (float) $r['max_credit_hours']
                - (float) $r['research_release_hours'] - (float) $r['admin_release_hours'];
            $r['effective_max_credits'] = $effectiveMax;
            $r['utilization_pct'] = $effectiveMax > 0
                ? round($r['total_credits'] / $effectiveMax * 100, 1)
                : 0.0;
            $r['load_status'] = match (true) {
                $r['status'] !== 'active' => 'on_leave',
                $r['total_credits'] > $effectiveMax => 'overloaded',
                $r['total_credits'] < (float) $r['min_credit_hours'] => 'underloaded',
                default => 'balanced',
            };
            $r['violations'] = $this->validateFaculty($r, $termId);
        }

        return $rows;
    }

    /**
     * Rules engine: evaluate all active workload policies against a
     * computed faculty workload row. Returns violation descriptions.
     */
    public function validateFaculty(array $w, int $termId): array
    {
        $policies = DB::select(
            'SELECT * FROM workload_policies
             WHERE is_active = 1 AND tenant_id = ?
               AND (department_id IS NULL OR department_id = ?)
               AND (contract_type = "any" OR contract_type = ?)',
            [Tenancy::requireId(), (int) $w['department_id'], $w['contract_type']]
        );

        $violations = [];
        foreach ($policies as $p) {
            $value = (float) $p['rule_value'];
            $violated = match ($p['rule_type']) {
                'max_credit_hours' => $w['total_credits'] > $value,
                'min_credit_hours' => $w['status'] === 'active' && $w['total_credits'] < $value,
                'max_contact_hours' => (float) $w['contact_hours'] > $value,
                'max_preps' => (int) $w['preps'] > $value,
                'adjunct_max_credit_hours' => $w['contract_type'] === 'adjunct' && $w['teaching_credits'] > $value,
                'max_overload_hours' => $w['total_credits'] - (float) $w['max_credit_hours'] > $value,
                'max_consecutive_hours' => $this->maxConsecutiveHours((int) $w['id'], $termId) > $value,
                'max_sections' => $this->sectionCount((int) $w['id'], $termId) > $value,
                default => false, // accreditation/union: informational placeholders
            };
            if ($violated) {
                $violations[] = [
                    'policy' => $p['name'],
                    'rule_type' => $p['rule_type'],
                    'limit' => $value,
                    'severity' => $p['severity'],
                ];
            }
        }

        return $violations;
    }

    private function sectionCount(int $facultyId, int $termId): int
    {
        $row = DB::selectOne(
            'SELECT COUNT(*) n FROM sections WHERE faculty_id = ? AND term_id = ? AND status <> "cancelled"',
            [$facultyId, $termId]
        );

        return (int) ($row['n'] ?? 0);
    }

    private function maxConsecutiveHours(int $facultyId, int $termId): float
    {
        $meetings = DB::select(
            'SELECT m.day, m.start_time, m.end_time FROM section_meetings m
             JOIN sections s ON s.id = m.section_id
             WHERE s.faculty_id = ? AND s.term_id = ? AND s.status <> "cancelled"
             ORDER BY m.day, m.start_time',
            [$facultyId, $termId]
        );
        $max = 0.0;
        $byDay = [];
        foreach ($meetings as $m) {
            $byDay[$m['day']][] = $m;
        }
        foreach ($byDay as $dayMeetings) {
            $run = 0.0;
            $prevEnd = null;
            foreach ($dayMeetings as $m) {
                $duration = (strtotime($m['end_time']) - strtotime($m['start_time'])) / 3600;
                if ($prevEnd !== null && (strtotime($m['start_time']) - strtotime($prevEnd)) <= 15 * 60) {
                    $run += $duration;
                } else {
                    $run = $duration;
                }
                $prevEnd = $m['end_time'];
                $max = max($max, $run);
            }
        }

        return round($max, 2);
    }

    /** Department-level workload analytics: averages, distribution, equity. */
    public function departmentAnalytics(int $termId): array
    {
        $workloads = $this->facultyWorkloads($termId);
        $byDept = [];
        foreach ($workloads as $w) {
            $byDept[$w['department']][] = $w;
        }

        $analytics = [];
        foreach ($byDept as $dept => $members) {
            $credits = array_map(fn ($m) => $m['total_credits'], $members);
            $n = count($credits);
            $mean = $n > 0 ? array_sum($credits) / $n : 0.0;
            $variance = $n > 0
                ? array_sum(array_map(fn ($c) => ($c - $mean) ** 2, $credits)) / $n
                : 0.0;
            $analytics[] = [
                'department' => $dept,
                'faculty_count' => $n,
                'avg_credits' => round($mean, 2),
                'std_dev' => round(sqrt($variance), 2),
                'equity_index' => $mean > 0 ? round(1 - min(1, sqrt($variance) / $mean), 2) : 1.0,
                'overloaded' => count(array_filter($members, fn ($m) => $m['load_status'] === 'overloaded')),
                'underloaded' => count(array_filter($members, fn ($m) => $m['load_status'] === 'underloaded')),
                'avg_utilization_pct' => $n > 0
                    ? round(array_sum(array_map(fn ($m) => $m['utilization_pct'], $members)) / $n, 1)
                    : 0.0,
            ];
        }

        return $analytics;
    }

    /** Concrete rebalancing suggestions: move sections from overloaded to underloaded qualified faculty. */
    public function rebalancingSuggestions(int $termId): array
    {
        $workloads = $this->facultyWorkloads($termId);
        $overloaded = array_filter($workloads, fn ($w) => $w['load_status'] === 'overloaded');
        $underloaded = array_filter($workloads, fn ($w) => $w['load_status'] === 'underloaded');

        $suggestions = [];
        foreach ($overloaded as $over) {
            $sections = DB::select(
                'SELECT s.id, c.id course_id, c.code, c.credit_hours FROM sections s
                 JOIN courses c ON c.id = s.course_id
                 WHERE s.faculty_id = ? AND s.term_id = ? AND s.status <> "cancelled"
                 ORDER BY c.credit_hours DESC',
                [(int) $over['id'], $termId]
            );
            foreach ($sections as $section) {
                foreach ($underloaded as $under) {
                    if ($under['department_id'] !== $over['department_id']) {
                        continue;
                    }
                    $qualified = DB::selectOne(
                        'SELECT level FROM faculty_course_qualifications WHERE faculty_id = ? AND course_id = ?',
                        [(int) $under['id'], (int) $section['course_id']]
                    );
                    if ($qualified === null) {
                        continue;
                    }
                    $suggestions[] = [
                        'section_id' => (int) $section['id'],
                        'course' => $section['code'],
                        'from_faculty_id' => (int) $over['id'],
                        'from' => $over['name'],
                        'to_faculty_id' => (int) $under['id'],
                        'to' => $under['name'],
                        'credit_hours' => (float) $section['credit_hours'],
                        'rationale' => sprintf(
                            '%s is overloaded (%.1f cr); %s is underloaded (%.1f cr) and %s in %s',
                            $over['name'], $over['total_credits'],
                            $under['name'], $under['total_credits'],
                            $qualified['level'], $section['code']
                        ),
                    ];
                    continue 2; // one suggestion per overloaded section
                }
            }
        }

        return $suggestions;
    }
}
