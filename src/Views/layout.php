<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'Dashboard') ?> · <?= e($app['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
</head>
<body>
<nav class="navbar navbar-expand-lg navbar-dark bg-primary-dark sticky-top">
  <div class="container-fluid">
    <a class="navbar-brand fw-bold" href="/"><i class="bi bi-calendar3-week me-2"></i><?= e($app['name']) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <?php
        $items = [
            ['/', 'dashboard', 'speedometer2', 'Dashboard'],
            ['/scheduler', 'scheduler', 'calendar-week', 'Scheduler'],
            ['/faculty', 'faculty', 'people', 'Faculty'],
            ['/workload', 'workload', 'bar-chart-steps', 'Workload'],
            ['/rooms', 'rooms', 'building', 'Rooms'],
            ['/scenarios', 'scenarios', 'diagram-3', 'Scenarios'],
            ['/workflow', 'workflow', 'check2-square', 'Approvals'],
            ['/reports', 'reports', 'file-earmark-bar-graph', 'Reports'],
            ['/imports', 'imports', 'upload', 'Import'],
            ['/assistant', 'assistant', 'stars', 'AI Assistant'],
        ];
        foreach ($items as [$href, $key, $icon, $label]): ?>
        <li class="nav-item">
          <a class="nav-link<?= ($active ?? '') === $key ? ' active fw-semibold' : '' ?>" href="<?= e($href) ?>?term_id=<?= (int) $term['id'] ?>">
            <i class="bi bi-<?= e($icon) ?> me-1"></i><?= e($label) ?>
          </a>
        </li>
        <?php endforeach; ?>
      </ul>
      <form class="d-flex me-3" method="get">
        <select class="form-select form-select-sm" name="term_id" onchange="this.form.submit()">
          <?php foreach ($terms as $t): ?>
          <option value="<?= (int) $t['id'] ?>"<?= (int) $t['id'] === (int) $term['id'] ? ' selected' : '' ?>>
            <?= e($t['name']) ?> (<?= e($t['status']) ?>)
          </option>
          <?php endforeach; ?>
        </select>
      </form>
      <span class="navbar-text text-light me-2 small"><i class="bi bi-person-circle me-1"></i><?= e($user['name'] ?? '') ?></span>
      <a class="btn btn-outline-light btn-sm me-2" href="/security" title="Account security (MFA)"><i class="bi bi-shield-lock"></i></a>
      <a class="btn btn-outline-light btn-sm" href="/logout" title="Sign out"><i class="bi bi-box-arrow-right"></i></a>
    </div>
  </div>
</nav>

<main class="container-fluid py-4 px-4">
<?= $content ?>
</main>

<script>window.APP = { termId: <?= (int) $term['id'] ?> };</script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.3/dist/chart.umd.min.js"></script>
<script src="/assets/app.js"></script>
</body>
</html>
