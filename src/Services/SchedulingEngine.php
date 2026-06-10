<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Database as DB;
use App\Core\Tenancy;

/**
 * Deterministic scheduling core: conflict detection and automatic
 * schedule generation. The AI layer (AIService) builds on top of this —
 * hard constraints are always enforced here in code, never delegated
 * to the LLM, so a generated schedule can never violate them.
 */
final class SchedulingEngine
{
    private const DAYS = ['Mon', 'Tue', 'Wed', 'Thu', 'Fri'];

    // =================================================================
    // Conflict detection
    // =================================================================

    /**
     * Run all conflict checks for a term, persist results, and return them.
     * Previously detected open conflicts for the term are recomputed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function detectConflicts(int $termId): array
    {
        $meetings = DB::select(
            'SELECT m.id meeting_id, m.section_id, m.room_id, m.day, m.start_time, m.end_time, m.kind,
                    s.faculty_id, s.capacity, s.enrolled, s.delivery_mode, s.status,
                    c.id course_id, c.code course_code, c.title, c.requires_lab, c.required_equipment,
                    c.department_id,
                    r.code room_code, r.capacity room_capacity, r.type room_type, r.equipment room_equipment,
                    CONCAT(f.first_name, " ", f.last_name) faculty_name
             FROM section_meetings m
             JOIN sections s ON s.id = m.section_id
             JOIN courses c ON c.id = s.course_id
             LEFT JOIN rooms r ON r.id = m.room_id
             LEFT JOIN faculty f ON f.id = s.faculty_id
             WHERE s.term_id = ? AND s.status <> "cancelled"',
            [$termId]
        );

        $conflicts = [];
        $conflicts = array_merge(
            $conflicts,
            $this->checkTimeOverlaps($meetings),
            $this->checkRoomSuitability($meetings),
            $this->checkFacultyAvailability($meetings),
            $this->checkDuplicateAssignments($termId),
            $this->checkPathwayConflicts($termId),
            $this->checkRequisiteSequencing($termId)
        );

        DB::transaction(function () use ($termId, $conflicts) {
            DB::execute('DELETE FROM schedule_conflicts WHERE term_id = ? AND status = "open"', [$termId]);
            foreach ($conflicts as $c) {
                DB::insert('schedule_conflicts', [
                    'term_id' => $termId,
                    'type' => $c['type'],
                    'severity' => $c['severity'],
                    'section_id' => $c['section_id'] ?? null,
                    'conflicting_section_id' => $c['conflicting_section_id'] ?? null,
                    'description' => mb_substr($c['description'], 0, 500),
                    'suggestion' => isset($c['suggestion']) ? mb_substr($c['suggestion'], 0, 500) : null,
                ]);
            }
        });

        Audit::log('detect_conflicts', 'term', $termId, null, ['count' => count($conflicts)]);

        return $conflicts;
    }

    /** Faculty double-booking and room double-booking. */
    private function checkTimeOverlaps(array $meetings): array
    {
        $conflicts = [];
        $n = count($meetings);
        for ($i = 0; $i < $n; $i++) {
            for ($j = $i + 1; $j < $n; $j++) {
                $a = $meetings[$i];
                $b = $meetings[$j];
                if ($a['day'] !== $b['day'] || !$this->timesOverlap($a, $b)) {
                    continue;
                }
                if ($a['faculty_id'] !== null && $a['faculty_id'] === $b['faculty_id']
                    && $a['section_id'] !== $b['section_id']) {
                    $conflicts[] = [
                        'type' => 'faculty_time',
                        'severity' => 'error',
                        'section_id' => (int) $a['section_id'],
                        'conflicting_section_id' => (int) $b['section_id'],
                        'description' => sprintf(
                            '%s is double-booked on %s %s–%s (%s vs %s)',
                            $a['faculty_name'], $a['day'],
                            substr($a['start_time'], 0, 5), substr($a['end_time'], 0, 5),
                            $a['course_code'], $b['course_code']
                        ),
                        'suggestion' => sprintf(
                            'Move %s to a different meeting pattern or assign a different qualified instructor.',
                            $b['course_code']
                        ),
                    ];
                }
                if ($a['room_id'] !== null && $a['room_id'] === $b['room_id']
                    && $a['section_id'] !== $b['section_id']
                    && !in_array($a['room_type'], ['online'], true)) {
                    $conflicts[] = [
                        'type' => 'room_time',
                        'severity' => 'error',
                        'section_id' => (int) $a['section_id'],
                        'conflicting_section_id' => (int) $b['section_id'],
                        'description' => sprintf(
                            'Room %s is double-booked on %s %s–%s (%s vs %s)',
                            $a['room_code'], $a['day'],
                            substr($a['start_time'], 0, 5), substr($a['end_time'], 0, 5),
                            $a['course_code'], $b['course_code']
                        ),
                        'suggestion' => sprintf('Reassign %s to another room with capacity ≥ %d.',
                            $b['course_code'], (int) $b['capacity']),
                    ];
                }
            }
        }

        return $conflicts;
    }

