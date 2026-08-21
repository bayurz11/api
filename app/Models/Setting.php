<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBranch;
use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    use BelongsToBranch;

    protected $fillable = [
        'key',
        'value',
        'group',
    ];

    public static function getValue(string $key, mixed $default = null): mixed
    {
        return static::query()->where('key', $key)->value('value') ?? $default;
    }

    public static function setValue(string $key, mixed $value, string $group = 'general'): void
    {
        static::query()->updateOrCreate(
            ['key' => $key],
            [
                'value' => $value,
                'group' => $group,
            ],
        );
    }
}
