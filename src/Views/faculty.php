<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Faculty Profiles</h1>
  <a class="btn btn-outline-secondary btn-sm" href="/api/v1/export/faculty-schedule/xlsx?term_id=<?= (int) $term['id'] ?>">
    <i class="bi bi-file-earmark-excel me-1"></i>Export schedules
  </a>
</div>

<div class="card border-0 shadow-sm">
  <div class="card-body table-responsive">
    <table class="table table-sm table-hover align-middle">
      <thead><tr>
        <th>Name</th><th>Email</th><th>Dept</th><th>Rank</th><th>Contract</th><th>Status</th>
        <th>Max load</th><th>Releases</th><th>Expertise</th><th>Qualified courses</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($faculty as $f): ?>
      <tr>
        <td class="fw-semibold"><?= e($f['first_name'] . ' ' . $f['last_name']) ?></td>
        <td class="small"><?= e($f['email']) ?></td>
        <td><?= e($f['department']) ?></td>
        <td><?= e(str_replace('_', ' ', $f['rank'])) ?></td>
        <td><?= e(str_replace('_', ' ', $f['contract_type'])) ?></td>
        <td><span class="badge text-bg-<?= $f['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e($f['status']) ?></span></td>
        <td><?= e($f['max_credit_hours']) ?> cr / <?= e($f['max_contact_hours']) ?> h</td>
        <td class="small">
          <?php if ((float) $f['research_release_hours'] > 0): ?>R: <?= e($f['research_release_hours']) ?><?php endif; ?>
          <?php if ((float) $f['admin_release_hours'] > 0): ?> A: <?= e($f['admin_release_hours']) ?><?php endif; ?>
        </td>
        <td class="small">
          <?php foreach (array_slice(json_decode((string) ($f['expertise'] ?? '[]'), true) ?: [], 0, 3) as $exp): ?>
          <span class="badge text-bg-light border"><?= e($exp) ?></span>
          <?php endforeach; ?>
        </td>
        <td><?= (int) $f['qualifications_count'] ?></td>
        <td><button class="btn btn-sm btn-outline-primary" onclick="showFaculty(<?= (int) $f['id'] ?>)"><i class="bi bi-eye"></i></button></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<div class="modal fade" id="facultyModal" tabindex="-1">
  <div class="modal-dialog modal-lg">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title" id="facName">Faculty</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body" id="facBody"><div class="text-center p-4"><div class="spinner-border"></div></div></div>
    </div>
  </div>
</div>

<script>
async function showFaculty(id) {
  const modal = new bootstrap.Modal(document.getElementById('facultyModal'));
  modal.show();
  const f = await api('GET', `/api/v1/faculty/${id}`);
  document.getElementById('facName').textContent = `${f.first_name} ${f.last_name}`;
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  document.getElementById('facBody').innerHTML = `
    <div class="row">
      <div class="col-md-6">
        <h6>Availability</h6>
        <ul class="small list-unstyled">${(f.availability || []).map(a =>
          `<li><strong>${esc(a.day)}</strong> ${esc(a.start_time).slice(0,5)}–${esc(a.end_time).slice(0,5)} <span class="badge text-bg-light border">${esc(a.preference)}</span></li>`).join('') || '<li class="text-muted">None declared</li>'}</ul>
        <h6>Qualifications</h6>
        <ul class="small list-unstyled">${(f.course_qualifications || []).map(q =>
          `<li><strong>${esc(q.code)}</strong> ${esc(q.title)} — ${esc(q.level)} (taught ${q.times_taught}×)</li>`).join('') || '<li class="text-muted">None</li>'}</ul>
      </div>
      <div class="col-md-6">
        <h6>Teaching history</h6>
        <ul class="small list-unstyled" style="max-height:300px;overflow:auto">${(f.teaching_history || []).map(h =>
          `<li>${esc(h.term)}: <strong>${esc(h.course)}</strong>-${esc(h.section_no)}</li>`).join('') || '<li class="text-muted">None</li>'}</ul>
      </div>
    </div>`;
}
</script>
