<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Auth;
use App\Core\Database as DB;

/**
 * AI layer: OpenAI-compatible LLM integration for the conversational
 * assistant, plus rule-based recommendation generation that works even
 * without an API key (the LLM enriches, never replaces, the deterministic
 * engines).
 *
 * Security model: the LLM never receives raw SQL access. We classify the
 * user's intent, run whitelisted queries server-side, and pass only the
 * resulting aggregates to the model as context.
 */
final class AIService
{
    public function __construct(
        private readonly array $config,
        private readonly SchedulingEngine $engine = new SchedulingEngine(),
        private readonly WorkloadService $workload = new WorkloadService(),
        private readonly AnalyticsService $analytics = new AnalyticsService(),
        private readonly ForecastService $forecast = new ForecastService(),
    ) {
    }

    public function isConfigured(): bool
    {
        return ($this->config['api_key'] ?? '') !== '';
    }

    // =================================================================
    // Conversational assistant
    // =================================================================

    public function chat(string $message, string $sessionId, int $termId): array
    {
        $userId = Auth::id() ?? 0;
        DB::insert('ai_chat_messages', [
            'user_id' => $userId, 'session_id' => $sessionId, 'role' => 'user', 'content' => $message,
        ]);

        // Gather grounded context based on detected intent (no raw DB access for the LLM).
        $context = $this->buildContext($message, $termId);

        if ($this->isConfigured()) {
            $reply = $this->completeWithLlm($message, $context, $userId, $sessionId);
        } else {
            $reply = $this->ruleBasedReply($message, $context, $termId);
        }

        DB::insert('ai_chat_messages', [
            'user_id' => $userId, 'session_id' => $sessionId, 'role' => 'assistant', 'content' => $reply,
        ]);

        return ['reply' => $reply, 'context_used' => array_keys($context)];
    }

    /** Intent-routed, permission-checked data gathering. */
    private function buildContext(string $message, int $termId): array
    {
        $m = mb_strtolower($message);
        $context = ['kpis' => $this->analytics->schedulingKpis($termId)];

        if (preg_match('/workload|overload|underload|teaching load|equity/', $m) && Auth::can('workload.view')) {
            $context['workloads'] = array_map(
                fn ($w) => array_intersect_key($w, array_flip([
                    'name', 'department', 'rank', 'contract_type', 'teaching_credits',
                    'activity_credits', 'total_credits', 'utilization_pct', 'load_status',
                ])),
                $this->workload->facultyWorkloads($termId)
            );
        }
        if (preg_match('/conflict|clash|double.?book/', $m) && Auth::can('schedule.view')) {
            $context['conflicts'] = DB::select(
                'SELECT type, severity, description, suggestion FROM schedule_conflicts
                 WHERE term_id = ? AND status = "open" LIMIT 50',
                [$termId]
            );
        }
        if (preg_match('/room|utiliz|classroom|space/', $m) && Auth::can('rooms.view')) {
            $context['room_utilization'] = $this->analytics->roomUtilization($termId);
        }
        if (preg_match('/enroll|demand|forecast|predict|waitlist/', $m) && Auth::can('reports.view')) {
            $context['enrollment'] = $this->analytics->enrollmentKpis($termId);
            $context['forecasts'] = $this->forecast->forecastTerm($termId, persist: false);
        }
        if (preg_match('/instructor|teacher|who (should|can) teach|suggest.*(faculty|professor)/', $m)
            && Auth::can('faculty.view')) {
            $context['faculty_qualifications'] = DB::select(
                'SELECT CONCAT(f.first_name, " ", f.last_name) name, c.code course, q.level, q.times_taught
                 FROM faculty_course_qualifications q
                 JOIN faculty f ON f.id = q.faculty_id
                 JOIN courses c ON c.id = q.course_id
                 WHERE f.status = "active" LIMIT 200'
            );
        }

        return $context;
    }

    private function completeWithLlm(string $message, array $context, int $userId, string $sessionId): string
    {
        $history = array_reverse(DB::select(
            'SELECT role, content FROM ai_chat_messages
             WHERE user_id = ? AND session_id = ? AND role IN ("user","assistant")
             ORDER BY id DESC LIMIT 10',
            [$userId, $sessionId]
        ));

        $messages = [[
            'role' => 'system',
            'content' => "You are the AI Scheduling Assistant for a university course scheduling and "
                . "faculty workload management system. Answer using ONLY the institutional data provided "
                . "below. Be specific and actionable: name faculty, courses, rooms, and times. When "
                . "recommending changes, explain the rationale (workload balance, qualifications, "
                . "utilization). If the data does not contain the answer, say so.\n\nINSTITUTIONAL DATA:\n"
                . json_encode($context, JSON_UNESCAPED_UNICODE),
        ]];
        foreach ($history as $h) {
            $messages[] = ['role' => $h['role'], 'content' => $h['content']];
        }

        $response = $this->callApi($messages);

        return $response ?? 'The AI service is currently unavailable. Please try again later.';
    }

