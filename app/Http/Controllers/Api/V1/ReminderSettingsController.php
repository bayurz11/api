<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ReminderSettingsController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json([
            'data' => $this->payload(),
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'reservation_reminders_enabled' => ['required', 'boolean'],
            'reservation_reminder_minutes_before' => ['required', 'integer', 'min:15', 'max:10080'],
            'event_reminders_enabled' => ['required', 'boolean'],
            'event_reminder_minutes_before' => ['required', 'integer', 'min:15', 'max:10080'],
            'dashboard_reminder_limit' => ['required', 'integer', 'min:1', 'max:10'],
        ]);

        Setting::setValue(
            'reservation_reminders_enabled',
            $data['reservation_reminders_enabled'] ? '1' : '0',
            'reminders',
        );
        Setting::setValue(
            'reservation_reminder_minutes_before',
            (string) $data['reservation_reminder_minutes_before'],
            'reminders',
        );
        Setting::setValue(
            'event_reminders_enabled',
            $data['event_reminders_enabled'] ? '1' : '0',
            'reminders',
        );
        Setting::setValue(
            'event_reminder_minutes_before',
            (string) $data['event_reminder_minutes_before'],
            'reminders',
        );
        Setting::setValue(
            'dashboard_reminder_limit',
            (string) $data['dashboard_reminder_limit'],
            'reminders',
        );

        return response()->json([
            'message' => 'Pengaturan reminder berhasil disimpan.',
            'data' => $this->payload(),
        ]);
    }

    private function payload(): array
    {
        return [
            'reservation_reminders_enabled' => $this->boolSetting(
                'reservation_reminders_enabled',
                true,
            ),
            'reservation_reminder_minutes_before' => $this->intSetting(
                'reservation_reminder_minutes_before',
                120,
            ),
            'event_reminders_enabled' => $this->boolSetting(
                'event_reminders_enabled',
                true,
            ),
            'event_reminder_minutes_before' => $this->intSetting(
                'event_reminder_minutes_before',
                1440,
            ),
            'dashboard_reminder_limit' => $this->intSetting(
                'dashboard_reminder_limit',
                4,
            ),
        ];
    }

    private function boolSetting(string $key, bool $default): bool
    {
        $value = Setting::getValue($key, $default ? '1' : '0');

        if (is_bool($value)) {
            return $value;
        }

        return in_array((string) $value, ['1', 'true', 'TRUE'], true);
    }

    private function intSetting(string $key, int $default): int
    {
        return (int) Setting::getValue($key, (string) $default);
    }
}
