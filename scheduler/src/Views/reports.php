<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Reports &amp; Exports — <?= e($term['name']) ?></h1>

<div class="row g-3 mb-4">
  <?php
  $reports = [
      ['faculty-schedule', 'Faculty Schedule', 'Courses, sections, times, rooms, credit & contact hours per instructor.', 'person-lines-fill'],
      ['department-schedule', 'Department Schedule', 'Full timetable with room, instructor and meeting patterns.', 'calendar3'],
      ['workload', 'Faculty Workload Report', 'Teaching load, duties, releases, overloads & policy violations.', 'bar-chart-steps'],
      ['utilization', 'Classroom Utilization', 'Occupancy, utilization % and capacity analysis per room.', 'building'],
      ['conflicts', 'Scheduling Efficiency', 'All detected conflicts with AI resolution suggestions.', 'exclamation-triangle'],
  ];
  foreach ($reports as [$key, $name, $desc, $icon]): ?>
  <div class="col-md-6 col-xl-4">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-body">
        <div class="d-flex align-items-center mb-2">
          <div class="fs-3 text-primary me-2"><i class="bi bi-<?= e($icon) ?>"></i></div>
          <h2 class="h6 mb-0"><?= e($name) ?></h2>
        </div>
        <p class="small text-muted"><?= e($desc) ?></p>
        <div class="btn-group btn-group-sm">
          <?php foreach (['pdf' => 'file-earmark-pdf', 'xlsx' => 'file-earmark-excel', 'csv' => 'filetype-csv', 'json' => 'filetype-json'] as $fmt => $fIcon): ?>
          <a class="btn btn-outline-secondary" <?= $fmt === 'pdf' ? 'target="_blank"' : '' ?>
             href="/api/v1/export/<?= e($key) ?>/<?= e($fmt) ?>?term_id=<?= (int) $term['id'] ?>">
            <i class="bi bi-<?= e($fIcon) ?> me-1"></i><?= strtoupper($fmt) ?>
          </a>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  </div>
  <?php endforeach; ?>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-funnel me-1"></i>Filtered export</div>
      <div class="card-body">
        <form onsubmit="event.preventDefault(); runFilteredExport(this)">
          <div class="row g-2">
            <div class="col-md-6">
              <label class="form-label small">Report</label>
              <select class="form-select form-select-sm" name="report">
                <option value="department-schedule">Department schedule</option>
                <option value="faculty-schedule">Faculty schedule</option>
                <option value="workload">Workload</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Department</label>
              <select class="form-select form-select-sm" name="department_id">
                <option value="">All</option>
                <?php foreach ($departments as $d): ?>
                <option value="<?= (int) $d['id'] ?>"><?= e($d['code']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Faculty (faculty schedule only)</label>
              <select class="form-select form-select-sm" name="faculty_id">
                <option value="">All</option>
                <?php foreach ($facultyList as $f): ?>
                <option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option>
                <?php endforeach; ?>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label small">Format</label>
              <select class="form-select form-select-sm" name="format">
                <option>pdf</option><option>xlsx</option><option>csv</option><option>json</option><option>xml</option>
              </select>
            </div>
          </div>
          <button class="btn btn-primary btn-sm mt-3"><i class="bi bi-download me-1"></i>Export</button>
        </form>
      </div>
    </div>
  </div>
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-clock-history me-1"></i>Scheduled reports</div>
      <div class="card-body">
        <?php if ($scheduledReports === []): ?>
        <p class="text-muted small">No scheduled reports configured. Add a cron entry for <code>php bin/scheduled_reports.php</code> (see deployment guide) — daily conflict reports, weekly workload &amp; utilization, monthly executive dashboards.</p>
        <?php else: ?>
        <ul class="list-group list-group-flush">
          <?php foreach ($scheduledReports as $sr): ?>
          <li class="list-group-item small">
            <strong><?= e($sr['name']) ?></strong> — <?= e($sr['frequency']) ?>, <?= e(strtoupper($sr['format'])) ?>
            via <?= e($sr['delivery']) ?> · last run <?= e($sr['last_run_at'] ?? 'never') ?>
          </li>
          <?php endforeach; ?>
        </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<script>
function runFilteredExport(form) {
  const fd = new FormData(form);
  const params = new URLSearchParams({term_id: APP.termId});
  for (const [k, v] of fd.entries()) if (v && k !== 'report' && k !== 'format') params.set(k, v);
  const url = `/api/v1/export/${fd.get('report')}/${fd.get('format')}?${params}`;
  fd.get('format') === 'pdf' ? window.open(url) : (location.href = url);
}
</script>
