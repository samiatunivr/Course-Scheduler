<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Faculty Workload — <?= e($term['name']) ?></h1>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="/api/v1/export/workload/xlsx?term_id=<?= (int) $term['id'] ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a class="btn btn-outline-secondary btn-sm" href="/api/v1/export/workload/csv?term_id=<?= (int) $term['id'] ?>"><i class="bi bi-filetype-csv me-1"></i>CSV</a>
    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="/api/v1/export/workload/pdf?term_id=<?= (int) $term['id'] ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php foreach ($deptAnalytics as $d): ?>
  <div class="col-md-3">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="fw-semibold"><?= e($d['department']) ?> <span class="text-muted small">(<?= (int) $d['faculty_count'] ?> faculty)</span></div>
        <div class="small">Avg load: <strong><?= e($d['avg_credits']) ?> cr</strong> · σ <?= e($d['std_dev']) ?></div>
        <div class="small">Equity index: <strong><?= e($d['equity_index']) ?></strong> · Utilization <?= e($d['avg_utilization_pct']) ?>%</div>
        <div class="small">
          <span class="text-danger"><?= (int) $d['overloaded'] ?> over</span> /
          <span class="text-warning"><?= (int) $d['underloaded'] ?> under</span>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="card border-0 shadow-sm mb-4">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-people me-1"></i>Faculty workloads &amp; policy validation</div>
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead><tr>
        <th>Faculty</th><th>Dept</th><th>Rank</th><th>Contract</th>
        <th>Teaching</th><th>Contact</th><th>Preps</th><th>Other duties</th><th>Total</th>
        <th style="width:160px">Utilization</th><th>Status</th><th>Policy violations</th>
      </tr></thead>
      <tbody>
      <?php foreach ($workloads as $w): ?>
      <tr>
        <td class="fw-semibold"><?= e($w['name']) ?></td>
        <td><?= e($w['department']) ?></td>
        <td><?= e(str_replace('_', ' ', $w['rank'])) ?></td>
        <td><?= e(str_replace('_', ' ', $w['contract_type'])) ?></td>
        <td><?= e($w['teaching_credits']) ?> cr</td>
        <td><?= e($w['contact_hours']) ?> h</td>
        <td><?= (int) $w['preps'] ?></td>
        <td><?= e($w['activity_credits']) ?> cr</td>
        <td class="fw-semibold"><?= e($w['total_credits']) ?> / <?= e($w['max_credit_hours']) ?></td>
        <td>
          <div class="progress" style="height:16px">
            <div class="progress-bar <?= $w['utilization_pct'] > 100 ? 'bg-danger' : ($w['utilization_pct'] < 50 ? 'bg-warning' : 'bg-success') ?>"
                 style="width:<?= min(100, $w['utilization_pct']) ?>%"><?= e($w['utilization_pct']) ?>%</div>
          </div>
        </td>
        <td>
          <?php $badge = ['overloaded' => 'danger', 'underloaded' => 'warning', 'balanced' => 'success', 'on_leave' => 'secondary'][$w['load_status']]; ?>
          <span class="badge text-bg-<?= $badge ?>"><?= e(str_replace('_', ' ', $w['load_status'])) ?></span>
        </td>
        <td class="small text-danger">
          <?= e(implode('; ', array_map(fn ($v) => $v['policy'], $w['violations']))) ?: '—' ?>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-stars me-1"></i>AI rebalancing suggestions</div>
  <div class="card-body p-0">
    <?php if ($suggestions === []): ?>
    <p class="text-muted p-3 mb-0">No rebalancing needed — workloads are within policy limits.</p>
    <?php else: ?>
    <ul class="list-group list-group-flush">
      <?php foreach ($suggestions as $s): ?>
      <li class="list-group-item d-flex justify-content-between align-items-center">
        <div>
          <strong>Move <?= e($s['course']) ?></strong> (<?= e($s['credit_hours']) ?> cr):
          <?= e($s['from']) ?> → <?= e($s['to']) ?>
          <div class="small text-muted"><?= e($s['rationale']) ?></div>
        </div>
        <button class="btn btn-sm btn-outline-primary"
                onclick="api('PUT', `/api/v1/sections/<?= (int) $s['section_id'] ?>`, {faculty_id: <?= (int) $s['to_faculty_id'] ?>}).then(()=>location.reload())">
          Apply
        </button>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</div>