    /** Capacity, equipment, and lab requirements of the assigned room. */
    private function checkRoomSuitability(array $meetings): array
    {
        $conflicts = [];
        $seen = [];
        foreach ($meetings as $m) {
            if ($m['room_id'] === null || isset($seen[$m['section_id'] . ':' . $m['room_id']])) {
                continue;
            }
            $seen[$m['section_id'] . ':' . $m['room_id']] = true;

            if ((int) $m['room_capacity'] < (int) $m['capacity'] && $m['room_type'] !== 'online') {
                $conflicts[] = [
                    'type' => 'room_capacity',
                    'severity' => 'error',
                    'section_id' => (int) $m['section_id'],
                    'description' => sprintf(
                        '%s: room %s seats %d but section capacity is %d',
                        $m['course_code'], $m['room_code'], (int) $m['room_capacity'], (int) $m['capacity']
                    ),
                    'suggestion' => 'Move to a larger room or split the section.',
                ];
            }

            $required = json_decode((string) ($m['required_equipment'] ?? '[]'), true) ?: [];
            $available = json_decode((string) ($m['room_equipment'] ?? '[]'), true) ?: [];
            $missing = array_diff($required, $available);
            if ($missing !== [] && $m['room_type'] !== 'online') {
                $conflicts[] = [
                    'type' => 'room_equipment',
                    'severity' => 'warning',
                    'section_id' => (int) $m['section_id'],
                    'description' => sprintf(
                        '%s in %s is missing required equipment: %s',
                        $m['course_code'], $m['room_code'], implode(', ', $missing)
                    ),
                    'suggestion' => 'Reassign to a room providing: ' . implode(', ', $missing) . '.',
                ];
            }

            if ((int) $m['requires_lab'] === 1 && $m['kind'] === 'lab'
                && !in_array($m['room_type'], ['laboratory', 'online', 'hybrid'], true)) {
                $conflicts[] = [
                    'type' => 'room_equipment',
                    'severity' => 'warning',
                    'section_id' => (int) $m['section_id'],
                    'description' => sprintf('%s lab meeting is scheduled in a non-lab room (%s)',
                        $m['course_code'], $m['room_code']),
                    'suggestion' => 'Assign the lab meeting to a laboratory room.',
                ];
            }
        }

        return $conflicts;
    }

