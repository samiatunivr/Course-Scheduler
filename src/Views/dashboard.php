<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Dashboard — <?= e($term['name']) ?></h1>
  <div>
    <button class="btn btn-outline-primary btn-sm" onclick="api('POST', `/api/v1/terms/${APP.termId}/detect-conflicts`).then(()=>location.reload())">
      <i class="bi bi-search me-1"></i>Re-validate
    </button>
    <button class="btn btn-primary btn-sm" onclick="api('POST', `/api/v1/terms/${APP.termId}/recommendations`).then(()=>location.reload())">
      <i class="bi bi-stars me-1"></i>Refresh AI Recommendations
    </button>
  </div>
</div>

<div class="row g-3 mb-4">
  <?php
  $cards = [
      ['Sections', $kpis['total_sections'], 'collection', 'primary'],
      ['Courses scheduled', $kpis['total_courses_scheduled'], 'journal-bookmark', 'primary'],
      ['Unscheduled courses', $kpis['unscheduled_courses'], 'journal-x', $kpis['unscheduled_courses'] > 0 ? 'warning' : 'success'],
      ['Open conflicts', $kpis['open_conflicts'], 'exclamation-triangle', $kpis['error_conflicts'] > 0 ? 'danger' : 'success'],
      ['No instructor', $kpis['unassigned_faculty_sections'], 'person-x', $kpis['unassigned_faculty_sections'] > 0 ? 'warning' : 'success'],
      ['Completion', $kpis['completion_pct'] . '%', 'check2-circle', $kpis['completion_pct'] >= 95 ? 'success' : 'info'],
  ];
  foreach ($cards as [$label, $value, $icon, $color]): ?>
  <div class="col-6 col-md-4 col-xl-2">
    <div class="card kpi-card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="text-<?= e($color) ?> fs-4"><i class="bi bi-<?= e($icon) ?>"></i></div>
        <div class="fs-4 fw-bold"><?= e($value) ?></div>
        <div class="text-muted small"><?= e($label) ?></div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-building me-1"></i>Room utilization %</div>
      <div class="card-body"><canvas id="roomChart" height="220"></canvas></div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-bar-chart me-1"></i>Department workload distribution</div>
      <div class="card-body"><canvas id="deptChart" height="220"></canvas></div>
    </div>
  </div>
</div>

<div class="row g-3 mb-4">
  <div class="col-lg-7">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-grid-3x3 me-1"></i>Weekly schedule heat map</div>
      <div class="card-body table-responsive">
        <table class="table table-sm text-center align-middle heatmap mb-0">
          <thead><tr><th></th><?php for ($h = 8; $h <= 19; $h++): ?><th><?= $h ?>:00</th><?php endfor; ?></tr></thead>
          <tbody>
          <?php $max = max(1, max(array_map(fn ($r) => max($r), $heatmap)));
          foreach ($heatmap as $day => $hours): ?>
            <tr><th class="text-start"><?= e($day) ?></th>
            <?php foreach ($hours as $n): $alpha = $n / $max; ?>
              <td style="background: rgba(31,78,121,<?= round($alpha, 2) ?>); color:<?= $alpha > 0.5 ? '#fff' : '#333' ?>"><?= $n ?: '' ?></td>
            <?php endforeach; ?></tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-stars me-1"></i>AI recommendations</div>
      <div class="card-body p-0">
        <?php if ($recommendations === []): ?>
        <p class="text-muted p-3 mb-0">No open recommendations. Click “Refresh AI Recommendations”.</p>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($recommendations as $rec): ?>
          <li class="list-group-item">
            <span class="badge text-bg-secondary me-1"><?= e($rec['category']) ?></span>
            <strong><?= e($rec['title']) ?></strong>
            <div class="small text-muted"><?= e($rec['detail']) ?></div>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-header bg-white fw-semibold"><i class="bi bi-mortarboard me-1"></i>Enrollment &amp; fill rate by course</div>
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead><tr><th>Course</th><th>Title</th><th>Sections</th><th>Enrolled</th><th>Capacity</th><th>Waitlist</th><th>Forecast</th><th style="width:180px">Fill rate</th></tr></thead>
      <tbody>
      <?php foreach ($enrollment as $row): ?>
      <tr>
        <td class="fw-semibold"><?= e($row['code']) ?></td>
        <td><?= e($row['title']) ?></td>
        <td><?= (int) $row['sections'] ?></td>
        <td><?= (int) $row['enrolled'] ?></td>
        <td><?= (int) $row['capacity'] ?></td>
        <td><?= (int) $row['waitlisted'] ?></td>
        <td><?= $row['predicted_enrollment'] !== null ? (int) $row['predicted_enrollment'] : '—' ?></td>
        <td>
          <div class="progress" style="height:18px">
            <div class="progress-bar <?= $row['fill_rate_pct'] > 95 ? 'bg-danger' : ($row['fill_rate_pct'] > 75 ? 'bg-warning' : 'bg-success') ?>"
                 style="width:<?= min(100, $row['fill_rate_pct']) ?>%"><?= $row['fill_rate_pct'] ?>%</div>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script>
window.DASH = {
  rooms: <?= json_encode(array_values(array_filter($rooms, fn ($r) => $r['utilization_pct'] !== null))) ?>,
  depts: <?= json_encode($deptAnalytics) ?>
};
</script>
