<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
  <h1 class="h3 mb-0">Scheduler — <?= e($term['name']) ?></h1>
  <div class="d-flex gap-2">
    <select id="deptFilter" class="form-select form-select-sm" style="width:auto">
      <option value="">All departments</option>
      <?php foreach ($departments as $d): ?>
      <option value="<?= (int) $d['id'] ?>"><?= e($d['code']) ?> — <?= e($d['name']) ?></option>
      <?php endforeach; ?>
    </select>
    <button class="btn btn-outline-primary btn-sm" id="btnDetect"><i class="bi bi-search me-1"></i>Detect conflicts</button>
    <button class="btn btn-primary btn-sm" id="btnGenerate"><i class="bi bi-magic me-1"></i>Generate schedule (AI)</button>
    <button class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#addSectionModal"><i class="bi bi-plus-lg"></i> Section</button>
  </div>
</div>

<div id="genResult" class="alert alert-info d-none small"></div>

<?php $errors = array_filter($conflicts, fn ($c) => $c['severity'] === 'error'); ?>
<div class="alert <?= $errors === [] ? 'alert-success' : 'alert-danger' ?> py-2 small" id="conflictBanner">
  <i class="bi bi-<?= $errors === [] ? 'check-circle' : 'exclamation-triangle' ?> me-1"></i>
  <?= count($conflicts) ?> open conflict(s), <?= count($errors) ?> error-level.
  <a href="#conflictList" class="alert-link" data-bs-toggle="collapse">Details</a>
</div>
<div class="collapse mb-3" id="conflictList">
  <div class="card card-body p-2">
    <?php if ($conflicts === []): ?><span class="text-muted small">No conflicts.</span><?php endif; ?>
    <?php foreach ($conflicts as $c): ?>
    <div class="small border-bottom py-1">
      <span class="badge text-bg-<?= $c['severity'] === 'error' ? 'danger' : 'warning' ?>"><?= e($c['type']) ?></span>
      <?= e($c['description']) ?>
      <?php if ($c['suggestion']): ?><span class="text-primary">→ <?= e($c['suggestion']) ?></span><?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
</div>

<ul class="nav nav-tabs mb-3">
  <li class="nav-item"><a class="nav-link active" data-bs-toggle="tab" href="#tabWeek">Weekly board</a></li>
  <li class="nav-item"><a class="nav-link" data-bs-toggle="tab" href="#tabList">Section list</a></li>
</ul>

<div class="tab-content">
  <div class="tab-pane fade show active" id="tabWeek">
    <p class="text-muted small mb-2"><i class="bi bi-info-circle me-1"></i>Drag a class card onto another day/time slot to reschedule it. Conflicts re-validate automatically.</p>
    <div class="table-responsive">
      <table class="table table-bordered schedule-grid" id="scheduleGrid">
        <thead><tr><th style="width:70px">Time</th>
          <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $d): ?><th><?= $d ?></th><?php endforeach; ?>
        </tr></thead>
        <tbody>
          <?php for ($h = 8; $h <= 19; $h++): ?>
          <tr>
            <th class="small text-muted"><?= sprintf('%02d:00', $h) ?></th>
            <?php foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri'] as $d): ?>
            <td class="slot" data-day="<?= $d ?>" data-hour="<?= $h ?>"></td>
            <?php endforeach; ?>
          </tr>
          <?php endfor; ?>
        </tbody>
      </table>
    </div>
  </div>

  <div class="tab-pane fade" id="tabList">
    <div class="table-responsive">
      <table class="table table-sm table-hover align-middle" id="sectionTable">
        <thead><tr><th>Course</th><th>Sec</th><th>Title</th><th>Instructor</th><th>Meetings</th><th>Room</th><th>Cap</th><th>Status</th><th></th></tr></thead>
        <tbody></tbody>
      </table>
    </div>
  </div>
</div>

<!-- Add section modal -->
<div class="modal fade" id="addSectionModal" tabindex="-1">
  <div class="modal-dialog">
    <div class="modal-content">
      <div class="modal-header"><h5 class="modal-title">Add section</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <div class="mb-2"><label class="form-label small">Course</label>
          <select class="form-select form-select-sm" id="newCourse"></select></div>
        <div class="mb-2"><label class="form-label small">Section #</label>
          <input class="form-control form-control-sm" id="newSectionNo" value="01"></div>
        <div class="mb-2"><label class="form-label small">Meeting pattern</label>
          <select class="form-select form-select-sm" id="newPattern">
            <?php foreach ($patterns as $p): ?>
            <option value='<?= e(json_encode(['days' => explode(',', $p['days']), 'start' => $p['start_time'], 'end' => $p['end_time']])) ?>'>
              <?= e($p['code']) ?> (<?= e($p['days']) ?> <?= e(substr($p['start_time'], 0, 5)) ?>–<?= e(substr($p['end_time'], 0, 5)) ?>)
            </option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label small">Room</label>
          <select class="form-select form-select-sm" id="newRoom">
            <option value="">— TBA —</option>
            <?php foreach ($rooms as $room): ?>
            <option value="<?= (int) $room['id'] ?>"><?= e($room['code']) ?> (<?= (int) $room['capacity'] ?> seats, <?= e($room['type']) ?>)</option>
            <?php endforeach; ?>
          </select></div>
        <div class="mb-2"><label class="form-label small">Capacity</label>
          <input type="number" class="form-control form-control-sm" id="newCapacity" value="30"></div>
      </div>
      <div class="modal-footer">
        <button class="btn btn-primary btn-sm" id="btnCreateSection">Create</button>
      </div>
    </div>
  </div>
</div>

<script src="/assets/scheduler.js"></script>