    /** Meetings outside the instructor's declared availability windows. */
    private function checkFacultyAvailability(array $meetings): array
    {
        $conflicts = [];
        $availabilityCache = [];
        foreach ($meetings as $m) {
            if ($m['faculty_id'] === null) {
                continue;
            }
            $fid = (int) $m['faculty_id'];
            $availabilityCache[$fid] ??= DB::select(
                'SELECT day, start_time, end_time, preference FROM faculty_availability WHERE faculty_id = ?',
                [$fid]
            );
            $windows = array_filter(
                $availabilityCache[$fid],
                fn ($w) => $w['day'] === $m['day'] && $w['preference'] !== 'unavailable'
            );
            if ($availabilityCache[$fid] === []) {
                continue; // no availability declared = assume open
            }
            $inside = false;
            foreach ($windows as $w) {
                if ($w['start_time'] <= $m['start_time'] && $w['end_time'] >= $m['end_time']) {
                    $inside = true;
                    break;
                }
            }
            if (!$inside) {
                $conflicts[] = [
                    'type' => 'faculty_unavailable',
                    'severity' => 'error',
                    'section_id' => (int) $m['section_id'],
                    'description' => sprintf(
                        '%s (%s) is scheduled %s %s–%s, outside %s\'s availability',
                        $m['course_code'], $m['faculty_name'] ?? '?', $m['day'],
                        substr($m['start_time'], 0, 5), substr($m['end_time'], 0, 5),
                        $m['faculty_name'] ?? 'the instructor'
                    ),
                    'suggestion' => 'Move the meeting into an available window or reassign the section.',
                ];
            }
        }

        return $conflicts;
    }

    /** Same instructor assigned to two sections of the same course unnecessarily duplicated meetings. */
    private function checkDuplicateAssignments(int $termId): array
    {
        $rows = DB::select(
            'SELECT s.course_id, c.code, s.faculty_id, GROUP_CONCAT(s.id) section_ids, COUNT(*) n
             FROM sections s JOIN courses c ON c.id = s.course_id
             WHERE s.term_id = ? AND s.status <> "cancelled" AND s.faculty_id IS NOT NULL
             GROUP BY s.course_id, s.faculty_id, s.section_no
             HAVING COUNT(*) > 1',
            [$termId]
        );
        $conflicts = [];
        foreach ($rows as $r) {
            $ids = array_map('intval', explode(',', (string) $r['section_ids']));
            $conflicts[] = [
                'type' => 'duplicate_assignment',
                'severity' => 'error',
                'section_id' => $ids[0],
                'conflicting_section_id' => $ids[1] ?? null,
                'description' => sprintf('Duplicate section/assignment detected for %s (%d copies)',
                    $r['code'], (int) $r['n']),
                'suggestion' => 'Remove or renumber the duplicate section.',
            ];
        }

        return $conflicts;
    }

    /**
     * Student pathway conflicts: required courses in the same program
     * plan-semester must each have at least one non-overlapping section pair.
     */
    private function checkPathwayConflicts(int $termId): array
    {
        $groups = DB::select(
            'SELECT pc.program_id, p.code program_code, pc.plan_semester,
                    GROUP_CONCAT(DISTINCT pc.course_id) course_ids
             FROM program_courses pc
             JOIN programs p ON p.id = pc.program_id
             WHERE pc.is_required = 1
             GROUP BY pc.program_id, pc.plan_semester
             HAVING COUNT(DISTINCT pc.course_id) > 1'
        );

        $conflicts = [];
        foreach ($groups as $g) {
            $courseIds = array_map('intval', explode(',', (string) $g['course_ids']));
            for ($i = 0; $i < count($courseIds); $i++) {
                for ($j = $i + 1; $j < count($courseIds); $j++) {
                    $result = $this->coursePairFullyOverlaps($termId, $courseIds[$i], $courseIds[$j]);
                    if ($result !== null) {
                        $conflicts[] = [
                            'type' => 'pathway',
                            'severity' => 'error',
                            'section_id' => $result['section_a'],
                            'conflicting_section_id' => $result['section_b'],
                            'description' => sprintf(
                                'Program %s semester %d: every section of %s overlaps every section of %s — students cannot take both',
                                $g['program_code'], (int) $g['plan_semester'],
                                $result['code_a'], $result['code_b']
                            ),
                            'suggestion' => sprintf('Move one section of %s or %s to a non-overlapping pattern.',
                                $result['code_a'], $result['code_b']),
                        ];
                    }
                }
            }
        }

        return $conflicts;
    }

