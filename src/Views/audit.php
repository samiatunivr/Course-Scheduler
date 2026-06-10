<?php use function App\Core\e; ?>
<div class="d-flex justify-content-between align-items-center mb-4">
  <h1 class="h3 mb-0">Audit Trail</h1>
  <form class="d-flex gap-2" method="get">
    <input type="hidden" name="term_id" value="<?= (int) $term['id'] ?>">
    <select name="action" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All actions</option>
      <?php foreach ($actions as $a): ?>
      <option value="<?= e($a['action']) ?>"<?= ($_GET['action'] ?? '') === $a['action'] ? ' selected' : '' ?>><?= e($a['action']) ?></option>
      <?php endforeach; ?>
    </select>
    <select name="entity_type" class="form-select form-select-sm" onchange="this.form.submit()">
      <option value="">All entities</option>
      <?php foreach ($entityTypes as $t): ?>
      <option value="<?= e($t['entity_type']) ?>"<?= ($_GET['entity_type'] ?? '') === $t['entity_type'] ? ' selected' : '' ?>><?= e($t['entity_type']) ?></option>
      <?php endforeach; ?>
    </select>
  </form>
</div>

<p class="text-muted small">Every state-changing action in this institution is recorded: logins (incl. failures and MFA), schedule edits, AI generation runs, approvals, exports, imports and cross-tenant access denials. Showing the latest 200 entries for the current institution.</p>

<div class="card border-0 shadow-sm">
  <div class="card-body table-responsive p-0">
    <table class="table table-sm table-hover align-middle mb-0">
      <thead><tr>
        <th>#</th><th>When</th><th>Actor</th><th>Action</th><th>Entity</th><th>Changes</th><th>IP</th>
      </tr></thead>
      <tbody>
      <?php if ($logs === []): ?>
      <tr><td colspan="7" class="text-muted text-center py-4">No audit entries match the filter.</td></tr>
      <?php endif; ?>
      <?php foreach ($logs as $log): ?>
      <tr>
        <td class="text-muted small"><?= (int) $log['id'] ?></td>
        <td class="small text-nowrap"><?= e($log['created_at']) ?></td>
        <td class="small"><?= e($log['actor'] ?? 'system') ?></td>
        <td>
          <?php $color = match (true) {
              str_contains((string) $log['action'], 'fail') || str_contains((string) $log['action'], 'denied') => 'danger',
              in_array($log['action'], ['delete', 'cancel', 'rollback', 'import_rollback', 'workflow_reject'], true) => 'warning',
              default => 'light border',
          }; ?>
          <span class="badge text-bg-<?= $color ?>"><?= e($log['action']) ?></span>
        </td>
        <td class="small"><?= e($log['entity_type']) ?><?= $log['entity_id'] !== null ? ' #' . (int) $log['entity_id'] : '' ?></td>
        <td class="small text-muted" style="max-width:420px">
          <?php
          $changes = [];
          foreach (['old_values' => 'was', 'new_values' => 'now'] as $col => $label) {
              if (!empty($log[$col])) {
                  $decoded = json_decode((string) $log[$col], true);
                  if (is_array($decoded) && $decoded !== []) {
                      $changes[] = $label . ': ' . mb_substr(json_encode($decoded, JSON_UNESCAPED_UNICODE), 0, 180);
                  }
              }
          }
          echo e(implode(' · ', $changes)) ?: '—';
          ?>
        </td>
        <td class="small text-muted"><?= e($log['ip_address'] ?? '') ?></td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
