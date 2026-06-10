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

    public static function attempt(string $email, string $password): bool
    {
        $user = Database::selectOne(
            'SELECT * FROM users WHERE email = ? AND is_active = 1 AND sso_provider = "local"',
            [$email]
        );
        if ($user === null || !password_verify($password, (string) $user['password_hash'])) {
            return false;
        }

        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        Database::update('users', (int) $user['id'], ['last_login_at' => date('Y-m-d H:i:s')]);
        Audit::log('login', 'user', (int) $user['id']);

        return true;
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
