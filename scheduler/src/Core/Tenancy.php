<?php

declare(strict_types=1);

namespace App\Core;

/**
 * Tenant context for the current request. The tenant is derived from the
 * authenticated user (never from client input), so a session or API token
 * can only ever operate inside its own institution.
 */
final class Tenancy
{
    private static ?int $override = null;

    /**
     * CLI-only context override (cron jobs have no authenticated user).
     * Never call from web request paths.
     */
    public static function actAs(?int $tenantId): void
    {
        if (PHP_SAPI !== 'cli') {
            throw new \RuntimeException('Tenancy::actAs() is restricted to CLI scripts');
        }
        self::$override = $tenantId;
    }

    /** Current tenant id, or null before authentication. */
    public static function id(): ?int
    {
        if (self::$override !== null) {
            return self::$override;
        }
        $user = Auth::user();

        return $user === null ? null : (int) $user['tenant_id'];
    }

    /** Current tenant id; throws if called on an unauthenticated path. */
    public static function requireId(): int
    {
        $id = self::id();
        if ($id === null) {
            throw new \RuntimeException('No tenant context: route used before authentication');
        }

        return $id;
    }

    /**
     * Assert that a row in $table belongs to the current tenant.
     * Use on every id taken from the URL or request body whose table is
     * tenant-scoped. Throws (→ 404 via handler) on cross-tenant access,
     * so foreign ids are indistinguishable from missing ones.
     */
    public static function assertOwns(string $table, int $id): void
    {
        if (!preg_match('/^[a-z_]+$/', $table)) {
            throw new \InvalidArgumentException('Invalid table name');
        }
        $row = Database::selectOne("SELECT tenant_id FROM `$table` WHERE id = ?", [$id]);
        if ($row === null || (int) $row['tenant_id'] !== self::requireId()) {
            Audit::log('cross_tenant_denied', $table, $id);
            throw new NotFoundForTenantException(ucfirst(str_replace('_', ' ', rtrim($table, 's'))) . ' not found');
        }
    }

    /** Resolve a tenant id from an email domain (SSO just-in-time provisioning). */
    public static function resolveByEmailDomain(string $email): ?int
    {
        $domain = strtolower((string) substr((string) strrchr($email, '@'), 1));
        if ($domain === '') {
            return null;
        }
        $row = Database::selectOne(
            'SELECT id FROM tenants WHERE domain = ? AND is_active = 1',
            [$domain]
        );

        return $row === null ? null : (int) $row['id'];
    }
}

/** Mapped to HTTP 404 so cross-tenant probing leaks nothing. */
final class NotFoundForTenantException extends \RuntimeException
{
}
