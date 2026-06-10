<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Sign in · <?= e($app['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container" style="max-width:420px">
  <div class="card shadow border-0">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <div class="display-6 text-primary"><i class="bi bi-calendar3-week"></i></div>
        <h1 class="h4 mt-2"><?= e($app['name']) ?></h1>
        <p class="text-muted small">AI-powered scheduling &amp; faculty workload management</p>
      </div>
      <?php if (!empty($error)): ?>
      <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="/login">
        <div class="mb-3">
          <label class="form-label">Email</label>
          <input type="email" name="email" class="form-control" required autofocus value="admin@example.edu">
        </div>
        <div class="mb-3">
          <label class="form-label">Password</label>
          <input type="password" name="password" class="form-control" required>
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-box-arrow-in-right me-1"></i>Sign in</button>
      </form>
      <hr>
      <div class="d-grid gap-2">
        <?php if (!empty($samlEnabled)): ?>
        <a class="btn btn-outline-primary btn-sm" href="/auth/saml"><i class="bi bi-building-lock me-1"></i>Sign in with institutional SSO (SAML)</a>
        <?php else: ?>
        <button class="btn btn-outline-secondary btn-sm" disabled><i class="bi bi-building-lock me-1"></i>Institutional SSO (enable SAML in .env)</button>
        <?php endif; ?>
      </div>
      <p class="text-muted small text-center mt-3 mb-0">Demo login: admin@example.edu / Admin@12345</p>
    </div>
  </div>
</div>
</body>
</html>
