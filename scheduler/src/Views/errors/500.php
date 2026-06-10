<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><title>500 — Server error</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet"></head>
<body class="bg-light"><div class="text-center py-5">
  <div class="display-1 text-danger"><i class="bi bi-bug"></i></div>
  <h1 class="h3">500 — Something went wrong</h1>
  <p class="text-muted"><?= e($detail ?? 'Internal server error') ?></p>
  <a href="/" class="btn btn-primary btn-sm">Back to dashboard</a>
</div></body></html>