    /** Returns overlap info when ALL section pairs of two courses collide, else null. */
    private function coursePairFullyOverlaps(int $termId, int $courseA, int $courseB): ?array
    {
        $fetch = fn (int $cid) => DB::select(
            'SELECT s.id section_id, c.code, m.day, m.start_time, m.end_time
             FROM sections s
             JOIN courses c ON c.id = s.course_id
             JOIN section_meetings m ON m.section_id = s.id
             WHERE s.term_id = ? AND s.course_id = ? AND s.status <> "cancelled"',
            [$termId, $cid]
        );
        $aMeetings = $fetch($courseA);
        $bMeetings = $fetch($courseB);
        if ($aMeetings === [] || $bMeetings === []) {
            return null; // not both offered → no pathway collision
        }

        $bySection = function (array $meetings): array {
            $out = [];
            foreach ($meetings as $m) {
                $out[(int) $m['section_id']][] = $m;
            }
            return $out;
        };
        $aSections = $bySection($aMeetings);
        $bSections = $bySection($bMeetings);

        foreach ($aSections as $aMs) {
            foreach ($bSections as $bMs) {
                $pairOverlaps = false;
                foreach ($aMs as $am) {
                    foreach ($bMs as $bm) {
                        if ($am['day'] === $bm['day'] && $this->timesOverlap($am, $bm)) {
                            $pairOverlaps = true;
                            break 2;
                        }
                    }
                }
                if (!$pairOverlaps) {
                    return null; // found a compatible pair → students have a path
                }
            }
        }

        $firstA = $aMeetings[0];
        $firstB = $bMeetings[0];

        return [
            'section_a' => (int) $firstA['section_id'],
            'section_b' => (int) $firstB['section_id'],
            'code_a' => $firstA['code'],
            'code_b' => $firstB['code'],
        ];
    }

    /** A course offered in a term whose hard prerequisite is not offered in any prior recorded term or this term. */
    private function checkRequisiteSequencing(int $termId): array
    {
        $rows = DB::select(
            'SELECT DISTINCT c.code course_code, rq.code req_code, s.id section_id
             FROM sections s
             JOIN courses c ON c.id = s.course_id
             JOIN course_requisites cr ON cr.course_id = c.id AND cr.type = "corequisite"
             JOIN courses rq ON rq.id = cr.requisite_course_id
             WHERE s.term_id = ? AND s.status <> "cancelled"
               AND NOT EXISTS (
                   SELECT 1 FROM sections s2
                   WHERE s2.term_id = s.term_id AND s2.course_id = cr.requisite_course_id
                     AND s2.status <> "cancelled")',
            [$termId]
        );
        $conflicts = [];
        foreach ($rows as $r) {
            $conflicts[] = [
                'type' => 'requisite',
                'severity' => 'warning',
                'section_id' => (int) $r['section_id'],
                'description' => sprintf('%s is offered but its co-requisite %s has no section this term',
                    $r['course_code'], $r['req_code']),
                'suggestion' => sprintf('Add a section of %s or review the co-requisite rule.', $r['req_code']),
            ];
        }

        return $conflicts;
    }

    private function timesOverlap(array $a, array $b): bool
    {
        return $a['start_time'] < $b['end_time'] && $b['start_time'] < $a['end_time'];
    }

    // =================================================================
    // Automatic schedule generation
    // =================================================================

