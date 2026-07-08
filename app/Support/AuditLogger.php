<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AuditLogger
{
    public static function log(
        ?int $userId,
        ?string $roleName,
        string $action,
        string $entityType,
        ?int $entityId,
        mixed $before = null,
        mixed $after = null,
        ?string $reason = null,
    ): void {
        DB::table('audit_logs')->insert([
            'user_id' => $userId,
            'role_name' => $roleName,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'before_data' => $before ? json_encode($before, JSON_UNESCAPED_UNICODE) : null,
            'after_data' => $after ? json_encode($after, JSON_UNESCAPED_UNICODE) : null,
            'reason' => $reason,
            'logged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
