<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Approval Workflow — <?= e($term['name']) ?></h1>

<p class="text-muted small">Pipeline: Draft → AI validation → Chair review → Dean review → Registrar approval → Academic Affairs → Published. AI validation blocks advancement while error-level conflicts exist.</p>

<div class="row g-3" id="wfCards">
  <?php $byDept = array_column($workflows, null, 'department_id');
  foreach ($departments as $d):
      $wf = $byDept[$d['id']] ?? null;
      $step = $wf['current_step'] ?? 'draft';
      $steps = ['draft', 'ai_validation', 'chair_review', 'dean_review', 'registrar_approval', 'academic_affairs', 'published'];
      $idx = (int) array_search($step, $steps, true);
  ?>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white d-flex justify-content-between align-items-center">
        <span class="fw-semibold"><?= e($d['code']) ?> — <?= e($d['name']) ?></span>
        <span class="badge text-bg-<?= $step === 'published' ? 'success' : 'info' ?>"><?= e(str_replace('_', ' ', $step)) ?></span>
      </div>
      <div class="card-body">
        <div class="d-flex justify-content-between mb-3 workflow-steps">
          <?php foreach ($steps as $i => $s): ?>
          <div class="text-center flex-fill step <?= $i <= $idx ? 'done' : '' ?>">
            <div class="step-dot"><?= $i < $idx ? '✓' : $i + 1 ?></div>
            <div class="step-label"><?= e(str_replace('_', ' ', $s)) ?></div>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="d-flex gap-2">
          <?php if ($step !== 'published'): ?>
          <button class="btn btn-primary btn-sm" onclick="advance(<?= (int) $d['id'] ?>)">
            <i class="bi bi-arrow-right-circle me-1"></i>Advance
          </button>
          <button class="btn btn-outline-danger btn-sm" onclick="reject(<?= (int) $d['id'] ?>)">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Return to draft
          </button>
          <?php endif; ?>
          <button class="btn btn-outline-secondary btn-sm" onclick="history_(<?= (int) $d['id'] ?>)">
            <i class="bi bi-clock-history me-1"></i>History
          </button>
        </div>
        <div class="small mt-2" id="wfDetail<?= (int) $d['id'] ?>"></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<script>
const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
async function advance(deptId) {
  const comment = prompt('Optional comment for this approval step:') || '';
  const r = await api('POST', `/api/v1/terms/${APP.termId}/workflow/${deptId}/advance`, {comment});
  if (r.advanced === false) {
    document.getElementById('wfDetail' + deptId).innerHTML =
      `<div class="alert alert-danger py-1 small mt-2">${esc(r.message)}</div>` +
      '<ul class="small text-danger">' + (r.conflicts || []).slice(0, 8).map(c => `<li>${esc(c.description)}</li>`).join('') + '</ul>';
  } else { location.reload(); }
}
async function reject(deptId) {
  const comment = prompt('Reason for returning to draft:');
  if (comment === null) return;
  await api('POST', `/api/v1/terms/${APP.termId}/workflow/${deptId}/reject`, {comment});
  location.reload();
}
async function history_(deptId) {
  const r = await api('GET', `/api/v1/terms/${APP.termId}/workflow/${deptId}`);
  document.getElementById('wfDetail' + deptId).innerHTML =
    '<h6 class="small fw-bold mt-2">Audit trail</h6><ul class="small">' +
    (r.history.length ? r.history.map(h =>
      `<li>${esc(h.created_at)} — <strong>${esc(h.action)}</strong> at ${esc(h.step)} by ${esc(h.actor || 'system')}${h.comment ? ': “' + esc(h.comment) + '”' : ''}</li>`).join('')
      : '<li class="text-muted">No actions yet</li>') + '</ul>';
}
</script>
