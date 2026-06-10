<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>403 — Access denied</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="text-center py-5">
  <div class="display-1 text-warning"><i class="bi bi-shield-lock"></i></div>
  <h1 class="h3">403 — Access denied</h1>
  <p class="text-muted">Your role does not include the required permission<?= isset($permission) ? ' (<code>' . e($permission) . '</code>)' : '' ?>.</p>
  <a href="/" class="btn btn-primary btn-sm">Back to dashboard</a>
</div></body></html>
