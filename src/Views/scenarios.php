<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Scenario Planning &amp; Simulation — <?= e($term['name']) ?></h1>

<div class="row g-3">
  <div class="col-lg-5">
    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-sliders me-1"></i>New simulation</div>
      <div class="card-body">
        <div class="mb-2">
          <label class="form-label small">Scenario name</label>
          <input class="form-control form-control-sm" id="scName" placeholder="e.g. Enrollment +20%, Dr. Nguyen on leave">
        </div>
        <div class="mb-2">
          <label class="form-label small">Enrollment change (%)</label>
          <input type="number" class="form-control form-control-sm" id="scDelta" value="0" step="5">
          <div class="form-text">e.g. 20 simulates a 20% enrollment increase</div>
        </div>
        <div class="mb-2">
          <label class="form-label small">Faculty unavailable (resignation / leave)</label>
          <select multiple class="form-select form-select-sm" id="scFaculty" size="5">
            <?php foreach ($facultyList as $f): ?>
            <option value="<?= (int) $f['id'] ?>"><?= e($f['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="mb-3">
          <label class="form-label small">Rooms closed</label>
          <select multiple class="form-select form-select-sm" id="scRooms" size="4">
            <?php foreach ($roomList as $room): ?>
            <option value="<?= (int) $room['id'] ?>"><?= e($room['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <button class="btn btn-primary btn-sm" id="btnSimulate"><i class="bi bi-play-circle me-1"></i>Run simulation</button>
      </div>
    </div>
  </div>

  <div class="col-lg-7">
    <div class="card border-0 shadow-sm mb-3">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-graph-up me-1"></i>Result</div>
      <div class="card-body" id="scResult">
        <p class="text-muted small mb-0">Run a simulation to compare the generated alternative schedule against the current baseline. Simulations never modify live data until you click “Apply”.</p>
      </div>
    </div>

    <div class="card border-0 shadow-sm">
      <div class="card-header bg-white fw-semibold">Past scenarios</div>
      <div class="card-body p-0">
        <table class="table table-sm small mb-0">
          <thead><tr><th>#</th><th>Name</th><th>Parameters</th><th>Status</th><th>Created</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($scenarios as $sc): ?>
          <tr>
            <td><?= (int) $sc['id'] ?></td>
            <td class="fw-semibold"><?= e($sc['name']) ?></td>
            <td><?= e($sc['description']) ?></td>
            <td><span class="badge text-bg-light border"><?= e($sc['status']) ?></span></td>
            <td><?= e($sc['created_at']) ?></td>
            <td>
              <?php if ($sc['status'] === 'simulated'): ?>
              <button class="btn btn-sm btn-outline-success py-0"
                      onclick="if(confirm('Apply this scenario? Draft sections for the term will be regenerated.')) api('POST','/api/v1/scenarios/<?= (int) $sc['id'] ?>/apply').then(()=>location.reload())">
                Apply
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
</div>

<script>
document.getElementById('btnSimulate').addEventListener('click', async () => {
  const selected = el => [...el.selectedOptions].map(o => parseInt(o.value));
  const body = {
    name: document.getElementById('scName').value || 'Untitled scenario',
    parameters: {
      enrollment_delta_pct: parseFloat(document.getElementById('scDelta').value) || 0,
      exclude_faculty: selected(document.getElementById('scFaculty')),
      exclude_rooms: selected(document.getElementById('scRooms')),
    }
  };
  document.getElementById('scResult').innerHTML = '<div class="spinner-border spinner-border-sm"></div> Simulating…';
  const r = await api('POST', `/api/v1/terms/${APP.termId}/scenarios`, body);
  const esc = s => String(s ?? '').replace(/[&<>"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[c]));
  document.getElementById('scResult').innerHTML = `
    <div class="alert ${r.feasible ? 'alert-success' : 'alert-warning'} py-2 small">
      ${r.feasible ? '✅ Feasible: all sections can be scheduled under this scenario.'
                   : `⚠️ Not fully feasible: ${r.simulated.unassigned_count} section(s) cannot be placed.`}
    </div>
    <div class="row small">
      <div class="col-6"><strong>Baseline sections:</strong> ${r.baseline.total_sections}</div>
      <div class="col-6"><strong>Simulated sections:</strong> ${r.simulated.sections_created}</div>
    </div>
    ${r.simulated.unassigned.length ? '<h6 class="small fw-bold mt-2">Unplaceable</h6><ul class="small text-danger">' +
      r.simulated.unassigned.map(u => `<li>${esc(u.course)}-${esc(u.section_no)}: ${esc(u.reason)}</li>`).join('') + '</ul>' : ''}
    <details class="small mt-2"><summary>Generated sections (${r.sections.length})</summary>
      <ul>${r.sections.map(s => `<li>${esc(s.course)}-${esc(s.section_no)}: ${esc(s.faculty)} · ${esc(s.days.join(''))} ${esc(s.start_time).slice(0,5)} · ${esc(s.room)}</li>`).join('')}</ul>
    </details>
    <p class="small text-muted mt-2 mb-0">Saved as scenario #${r.scenario_id} — refresh to see it in the list, or apply it.</p>`;
});
</script>
