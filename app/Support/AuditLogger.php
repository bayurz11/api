<?php

namespace App\Support;

use Illuminate\Support\Facades\DB;

class AuditLogger
{
    private const SENSITIVE_KEYS = [
        'password',
        'token',
        'guest_token',
        'authorization',
        'email',
        'phone',
        'customer_phone',
        'customer_name',
        'reference_no',
        'restaurant_address',
        'address',
    ];

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
            'before_data' => $before ? self::encode($before) : null,
            'after_data' => $after ? self::encode($after) : null,
            'reason' => $reason ? self::redactString($reason) : null,
            'logged_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private static function encode(mixed $value): string
    {
        return json_encode(self::redact($value), JSON_UNESCAPED_UNICODE) ?: '{}';
    }

    private static function redact(mixed $value, ?string $key = null): mixed
    {
        if ($key !== null && in_array(mb_strtolower($key), self::SENSITIVE_KEYS, true)) {
            return '[REDACTED]';
        }

        if (is_array($value)) {
            $redacted = [];
            foreach (array_slice($value, 0, 100, true) as $childKey => $childValue) {
                $redacted[$childKey] = self::redact($childValue, is_string($childKey) ? $childKey : null);
            }

            if (count($value) > 100) {
                $redacted['_truncated'] = count($value) - 100;
            }

            return $redacted;
        }

        if (is_object($value)) {
            return self::redact((array) $value);
        }

        return is_string($value) ? self::redactString($value) : $value;
    }

    private static function redactString(string $value): string
    {
        $value = mb_substr($value, 0, 2000);
        $value = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/iu', '[EMAIL]', $value) ?? $value;

        return preg_replace('/(?<!\d)(?:\+62|62|0)8\d{7,12}(?!\d)/', '[PHONE]', $value) ?? $value;
    }
}