    /**
     * Generate a draft schedule for a term (optionally one department).
     *
     * Greedy constructive heuristic with scoring:
     *  1. Demand: forecast/history decides how many sections per course.
     *  2. Instructor: best qualified+available faculty under workload caps.
     *  3. Pattern+room: first pattern/room combination free for instructor
     *     and room, scored by preference fit and room-size fit.
     *
     * Hard constraints (never violated): faculty time clash, room time
     * clash, room capacity, faculty availability, workload credit caps.
     *
     * @param array $options ['replace' => bool, 'dry_run' => bool, 'enrollment_delta_pct' => float,
     *                        'exclude_faculty' => int[], 'exclude_rooms' => int[]]
     */
    public function generateSchedule(int $termId, ?int $departmentId = null, array $options = []): array
    {
        $replace = (bool) ($options['replace'] ?? false);
        $dryRun = (bool) ($options['dry_run'] ?? false);
        $deltaPct = (float) ($options['enrollment_delta_pct'] ?? 0.0);
        $excludeFaculty = array_map('intval', $options['exclude_faculty'] ?? []);
        $excludeRooms = array_map('intval', $options['exclude_rooms'] ?? []);

        $courses = $this->loadCoursesWithDemand($termId, $departmentId, $deltaPct);
        $faculty = $this->loadFacultyState($termId, $departmentId, $excludeFaculty, $replace);
        $rooms = $this->loadRooms($excludeRooms, $termId);
        $patterns = DB::select(
            'SELECT * FROM meeting_patterns WHERE tenant_id = ? ORDER BY start_time',
            [Tenancy::requireId()]
        );

        // Occupancy maps: existing meetings unless we're replacing drafts.
        [$facultyBusy, $roomBusy] = $this->loadOccupancy($termId, $replace);

        $created = [];
        $unassigned = [];

        // Largest demand first so big courses get big rooms and scarce slots.
        usort($courses, fn ($a, $b) => $b['demand'] <=> $a['demand']);

        foreach ($courses as $course) {
            $sectionsNeeded = max(1, (int) ceil($course['demand'] / max(1, (int) $course['default_capacity'])));
            for ($s = 1; $s <= $sectionsNeeded; $s++) {
                $placement = $this->placeSection($course, $faculty, $rooms, $patterns, $facultyBusy, $roomBusy);
                if ($placement === null) {
                    $unassigned[] = [
                        'course' => $course['code'],
                        'section_no' => sprintf('%02d', $s + (int) $course['existing_sections']),
                        'reason' => 'No instructor/room/pattern combination satisfies all hard constraints',
                    ];
                    continue;
                }
                $created[] = [
                    'course_id' => (int) $course['id'],
                    'course' => $course['code'],
                    'title' => $course['title'],
                    'section_no' => sprintf('%02d', $s + (int) $course['existing_sections']),
                    'faculty_id' => $placement['faculty_id'],
                    'faculty' => $placement['faculty_name'],
                    'room_id' => $placement['room_id'],
                    'room' => $placement['room_code'],
                    'pattern' => $placement['pattern_code'],
                    'days' => $placement['days'],
                    'start_time' => $placement['start_time'],
                    'end_time' => $placement['end_time'],
                    'capacity' => min((int) $course['default_capacity'], $placement['room_capacity']),
                    'score' => $placement['score'],
                ];
            }
        }

        if (!$dryRun) {
            $this->persistGeneratedSchedule($termId, $created, $replace, $departmentId);
        }

        $summary = [
            'term_id' => $termId,
            'department_id' => $departmentId,
            'sections_created' => count($created),
            'unassigned' => $unassigned,
            'sections' => $created,
            'dry_run' => $dryRun,
        ];
        Audit::log('generate_schedule', 'term', $termId, null, [
            'created' => count($created), 'unassigned' => count($unassigned), 'dry_run' => $dryRun,
        ]);

        return $summary;
    }

    private function loadCoursesWithDemand(int $termId, ?int $departmentId, float $deltaPct): array
    {
        $params = [$termId, $termId, $termId, Tenancy::requireId()];
        $deptFilter = ' AND c.tenant_id = ?';
        if ($departmentId !== null) {
            $deptFilter .= ' AND c.department_id = ?';
            $params[] = $departmentId;
        }
        $courses = DB::select(
            'SELECT c.*,
                    COALESCE(ef.predicted_enrollment,
                             (SELECT eh.enrolled FROM enrollment_history eh
                              WHERE eh.course_id = c.id ORDER BY eh.term_id DESC LIMIT 1),
                             c.default_capacity) demand,
                    (SELECT COUNT(*) FROM sections s WHERE s.course_id = c.id AND s.term_id = ?
                       AND s.status <> "cancelled") existing_sections
             FROM courses c
             LEFT JOIN enrollment_forecasts ef ON ef.course_id = c.id AND ef.term_id = ?
             WHERE c.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM sections s2 WHERE s2.course_id = c.id AND s2.term_id = ?
                               AND s2.status IN ("approved","published"))'
            . $deptFilter,
            $params
        );
        foreach ($courses as &$c) {
            $c['demand'] = (int) round((float) $c['demand'] * (1 + $deltaPct / 100));
        }