    private function callApi(array $messages): ?string
    {
        $payload = json_encode([
            'model' => $this->config['model'],
            'messages' => $messages,
            'max_tokens' => $this->config['max_tokens'],
            'temperature' => 0.2,
        ]);

        $ch = curl_init($this->config['base_url'] . '/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $this->config['api_key'],
            ],
            CURLOPT_TIMEOUT => 60,
        ]);
        $raw = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false || $status >= 400) {
            error_log("AI API call failed (HTTP $status)");

            return null;
        }
        $data = json_decode((string) $raw, true);

        return $data['choices'][0]['message']['content'] ?? null;
    }

    /** Deterministic fallback so the assistant is useful without an API key. */
    private function ruleBasedReply(string $message, array $context, int $termId): string
    {
        $m = mb_strtolower($message);

        if (isset($context['workloads'])) {
            $over = array_filter($context['workloads'], fn ($w) => $w['load_status'] === 'overloaded');
            $under = array_filter($context['workloads'], fn ($w) => $w['load_status'] === 'underloaded');
            $lines = ["**Workload summary** (" . count($context['workloads']) . " faculty):"];
            foreach ($over as $w) {
                $lines[] = sprintf('- ⚠️ %s (%s) is **overloaded**: %.1f credit hours (%.0f%% utilization)',
                    $w['name'], $w['department'], $w['total_credits'], $w['utilization_pct']);
            }
            foreach ($under as $w) {
                $lines[] = sprintf('- %s (%s) is underloaded: %.1f credit hours',
                    $w['name'], $w['department'], $w['total_credits']);
            }
            if ($over === [] && $under === []) {
                $lines[] = 'All faculty workloads are within policy limits. ✅';
            }

            return implode("\n", $lines);
        }

        if (isset($context['conflicts'])) {
            if ($context['conflicts'] === []) {
                return 'No open scheduling conflicts for this term. ✅';
            }
            $lines = ['**Open conflicts** (' . count($context['conflicts']) . '):'];
            foreach (array_slice($context['conflicts'], 0, 15) as $c) {
                $lines[] = sprintf('- [%s/%s] %s%s', $c['type'], $c['severity'], $c['description'],
                    $c['suggestion'] ? "\n  → Suggestion: {$c['suggestion']}" : '');
            }

            return implode("\n", $lines);
        }

        if (isset($context['room_utilization'])) {
            $lines = ['**Room utilization** (weekday 08:00–18:00 window):'];
            foreach ($context['room_utilization'] as $r) {
                if ($r['utilization_pct'] === null) {
                    continue;
                }
                $flag = $r['utilization_pct'] < 20 ? ' — underutilized' : ($r['utilization_pct'] > 80 ? ' — near capacity' : '');
                $lines[] = sprintf('- %s (%s, %d seats): %.1f h/week, %.0f%%%s',
                    $r['code'], $r['type'], $r['capacity'], $r['used_hours'], $r['utilization_pct'], $flag);
            }

            return implode("\n", $lines);
        }

        if (isset($context['forecasts'])) {
            $lines = ['**Enrollment forecast** (trend-based):'];
            foreach ($context['forecasts'] as $f) {
                $lines[] = sprintf('- %s: predicted %d students (%s)',
                    $f['course'], $f['predicted'], $f['basis']);
            }

            return implode("\n", $lines);
        }

        if (isset($context['faculty_qualifications'])) {
            // Try to extract a course code from the question.
            if (preg_match('/[a-z]{2,4}\s?\d{3}/i', $message, $mm)) {
                $code = strtoupper(str_replace(' ', '', $mm[0]));
                $matches = array_filter($context['faculty_qualifications'],
                    fn ($q) => $q['course'] === $code);
                if ($matches !== []) {
                    usort($matches, fn ($a, $b) =>
                        [$b['level'] === 'expert', $b['times_taught']] <=> [$a['level'] === 'expert', $a['times_taught']]);
                    $lines = ["**Recommended instructors for $code:**"];
                    foreach ($matches as $q) {
                        $lines[] = sprintf('- %s — %s, taught %d times', $q['name'], $q['level'], $q['times_taught']);
                    }

                    return implode("\n", $lines);
                }

                return "No qualified instructors found for $code in the qualification records.";
            }
        }

        if (preg_match('/generate|create.*schedule|build.*schedule/', $m)) {
            return 'I can generate a draft schedule for this term. Use the **Generate Schedule** button on '
                . 'the Scheduler page, or POST /api/v1/terms/{id}/generate. I will respect faculty '
                . 'qualifications, availability, workload caps, and room constraints.';
        }

        $k = $context['kpis'];

        return sprintf(
            "Here's the current term at a glance:\n- Sections: %d (%d draft, %d approved)\n"
            . "- Courses scheduled: %d (%d still unscheduled)\n- Open conflicts: %d (%d errors)\n"
            . "- Sections without instructor: %d\n- Completion: %.1f%%\n\n"
            . "Ask me about conflicts, workloads, rooms, enrollment forecasts, or instructor suggestions.",
            $k['total_sections'], $k['draft_sections'], $k['approved_sections'],
            $k['total_courses_scheduled'], $k['unscheduled_courses'],
            $k['open_conflicts'], $k['error_conflicts'],
            $k['unassigned_faculty_sections'], $k['completion_pct']
        );
    }

    // =================================================================
    // Recommendation engine
    // =================================================================

    /** Generate and persist actionable recommendations for a term. */
    public function generateRecommendations(int $termId): array
    {
        $recommendations = [];

        // 1. Workload rebalancing
        foreach ($this->workload->rebalancingSuggestions($termId) as $s) {
            $recommendations[] = [
                'category' => 'workload',
                'title' => sprintf('Reassign %s from %s to %s', $s['course'], $s['from'], $s['to']),
                'detail' => $s['rationale'],
                'payload' => ['action' => 'reassign_section', 'section_id' => $s['section_id'],
                              'to_faculty_id' => $s['to_faculty_id']],
            ];
        }

        // 2. Demand-driven extra sections
        $demand = DB::select(
            'SELECT c.id, c.code, c.default_capacity,
                    COALESCE(ef.predicted_enrollment, eh.enrolled, 0) demand,
                    COALESCE(SUM(s.capacity), 0) offered_capacity
             FROM courses c
             LEFT JOIN enrollment_forecasts ef ON ef.course_id = c.id AND ef.term_id = ?
             LEFT JOIN enrollment_history eh ON eh.course_id = c.id
                 AND eh.term_id = (SELECT MAX(term_id) FROM enrollment_history WHERE course_id = c.id)
             LEFT JOIN sections s ON s.course_id = c.id AND s.term_id = ? AND s.status <> "cancelled"
             WHERE c.is_active = 1
             GROUP BY c.id
             HAVING demand > offered_capacity AND offered_capacity > 0',
            [$termId, $termId]
        );
        foreach ($demand as $d) {
            $shortfall = (int) $d['demand'] - (int) $d['offered_capacity'];
            $extra = (int) ceil($shortfall / max(1, (int) $d['default_capacity']));
            $recommendations[] = [
                'category' => 'section',
                'title' => sprintf('Add %d section(s) of %s', $extra, $d['code']),
                'detail' => sprintf(
                    'Predicted demand %d exceeds offered capacity %d by %d seats.',
                    (int) $d['demand'], (int) $d['offered_capacity'], $shortfall
                ),
                'payload' => ['action' => 'add_sections', 'course_id' => (int) $d['id'], 'count' => $extra],
            ];
        }

        // 3. Underutilized rooms
        foreach ($this->analytics->roomUtilization($termId) as $r) {
            if ($r['utilization_pct'] !== null && $r['utilization_pct'] < 15 && $r['type'] !== 'online') {
                $recommendations[] = [
                    'category' => 'room',
                    'title' => sprintf('Room %s is underutilized (%.0f%%)', $r['code'], $r['utilization_pct']),
                    'detail' => 'Consider consolidating sections into this room or releasing it for events.',
                    'payload' => ['action' => 'review_room', 'room_code' => $r['code']],
                ];
            }
        }

        // 4. Staffing gaps: courses with demand but no qualified active faculty
        $gaps = DB::select(
            'SELECT c.code FROM courses c
             WHERE c.is_active = 1
               AND NOT EXISTS (SELECT 1 FROM faculty_course_qualifications q
                               JOIN faculty f ON f.id = q.faculty_id AND f.status = "active"
                               WHERE q.course_id = c.id)'
        );
        foreach ($gaps as $g) {
            $recommendations[] = [
                'category' => 'staffing',
                'title' => sprintf('No qualified active faculty for %s', $g['code']),
                'detail' => 'Hire, certify existing faculty, or engage an adjunct for this course.',
                'payload' => ['action' => 'staffing_gap', 'course' => $g['code']],
            ];
        }

        DB::transaction(function () use ($termId, $recommendations) {
            DB::execute('DELETE FROM ai_recommendations WHERE term_id = ? AND status = "open"', [$termId]);
            foreach ($recommendations as $r) {
                DB::insert('ai_recommendations', [
                    'term_id' => $termId,
                    'category' => $r['category'],
                    'title' => mb_substr($r['title'], 0, 200),
                    'detail' => $r['detail'],
                    'payload' => json_encode($r['payload']),
                ]);
            }
        });

        return $recommendations;
    }
}
