<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Session + API-token authentication with RBAC permission checks.
 * Permissions are loaded once per request and cached.
 */
final class Auth
{
    private static ?array $user = null;
    private static ?array $permissions = null;

    /**
     * Password check. Returns 'ok' (logged in), 'mfa' (password correct,
     * TOTP challenge pending — complete with verifyMfa()), or 'fail'.
     */
    public static function attempt(string $email, string $password): string
    {
        $user = Database::selectOne(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 AND sso_provider = "local"',
            [$email]
        );
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return 'fail';
        }

        if ((int) $user['mfa_enabled'] === 1) {
            $_SESSION['mfa_pending_user'] = (int) $user['id'];

            return 'mfa';
        }

        self::finalizeLogin((int) $user['id'], 'password');

        return 'ok';
    }

    /** Complete a pending MFA challenge with a TOTP code or a recovery code. */
    public static function verifyMfa(string $code): bool
    {
        $userId = $_SESSION['mfa_pending_user'] ?? null;
        if ($userId === null) {
            return false;
        }
        $user = Database::selectOne('SELECT * FROM users WHERE id = ? AND is_active = 1', [(int) $userId]);
        if ($user === null || (int) $user['mfa_enabled'] !== 1 || empty($user['mfa_secret'])) {
            return false;
        }

        $totp = new \App\Services\TotpService();
        $usedCounter = null;
        $lastCounter = $user['mfa_last_counter'] !== null ? (int) $user['mfa_last_counter'] : null;

        if ($totp->verify((string) $user['mfa_secret'], $code, 1, $lastCounter, $usedCounter)) {
            Database::update('users', (int) $userId, ['mfa_last_counter' => $usedCounter]);
        } else {
            // Fall back to one-time recovery codes.
            $hashes = json_decode((string) ($user['mfa_recovery_codes'] ?? '[]'), true) ?: [];
            $index = $totp->matchRecoveryCode($code, $hashes);
            if ($index === null) {
                Audit::log('mfa_failed', 'user', (int) $userId);

                return false;
            }
            unset($hashes[$index]);
            Database::update('users', (int) $userId, [
                'mfa_recovery_codes' => json_encode(array_values($hashes)),
            ]);
            Audit::log('mfa_recovery_code_used', 'user', (int) $userId);
        }

        unset($_SESSION['mfa_pending_user']);
        self::finalizeLogin((int) $userId, 'password+mfa');

        return true;
    }

    /** Finalize a login for an externally authenticated identity (SAML/OAuth). */
    public static function loginAs(int $userId, string $provider): void
    {
        self::finalizeLogin($userId, $provider);
    }

    private static function finalizeLogin(int $userId, string $method): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id'] = $userId;
        self::$user = null;
        self::$permissions = null;
        Database::update('users', $userId, ['last_login_at' => date('Y-m-d H:i:s')]);
        Audit::log('login', 'user', $userId, null, ['method' => $method]);
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        self::$user = null;
        self::$permissions = null;
    }

    /** Resolve the current user from session or Bearer token. */
    public static function user(): ?array
    {
        if (self::$user !== null) {
            return self::$user;
        }

        $userId = $_SESSION['user_id'] ?? null;

        if ($userId === null) {
            $token = (new Request())->bearerToken();
            if ($token !== null) {
                $row = Database::selectOne(
                    'SELECT user_id FROM api_tokens
                     WHERE token_hash = ? AND (expires_at IS NULL OR expires_at > NOW())',
                    [hash('sha256', $token)]
                );
                $userId = $row['user_id'] ?? null;
            }
        }

        if ($userId === null) {
            return null;
        }

        self::$user = Database::selectOne(
            'SELECT id, email, name, is_active FROM users WHERE id = ? AND is_active = 1',
            [(int) $userId]
        );

        return self::$user;
    }

    public static function id(): ?int
    {
        $user = self::user();

        return $user === null ? null : (int) $user['id'];
    }

    /** @return string[] permission codes for the current user */
    public static function permissions(): array
    {
        if (self::$permissions !== null) {
            return self::$permissions;
        }
        $id = self::id();
        if ($id === null) {
            return self::$permissions = [];
        }
        $rows = Database::select(
            'SELECT DISTINCT p.code FROM permissions p
             JOIN role_permissions rp ON rp.permission_id = p.id
             JOIN user_roles ur ON ur.role_id = rp.role_id
             WHERE ur.user_id = ?',
            [$id]
        );

        return self::$permissions = array_column($rows, 'code');
    }

    public static function can(string $permission): bool
    {
        return in_array($permission, self::permissions(), true);
    }

    /** Department ids the user's roles are scoped to (empty = all departments). */
    public static function departmentScope(): array
    {
        $id = self::id();
        if ($id === null) {
            return [];
        }
        $rows = Database::select(
            'SELECT DISTINCT department_id FROM user_roles WHERE user_id = ?',
            [$id]
        );
        $ids = array_filter(array_column($rows, 'department_id'), fn ($d) => $d !== null);

        return array_map('intval', $ids);
    }

    public static function issueApiToken(int $userId, string $name, ?string $expiresAt = null): string
    {
        $token = bin2hex(random_bytes(32));
        Database::insert('api_tokens', [
            'user_id' => $userId,
            'name' => $name,
            'token_hash' => hash('sha256', $token),
            'expires_at' => $expiresAt,
        ]);

        return $token;
    }
}
