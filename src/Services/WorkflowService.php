<?php

declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\Auth;
use App\Core\Database as DB;

/**
 * Approval workflow: draft → AI validation → chair → dean → registrar →
 * academic affairs → published. Each transition is recorded with the
 * acting user and optional comment (full audit trail).
 */
final class WorkflowService
{
    public const STEPS = [
        'draft', 'ai_validation', 'chair_review', 'dean_review',
        'registrar_approval', 'academic_affairs', 'published',
    ];

    private const STEP_PERMISSIONS = [
        'ai_validation' => 'schedule.edit',
        'chair_review' => 'schedule.edit',
        'dean_review' => 'schedule.approve',
        'registrar_approval' => 'schedule.approve',
        'academic_affairs' => 'schedule.approve',
        'published' => 'schedule.publish',
    ];

    public function __construct(
        private readonly SchedulingEngine $engine = new SchedulingEngine(),
    ) {
    }

    public function getOrCreate(int $termId, int $departmentId): array
    {
        $wf = DB::selectOne(
            'SELECT * FROM approval_workflows WHERE term_id = ? AND department_id = ?',
            [$termId, $departmentId]
        );
        if ($wf === null) {
            $id = DB::insert('approval_workflows', [
                'term_id' => $termId, 'department_id' => $departmentId,
            ]);
            $wf = DB::selectOne('SELECT * FROM approval_workflows WHERE id = ?', [$id]);
        }

        return $wf;
    }

    /** Advance to the next step. AI validation must pass with zero errors. */
    public function advance(int $termId, int $departmentId, string $comment = ''): array
    {
        $wf = $this->getOrCreate($termId, $departmentId);
        $currentIndex = array_search($wf['current_step'], self::STEPS, true);
        if ($currentIndex === false || $currentIndex >= count(self::STEPS) - 1) {
            throw new \RuntimeException('Workflow is already published');
        }
        $nextStep = self::STEPS[$currentIndex + 1];

        $requiredPermission = self::STEP_PERMISSIONS[$nextStep] ?? 'schedule.edit';
        if (!Auth::can($requiredPermission)) {
            throw new \RuntimeException("Missing permission '$requiredPermission' for step '$nextStep'");
        }

        // Gate: entering ai_validation (or beyond) requires conflict-free schedule.
        if ($nextStep === 'chair_review') {
            $conflicts = $this->engine->detectConflicts($termId);
            $errors = array_filter($conflicts, fn ($c) => $c['severity'] === 'error');
            if ($errors !== []) {
                DB::insert('approval_actions', [
                    'workflow_id' => (int) $wf['id'], 'step' => 'ai_validation',
                    'action' => 'rejected', 'user_id' => Auth::id(),
                    'comment' => sprintf('AI validation failed: %d error-level conflicts', count($errors)),
                ]);

                return [
                    'advanced' => false,
                    'step' => 'ai_validation',
                    'message' => sprintf('AI validation failed: %d error-level conflicts must be resolved first.',
                        count($errors)),
                    'conflicts' => array_values($errors),
                ];
            }
        }

        $isPublish = $nextStep === 'published';
        DB::update('approval_workflows', (int) $wf['id'], [
            'current_step' => $nextStep,
            'status' => $isPublish ? 'published' : 'in_progress',
        ]);
        DB::insert('approval_actions', [
            'workflow_id' => (int) $wf['id'], 'step' => $nextStep,
            'action' => $isPublish ? 'published' : 'approved',
            'user_id' => Auth::id(), 'comment' => $comment ?: null,
        ]);

        if ($isPublish) {
            DB::execute(
                'UPDATE sections s JOIN courses c ON c.id = s.course_id
                 SET s.status = "published"
                 WHERE s.term_id = ? AND c.department_id = ? AND s.status IN ("draft","scheduled","approved")',
                [$termId, $departmentId]
            );
        }

        Audit::log('workflow_advance', 'approval_workflow', (int) $wf['id'],
            ['step' => $wf['current_step']], ['step' => $nextStep]);

        return ['advanced' => true, 'step' => $nextStep, 'message' => "Advanced to $nextStep."];
    }

    public function reject(int $termId, int $departmentId, string $comment): array
    {
        $wf = $this->getOrCreate($termId, $departmentId);
        DB::update('approval_workflows', (int) $wf['id'], [
            'current_step' => 'draft', 'status' => 'rejected',
        ]);
        DB::insert('approval_actions', [
            'workflow_id' => (int) $wf['id'], 'step' => (string) $wf['current_step'],
            'action' => 'rejected', 'user_id' => Auth::id(), 'comment' => $comment,
        ]);
        Audit::log('workflow_reject', 'approval_workflow', (int) $wf['id']);

        return ['step' => 'draft', 'message' => 'Schedule returned to draft with comments.'];
    }

    public function history(int $termId, int $departmentId): array
    {
        $wf = $this->getOrCreate($termId, $departmentId);

        return DB::select(
            'SELECT a.step, a.action, a.comment, a.created_at, u.name actor
             FROM approval_actions a LEFT JOIN users u ON u.id = a.user_id
             WHERE a.workflow_id = ? ORDER BY a.id',
            [(int) $wf['id']]
        );
    }
}
