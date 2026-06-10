<?php use function App\Core\e; ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Two-factor verification · <?= e($app['name']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.min.css" rel="stylesheet">
<link href="/assets/app.css" rel="stylesheet">
</head>
<body class="bg-light d-flex align-items-center" style="min-height:100vh">
<div class="container" style="max-width:420px">
  <div class="card shadow border-0">
    <div class="card-body p-4">
      <div class="text-center mb-4">
        <div class="display-6 text-primary"><i class="bi bi-shield-lock"></i></div>
        <h1 class="h4 mt-2">Two-factor verification</h1>
        <p class="text-muted small">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>
      </div>
      <?php if (!empty($error)): ?>
      <div class="alert alert-danger py-2 small"><?= e($error) ?></div>
      <?php endif; ?>
      <form method="post" action="/mfa">
        <div class="mb-3">
          <input name="code" class="form-control form-control-lg text-center" placeholder="000000"
                 autofocus autocomplete="one-time-code" inputmode="numeric" maxlength="11" required>
        </div>
        <button class="btn btn-primary w-100" type="submit"><i class="bi bi-check2-circle me-1"></i>Verify</button>
      </form>
      <p class="text-center small mt-3 mb-0"><a href="/logout">Cancel and sign out</a></p>
    </div>
  </div>
</div>
</body>
</html>