        return $courses;
    }

    private function loadFacultyState(int $termId, ?int $departmentId, array $exclude, bool $replace): array
    {
        $params = [$termId, $termId, $replace ? 'draft' : '__none__', $termId, Tenancy::requireId()];
        $deptFilter = ' AND f.tenant_id = ?';
        if ($departmentId !== null) {
            $deptFilter .= ' AND f.department_id = ?';
            $params[] = $departmentId;
        }
        $rows = DB::select(
            'SELECT f.*, CONCAT(f.first_name, " ", f.last_name) full_name,
                    COALESCE((SELECT SUM(wa.credit_hour_equivalent) FROM workload_activities wa
                              WHERE wa.faculty_id = f.id AND wa.term_id = ?), 0) activity_hours,
                    COALESCE((SELECT SUM(c2.credit_hours) FROM sections s2
                              JOIN courses c2 ON c2.id = s2.course_id
                              WHERE s2.faculty_id = f.id AND s2.term_id = ?
                                AND s2.status <> "cancelled" AND s2.status <> ?), 0) assigned_credits,
                    COALESCE((SELECT SUM(c3.contact_hours) FROM sections s3
                              JOIN courses c3 ON c3.id = s3.course_id
                              WHERE s3.faculty_id = f.id AND s3.term_id = ?
                                AND s3.status <> "cancelled"), 0) assigned_contact
             FROM faculty f
             WHERE f.status = "active"' . $deptFilter,
            $params
        );

        $out = [];
        foreach ($rows as $f) {
            if (in_array((int) $f['id'], $exclude, true)) {
                continue;
            }
            $f['id'] = (int) $f['id'];
            $f['assigned_credits'] = (float) $f['assigned_credits'];
            $f['preps'] = [];
            $f['availability'] = DB::select(
                'SELECT day, start_time, end_time, preference FROM faculty_availability WHERE faculty_id = ?',
                [$f['id']]
            );
            $f['qualifications'] = array_column(DB::select(
                'SELECT course_id, level, times_taught FROM faculty_course_qualifications WHERE faculty_id = ?',
                [$f['id']]
            ), null, 'course_id');
            $f['prefs'] = json_decode((string) ($f['preferences'] ?? '{}'), true) ?: [];
            $out[$f['id']] = $f;
        }

        return $out;
    }

    private function loadRooms(array $exclude, int $termId): array
    {
        $rooms = DB::select(
            'SELECT r.* FROM rooms r
             WHERE r.is_active = 1 AND r.type <> "online" AND r.tenant_id = ?
               AND NOT EXISTS (SELECT 1 FROM room_closures rc WHERE rc.room_id = r.id
                               AND (rc.term_id = ? OR rc.term_id IS NULL))
             ORDER BY r.capacity ASC',
            [Tenancy::requireId(), $termId]
        );

        return array_values(array_filter($rooms, fn ($r) => !in_array((int) $r['id'], $exclude, true)));
    }

    /** @return array{0: array, 1: array} [facultyBusy, roomBusy] keyed by id => list of {day,start,end} */
    private function loadOccupancy(int $termId, bool $replace): array
    {
        $statusFilter = $replace ? 'AND s.status <> "draft"' : '';
        $rows = DB::select(
            "SELECT s.faculty_id, m.room_id, m.day, m.start_time, m.end_time
             FROM section_meetings m JOIN sections s ON s.id = m.section_id
             WHERE s.term_id = ? AND s.status <> 'cancelled' $statusFilter",
            [$termId]
        );
        $facultyBusy = [];
        $roomBusy = [];
        foreach ($rows as $r) {
            $slot = ['day' => $r['day'], 'start_time' => $r['start_time'], 'end_time' => $r['end_time']];
            if ($r['faculty_id'] !== null) {
                $facultyBusy[(int) $r['faculty_id']][] = $slot;
            }
            if ($r['room_id'] !== null) {
                $roomBusy[(int) $r['room_id']][] = $slot;
            }
        }

        return [$facultyBusy, $roomBusy];
    }

    /** Find the best (instructor, room, pattern) for one section, or null. */
    private function placeSection(
        array $course,
        array &$faculty,
        array $rooms,
        array $patterns,
        array &$facultyBusy,
        array &$roomBusy
    ): ?array {
        $best = null;

        foreach ($faculty as $fid => $f) {
            $facultyScore = $this->scoreFaculty($f, $course);
            if ($facultyScore === null) {
                continue; // hard constraint failed (not qualified dept, over cap, ...)
            }
            foreach ($patterns as $pattern) {
                $days = explode(',', (string) $pattern['days']);
                if (!$this->facultyFreeForPattern($f, $days, $pattern, $facultyBusy[$fid] ?? [])) {
                    continue;
                }
                $patternScore = $this->scorePattern($f, $days, $pattern);

                foreach ($rooms as $room) {
                    if ((int) $room['capacity'] < min((int) $course['default_capacity'], 5)) {
                        continue;
                    }
                    if ((int) $room['capacity'] < (int) $course['default_capacity'] * 0.8) {
                        continue; // too small even with tolerance
                    }
                    if ((int) $course['requires_lab'] === 1 && $room['type'] === 'auditorium') {
                        continue;
                    }
                    if (!$this->roomFreeForPattern($days, $pattern, $roomBusy[(int) $room['id']] ?? [])) {
                        continue;
                    }
                    $required = json_decode((string) ($course['required_equipment'] ?? '[]'), true) ?: [];
                    $available = json_decode((string) ($room['equipment'] ?? '[]'), true) ?: [];
                    if (array_diff($required, $available) !== []) {
                        continue;
                    }
                    // Prefer snug rooms: penalize wasted seats.
                    $fitPenalty = max(0, (int) $room['capacity'] - (int) $course['default_capacity']) * 0.2;
                    $score = $facultyScore + $patternScore - $fitPenalty;

                    if ($best === null || $score > $best['score']) {
                        $best = [
                            'faculty_id' => $fid,
                            'faculty_name' => $f['full_name'],
                            'room_id' => (int) $room['id'],
                            'room_code' => $room['code'],
                            'room_capacity' => (int) $room['capacity'],
                            'pattern_code' => $pattern['code'],
                            'days' => $days,
                            'start_time' => $pattern['start_time'],
                            'end_time' => $pattern['end_time'],
                            'score' => round($score, 2),
                        ];
                    }
                }
            }
        }

        if ($best === null) {
            return null;
        }

        // Commit to in-memory state so subsequent placements respect this one.
        $fid = $best['faculty_id'];
        foreach ($best['days'] as $day) {
            $slot = ['day' => $day, 'start_time' => $best['start_time'], 'end_time' => $best['end_time']];
            $facultyBusy[$fid][] = $slot;
            $roomBusy[$best['room_id']][] = $slot;
        }
        $faculty[$fid]['assigned_credits'] += (float) $course['credit_hours'];
        $faculty[$fid]['assigned_contact'] = (float) $faculty[$fid]['assigned_contact'] + (float) $course['contact_hours'];
        $faculty[$fid]['preps'][(int) $course['id']] = true;

        return $best;
    }

    /** Null = ineligible (hard constraint); float = soft preference score. */
    private function scoreFaculty(array $f, array $course): ?float
    {
        if ((int) $f['department_id'] !== (int) $course['department_id']) {
            return null;
        }
        // Teaching capacity = contractual max minus releases minus non-teaching
        // duties (committees, coordination, advising) already on the books.
        $effectiveMax = (float) $f['max_credit_hours']
            - (float) $f['research_release_hours'] - (float) $f['admin_release_hours']
            - (float) $f['activity_hours'];
        if ($f['assigned_credits'] + (float) $course['credit_hours'] > $effectiveMax) {
            return null;
        }
        if ((float) $f['assigned_contact'] + (float) $course['contact_hours'] > (float) $f['max_contact_hours']) {
            return null;
        }

        $score = 0.0;
        $qual = $f['qualifications'][(int) $course['id']] ?? null;
        if ($qual !== null) {
            $score += match ($qual['level']) {
                'expert' => 10.0,
                'preferred' => 7.0,
                default => 4.0,
            };
            $score += min(3.0, (int) $qual['times_taught'] * 0.3); // historical familiarity
        } else {
            $score += 1.0; // same department but never taught it
        }
        // New prep penalty (max_preps soft handled by WorkloadService validation)
        if (!isset($f['preps'][(int) $course['id']]) && count($f['preps']) >= 2) {
            $score -= 2.0;
        }
        // Load balancing: favor faculty with more remaining capacity.
        $score += ($effectiveMax - $f['assigned_credits']) * 0.5;

        return $score;
    }

    private function scorePattern(array $f, array $days, array $pattern): float
    {
        $score = 0.0;
        $prefs = $f['prefs'];
        $preferredDays = $prefs['preferred_days'] ?? [];
        if ($preferredDays !== []) {
            $overlap = count(array_intersect($days, $preferredDays));
            $score += $overlap * 1.5;
        }
        if (($prefs['avoid_early'] ?? false) && $pattern['start_time'] < '09:30:00') {
            $score -= 3.0;
        }
        if (($prefs['evening_only'] ?? false) && $pattern['start_time'] < '16:00:00') {
            $score -= 10.0;
        }

        return $score;
    }

    private function facultyFreeForPattern(array $f, array $days, array $pattern, array $busy): bool
    {
        foreach ($days as $day) {
            // availability windows (if declared)
            if ($f['availability'] !== []) {
                $ok = false;
                foreach ($f['availability'] as $w) {
                    if ($w['day'] === $day && $w['preference'] !== 'unavailable'
                        && $w['start_time'] <= $pattern['start_time'] && $w['end_time'] >= $pattern['end_time']) {
                        $ok = true;
                        break;
                    }
                }
                if (!$ok) {
                    return false;
                }
            }
            foreach ($busy as $slot) {
                if ($slot['day'] === $day
                    && $slot['start_time'] < $pattern['end_time']
                    && $pattern['start_time'] < $slot['end_time']) {
                    return false;
                }
            }
        }

        return true;
    }

    private function roomFreeForPattern(array $days, array $pattern, array $busy): bool
    {
        foreach ($days as $day) {
            foreach ($busy as $slot) {
                if ($slot['day'] === $day
                    && $slot['start_time'] < $pattern['end_time']
                    && $pattern['start_time'] < $slot['end_time']) {
                    return false;
                }
            }
        }

        return true;
    }

    private function persistGeneratedSchedule(int $termId, array $created, bool $replace, ?int $departmentId): void
    {
        DB::transaction(function () use ($termId, $created, $replace, $departmentId) {
            if ($replace) {
                $params = [$termId];
                $deptFilter = '';
                if ($departmentId !== null) {
                    $deptFilter = ' AND course_id IN (SELECT id FROM courses WHERE department_id = ?)';
                    $params[] = $departmentId;
                }
                DB::execute("DELETE FROM sections WHERE term_id = ? AND status = 'draft'" . $deptFilter, $params);
            }
            foreach ($created as $row) {
                $sectionId = DB::insert('sections', [
                    'tenant_id' => Tenancy::requireId(),
                    'course_id' => $row['course_id'],
                    'term_id' => $termId,
                    'section_no' => $row['section_no'],
                    'faculty_id' => $row['faculty_id'],
                    'capacity' => $row['capacity'],
                    'status' => 'draft',
                ]);
                foreach ($row['days'] as $day) {
                    DB::insert('section_meetings', [
                        'section_id' => $sectionId,
                        'room_id' => $row['room_id'],
                        'day' => $day,
                        'start_time' => $row['start_time'],
                        'end_time' => $row['end_time'],
                    ]);
                }
            }
        });
    }
}
