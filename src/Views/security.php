<?php use function App\Core\e; ?>
<h1 class="h3 mb-4">Account Security</h1>

<?php if (!empty($flash)): ?>
<div class="alert alert-<?= e($flash['type']) ?> py-2 small"><?= e($flash['text']) ?></div>
<?php endif; ?>

<?php if (!empty($newRecoveryCodes)): ?>
<div class="card border-success shadow-sm mb-4">
  <div class="card-header bg-success text-white fw-semibold"><i class="bi bi-key me-1"></i>Recovery codes — shown once, save them now</div>
  <div class="card-body">
    <p class="small text-muted mb-2">Each code signs you in once if you lose access to your authenticator. Store them in a password manager.</p>
    <div class="row font-monospace">
      <?php foreach ($newRecoveryCodes as $code): ?>
      <div class="col-6 col-md-3 mb-1"><?= e($code) ?></div>
      <?php endforeach; ?>
    </div>
  </div>
</div>
<?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-shield-lock me-1"></i>Two-factor authentication (TOTP)</div>
      <div class="card-body">
        <?php if ($mfaEnabled): ?>
        <p><span class="badge text-bg-success"><i class="bi bi-check2 me-1"></i>Enabled</span>
           <span class="small text-muted ms-2"><?= (int) $recoveryCodesLeft ?> recovery code(s) remaining</span></p>
        <p class="small text-muted">Disabling two-factor authentication requires your password.</p>
        <form method="post" action="/security/mfa/disable" class="d-flex gap-2"
              onsubmit="return confirm('Disable two-factor authentication?')">
          <input type="password" name="password" class="form-control form-control-sm" placeholder="Current password" required>
          <button class="btn btn-outline-danger btn-sm text-nowrap"><i class="bi bi-shield-x me-1"></i>Disable</button>
        </form>
        <?php else: ?>
        <p><span class="badge text-bg-secondary">Disabled</span></p>
        <ol class="small">
          <li class="mb-2">Scan this QR code with Google Authenticator, Microsoft Authenticator, Authy or any TOTP app:
            <div class="my-2 p-2 bg-white border rounded d-inline-block"><canvas id="qr"></canvas></div>
            <div class="text-muted">Or enter the secret manually: <code><?= e($pendingSecret) ?></code></div>
          </li>
          <li>Enter the current 6-digit code to confirm and activate:</li>
        </ol>
        <form method="post" action="/security/mfa/enable" class="d-flex gap-2" style="max-width:320px">
          <input name="code" class="form-control" placeholder="000000" autocomplete="one-time-code"
                 inputmode="numeric" maxlength="6" required>
          <button class="btn btn-primary text-nowrap"><i class="bi bi-shield-check me-1"></i>Enable</button>
        </form>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card border-0 shadow-sm h-100">
      <div class="card-header bg-white fw-semibold"><i class="bi bi-box-arrow-in-right me-1"></i>Single sign-on</div>
      <div class="card-body small">
        <p>SAML 2.0 single sign-on is configured institution-wide by an administrator via <code>.env</code>
           (<code>SSO_SAML_ENABLED</code>, IdP entity ID / SSO URL / signing certificate).</p>
        <p class="mb-1">Service-provider endpoints to register at your IdP:</p>
        <ul class="mb-0">
          <li>Metadata / Entity ID: <code>/auth/saml/metadata</code></li>
          <li>ACS (HTTP-POST): <code>/auth/saml/acs</code></li>
          <li>Login initiation: <code>/auth/saml</code></li>
        </ul>
      </div>
    </div>
  </div>
</div>

<?php if (!$mfaEnabled && $pendingUri !== null): ?>
<script src="https://cdn.jsdelivr.net/npm/qrcode@1.5.3/build/qrcode.min.js"></script>
<script>
QRCode.toCanvas(document.getElementById('qr'), <?= json_encode($pendingUri) ?>, { width: 180, margin: 1 });
</script>
<?php endif; ?>
