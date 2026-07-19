<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Reservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'guest_name',
        'guest_phone',
        'table_id',
        'reservation_code',
        'reserved_at',
        'duration_minutes',
        'arrival_grace_minutes',
        'guest_count',
        'deposit_required_amount',
        'status',
        'source',
        'notes',
        'cancellation_policy',
        'cancellation_reason',
        'confirmed_at',
        'arrived_at',
        'seated_at',
        'cancelled_at',
        'no_show_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'reserved_at' => 'datetime',
            'duration_minutes' => 'integer',
            'arrival_grace_minutes' => 'integer',
            'deposit_required_amount' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'arrived_at' => 'datetime',
            'seated_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'no_show_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function tables(): BelongsToMany
    {
        return $this->belongsToMany(Table::class, 'reservation_tables')->withTimestamps();
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }
}
