<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Database as DB;

/** Dashboard KPIs: scheduling, workload, enrollment, utilization. */
final class AnalyticsService
{
    public function schedulingKpis(int $termId): array
    {
        $sections = DB::selectOne(
            'SELECT COUNT(*) total,
                    SUM(status = "draft") drafts,
                    SUM(status IN ("approved","published")) approved,
                    SUM(faculty_id IS NULL) unassigned_faculty,
                    COUNT(DISTINCT course_id) courses
             FROM sections WHERE term_id = ? AND status <> "cancelled"',
            [$termId]
        ) ?? [];

        $unroomed = DB::selectOne(
            'SELECT COUNT(DISTINCT s.id) n FROM sections s
             JOIN section_meetings m ON m.section_id = s.id
             WHERE s.term_id = ? AND s.status <> "cancelled" AND m.room_id IS NULL
               AND s.delivery_mode = "on_campus"',
            [$termId]
        );

        $conflicts = DB::selectOne(
            'SELECT COUNT(*) total, SUM(severity = "error") errors
             FROM schedule_conflicts WHERE term_id = ? AND status = "open"',
            [$termId]
        ) ?? [];

        $unscheduledCourses = DB::selectOne(
            'SELECT COUNT(*) n FROM courses c WHERE c.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM sections s WHERE s.course_id = c.id AND s.term_id = ?
                               AND s.status <> "cancelled")',
            [$termId]
        );

        $total = (int) ($sections['total'] ?? 0);

        return [
            'total_sections' => $total,
            'total_courses_scheduled' => (int) ($sections['courses'] ?? 0),
            'unscheduled_courses' => (int) ($unscheduledCourses['n'] ?? 0),
            'draft_sections' => (int) ($sections['drafts'] ?? 0),
            'approved_sections' => (int) ($sections['approved'] ?? 0),
            'unassigned_faculty_sections' => (int) ($sections['unassigned_faculty'] ?? 0),
            'unassigned_room_sections' => (int) ($unroomed['n'] ?? 0),
            'open_conflicts' => (int) ($conflicts['total'] ?? 0),
            'error_conflicts' => (int) ($conflicts['errors'] ?? 0),
            'completion_pct' => $total > 0
                ? round((1 - ((int) ($sections['unassigned_faculty'] ?? 0)) / $total) * 100, 1)
                : 0.0,
        ];
    }

    public function roomUtilization(int $termId): array
    {
        // Standard scheduling window: weekdays 08:00–18:00 = 50 bookable hours/week.
        $bookableHoursPerWeek = 50.0;
        $rows = DB::select(
            'SELECT r.id, r.code, r.name, r.type, r.capacity,
                    COALESCE(SUM(TIME_TO_SEC(TIMEDIFF(m.end_time, m.start_time)) / 3600), 0) used_hours,
                    COALESCE(AVG(s.enrolled / NULLIF(s.capacity, 0)) * 100, 0) avg_fill
             FROM rooms r
             LEFT JOIN section_meetings m ON m.room_id = r.id
             LEFT JOIN sections s ON s.id = m.section_id AND s.term_id = ? AND s.status <> "cancelled"
             WHERE r.is_active = 1
             GROUP BY r.id ORDER BY used_hours DESC',
            [$termId]
        );
        foreach ($rows as &$r) {
            $r['used_hours'] = round((float) $r['used_hours'], 1);
            $r['utilization_pct'] = $r['type'] === 'online'
                ? null
                : round(min(100, $r['used_hours'] / $bookableHoursPerWeek * 100), 1);
            $r['avg_fill_pct'] = round((float) $r['avg_fill'], 1);
            unset($r['avg_fill']);
        }

        return $rows;
    }

    public function enrollmentKpis(int $termId): array
    {
        $rows = DB::select(
            'SELECT c.code, c.title, SUM(s.enrolled) enrolled, SUM(s.capacity) capacity,
                    SUM(s.waitlisted) waitlisted, COUNT(s.id) sections,
                    ef.predicted_enrollment
             FROM sections s
             JOIN courses c ON c.id = s.course_id
             LEFT JOIN enrollment_forecasts ef ON ef.course_id = c.id AND ef.term_id = s.term_id
             WHERE s.term_id = ? AND s.status <> "cancelled"
             GROUP BY c.id ORDER BY enrolled DESC',
            [$termId]
        );
        foreach ($rows as &$r) {
            $cap = (int) $r['capacity'];
            $r['fill_rate_pct'] = $cap > 0 ? round((int) $r['enrolled'] / $cap * 100, 1) : 0.0;
        }

        return $rows;
    }

    /** Weekly heatmap matrix: scheduled meeting count per day/hour. */
    public function scheduleHeatmap(int $termId): array
    {
        $rows = DB::select(
            'SELECT m.day, HOUR(m.start_time) h, COUNT(*) n
             FROM section_meetings m JOIN sections s ON s.id = m.section_id
             WHERE s.term_id = ? AND s.status <> "cancelled"
             GROUP BY m.day, HOUR(m.start_time)',
            [$termId]
        );
        $matrix = [];
        foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $day) {
            for ($h = 8; $h <= 19; $h++) {
                $matrix[$day][$h] = 0;
            }
        }
        foreach ($rows as $r) {
            if (isset($matrix[$r['day']][(int) $r['h']])) {
                $matrix[$r['day']][(int) $r['h']] = (int) $r['n'];
            }
        }

        return $matrix;
    }

    public function enrollmentTrends(?int $courseId = null): array
    {
        $params = [];
        $filter = '';
        if ($courseId !== null) {
            $filter = ' AND eh.course_id = ?';
            $params[] = $courseId;
        }

        return DB::select(
            'SELECT t.code term, c.code course, eh.enrolled, eh.waitlisted, eh.sections_offered
             FROM enrollment_history eh
             JOIN terms t ON t.id = eh.term_id
             JOIN courses c ON c.id = eh.course_id
             WHERE 1=1' . $filter . '
             ORDER BY t.start_date, c.code',
            $params
        );
    }
}
