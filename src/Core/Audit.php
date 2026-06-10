<?php

declare(strict_types=1);

namespace App\Core;

/** Immutable audit trail for every state-changing action. */
final class Audit
{
    public static function log(
        string $action,
        string $entityType,
        ?int $entityId = null,
        ?array $oldValues = null,
        ?array $newValues = null
    ): void {
        try {
            Database::insert('audit_logs', [
                'tenant_id' => Tenancy::id(),
                'user_id' => Auth::id(),
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues === null ? null : json_encode($oldValues),
                'new_values' => $newValues === null ? null : json_encode($newValues),
                'ip_address' => $_SERVER['REMOTE_ADDR'] ?? null,
                'user_agent' => substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]);
        } catch (\Throwable) {
            // Auditing must never break the main flow; failures are logged to PHP error log.
            error_log('Audit log write failed for ' . $action . ' ' . $entityType);
        }
    }
}
