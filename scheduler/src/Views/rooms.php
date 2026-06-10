<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Classrooms &amp; Resources — <?= e($term['name']) ?></h1>
  <div>
    <a class="btn btn-outline-secondary btn-sm" href="/api/v1/export/utilization/xlsx?term_id=<?= (int) $term['id'] ?>"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
    <a class="btn btn-outline-secondary btn-sm" target="_blank" href="/api/v1/export/utilization/pdf?term_id=<?= (int) $term['id'] ?>"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
  </div>
</div>

<?php $utilizationById = array_column($utilization, null, 'id'); ?>
<div class="card border-0 shadow-sm">
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead><tr>
        <th>Room</th><th>Name</th><th>Campus</th><th>Building</th><th>Type</th><th>Capacity</th>
        <th>Equipment</th><th>Hours/week</th><th style="width:160px">Utilization</th><th>Avg fill</th>
      </tr></thead>
      <tbody>
      <?php foreach ($rooms as $room): $u = $utilizationById[$room['id']] ?? null; ?>
      <tr>
        <td class="fw-semibold"><?= e($room['code']) ?></td>
        <td><?= e($room['name']) ?></td>
        <td><?= e($room['campus'] ?? '—') ?></td>
        <td><?= e($room['building'] ?? '—') ?></td>
        <td><span class="badge text-bg-light border"><?= e($room['type']) ?></span></td>
        <td><?= (int) $room['capacity'] ?></td>
        <td class="small">
          <?php foreach (json_decode((string) ($room['equipment'] ?? '[]'), true) ?: [] as $eq): ?>
          <span class="badge text-bg-light border"><?= e($eq) ?></span>
          <?php endforeach; ?>
        </td>
        <td><?= $u !== null ? e($u['used_hours']) : '0' ?> h</td>
        <td>
          <?php if ($u !== null && $u['utilization_pct'] !== null): ?>
          <div class="progress" style="height:16px">
            <div class="progress-bar <?= $u['utilization_pct'] < 20 ? 'bg-warning' : 'bg-success' ?>"
                 style="width:<?= min(100, $u['utilization_pct']) ?>%"><?= e($u['utilization_pct']) ?>%</div>
          </div>
          <?php else: ?><span class="text-muted small">n/a</span><?php endif; ?>
        </td>
        <td><?= $u !== null ? e($u['avg_fill_pct']) . '%' : '—' ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
