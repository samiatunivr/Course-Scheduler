<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database as DB;

/**
 * Scenario planning: run "what if" simulations (enrollment surge, faculty
 * resignation, room closures, new offerings) as dry-run schedule
 * generations and compare metrics against the current schedule — without
 * touching live data.
 */
final class ScenarioService
{
    public function __construct(
        private readonly SchedulingEngine $engine = new SchedulingEngine(),
        private readonly AnalyticsService $analytics = new AnalyticsService(),
    ) {
    }

    /**
     * @param array $parameters {
     *   enrollment_delta_pct?: float,   // e.g. 20 = +20% demand
     *   exclude_faculty?: int[],        // simulate resignations / leaves
     *   exclude_rooms?: int[],          // simulate room closures
     *   department_id?: int|null
     * }
     */
    public function simulate(int $termId, string $name, array $parameters): array
    {
        $baseline = $this->analytics->schedulingKpis($termId);

        $result = $this->engine->generateSchedule(
            $termId,
            isset($parameters['department_id']) ? (int) $parameters['department_id'] : null,
            [
                'dry_run' => true,
                'replace' => true,
                'enrollment_delta_pct' => (float) ($parameters['enrollment_delta_pct'] ?? 0),
                'exclude_faculty' => $parameters['exclude_faculty'] ?? [],
                'exclude_rooms' => $parameters['exclude_rooms'] ?? [],
            ]
        );

        $summary = [
            'baseline' => $baseline,
            'simulated' => [
                'sections_created' => $result['sections_created'],
                'unassigned_count' => count($result['unassigned']),
                'unassigned' => $result['unassigned'],
            ],
            'feasible' => count($result['unassigned']) === 0,
            'sections' => $result['sections'],
        ];

        $scenarioId = DB::insert('scenarios', [
            'term_id' => $termId,
            'name' => $name,
            'description' => $this->describe($parameters),
            'parameters' => json_encode($parameters),
            'result' => json_encode([
                'baseline' => $summary['baseline'],
                'simulated' => $summary['simulated'],
                'feasible' => $summary['feasible'],
            ]),
            'status' => 'simulated',
            'created_by' => Auth::id(),
        ]);
        Audit::log('scenario_simulate', 'scenario', $scenarioId, null, $parameters);

        return ['scenario_id' => $scenarioId] + $summary;
    }

    /** Apply a simulated scenario: regenerate draft sections for real. */
    public function apply(int $scenarioId): array
    {
        $scenario = DB::selectOne('SELECT * FROM scenarios WHERE id = ?', [$scenarioId]);
        if ($scenario === null || $scenario['status'] !== 'simulated') {
            throw new \RuntimeException('Scenario not found or not simulated yet');
        }
        $parameters = json_decode((string) $scenario['parameters'], true) ?: [];

        $result = $this->engine->generateSchedule(
            (int) $scenario['term_id'],
            isset($parameters['department_id']) ? (int) $parameters['department_id'] : null,
            [
                'dry_run' => false,
                'replace' => true,
                'enrollment_delta_pct' => (float) ($parameters['enrollment_delta_pct'] ?? 0),
                'exclude_faculty' => $parameters['exclude_faculty'] ?? [],
                'exclude_rooms' => $parameters['exclude_rooms'] ?? [],
            ]
        );

        DB::update('scenarios', $scenarioId, ['status' => 'applied']);
        Audit::log('scenario_apply', 'scenario', $scenarioId);

        return $result;
    }

    private function describe(array $p): string
    {
        $parts = [];
        if (!empty($p['enrollment_delta_pct'])) {
            $parts[] = sprintf('enrollment %+.0f%%', (float) $p['enrollment_delta_pct']);
        }
        if (!empty($p['exclude_faculty'])) {
            $parts[] = count($p['exclude_faculty']) . ' faculty unavailable';
        }
        if (!empty($p['exclude_rooms'])) {
            $parts[] = count($p['exclude_rooms']) . ' room(s) closed';
        }

        return $parts === [] ? 'Baseline regeneration' : implode(', ', $parts);
    }
}
