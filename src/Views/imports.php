<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Bulk Import</h1>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-upload me-1"></i>Upload CSV / Excel-exported CSV</div>
      <div class="card-body">
        <div class="mb-3">
          <label class="form-label small">Data type</label>
          <select class="form-select form-select-sm" id="impEntity">
            <option value="faculty">Faculty records (first_name, last_name, email, department_code, rank, contract_type, max_credit_hours)</option>
            <option value="courses">Course catalog (code, title, department_code, credit_hours, contact_hours, capacity)</option>
            <option value="rooms">Room inventory (code, name, type, capacity, equipment "a|b|c")</option>
            <option value="enrollment">Enrollment history (course_code, term_code, enrolled, waitlisted, sections_offered)</option>
            <option value="availability">Faculty availability (faculty_email, day, start_time, end_time, preference)</option>
          </select>
        </div>
        <div class="mb-3">
          <input type="file" class="form-control form-control-sm" id="impFile" accept=".csv,.txt">
        </div>
        <button class="btn btn-primary btn-sm" id="btnValidate"><i class="bi bi-check2-circle me-1"></i>Validate &amp; preview</button>
      </div>
    </div>

    <div class="card border-0 shadow-sm mt-3">
      <div class="card-header bg-white fw-semibold">Recent batches</div>
      <div class="card-body p-0">
        <table class="table table-sm mb-0 small">
          <thead><tr><th>#</th><th>Type</th><th>File</th><th>Rows</th><th>Errors</th><th>Status</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($batches as $b): ?>
          <tr>
            <td><?= (int) $b['id'] ?></td>
            <td><?= e($b['entity_type']) ?></td>
            <td><?= e($b['file_name']) ?></td>
            <td><?= (int) $b['valid_rows'] ?>/<?= (int) $b['total_rows'] ?></td>
            <td class="<?= $b['error_rows'] > 0 ? 'text-danger' : '' ?>"><?= (int) $b['error_rows'] ?></td>
            <td><span class="badge text-bg-light border"><?= e($b['status']) ?></span></td>
            <td>
              <?php if ($b['status'] === 'committed'): ?>
              <button class="btn btn-sm btn-outline-danger py-0"
                      onclick="if(confirm('Roll back batch #<?= (int) $b['id'] ?>? All rows it created will be deleted.')) api('POST','/api/v1/import/batches/<?= (int) $b['id'] ?>/rollback').then(()=>location.reload())">
                Rollback
              </button>
              <?php endif; ?>
            </td>
          </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-eye me-1"></i>Validation result</div>
      <div class="card-body" id="impResult">
        <p class="text-muted small mb-0">Upload a file to see the validation preview, error report and commit option.</p>
      </div>
    </div>
  </div>
</div>

<script>
document.getElementById('btnValidate').addEventListener('click', async () => {
  const file = document.getElementById('impFile').files[0];
  if (!file) return alert('Choose a file first.');
  const entity = document.getElementById('impEntity').value;
  const fd = new FormData();
  fd.append('file', file);
  const res = await fetch(`/api/v1/import/${entity}/validate`, {method: 'POST', body: fd});
  const data = await res.json();
  if (!res.ok) return alert(data.error || 'Validation failed');
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  let html = `<div class="alert ${data.invalid ? 'alert-warning' : 'alert-success'} py-2 small">
      ${data.valid} valid / ${data.invalid} invalid of ${data.total} rows (batch #${data.batch_id})</div>`;
  if (data.errors.length) {
    html += '<h6 class="small fw-bold">Errors</h6><ul class="small text-danger">' +
      data.errors.slice(0, 20).map(e => `<li>Row ${e.row}: ${esc(e.errors.join('; '))}</li>`).join('') + '</ul>';
  }
  if (data.preview.length) {
    const cols = Object.keys(data.preview[0]);
    html += '<h6 class="small fw-bold">Preview (first 10 valid rows)</h6><div class="table-responsive"><table class="table table-sm small"><thead><tr>' +
      cols.map(c => `<th>${esc(c)}</th>`).join('') + '</tr></thead><tbody>' +
      data.preview.map(r => '<tr>' + cols.map(c => `<td>${esc(r[c])}</td>`).join('') + '</tr>').join('') +
      '</tbody></table></div>';
  }
  if (data.valid > 0) {
    html += `<button class="btn btn-success btn-sm" onclick="api('POST','/api/v1/import/batches/${data.batch_id}/commit').then(r=>{alert('Imported '+r.created+' rows'); location.reload()})">
      <i class="bi bi-database-add me-1"></i>Commit ${data.valid} rows</button>`;
  }
  document.getElementById('impResult').innerHTML = html;
});
</script>
