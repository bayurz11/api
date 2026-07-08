<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class QrOrder extends Model
{
    use HasFactory;

    protected $fillable = [
        'order_no',
        'guest_token',
        'table_id',
        'linked_bill_id',
        'approved_order_id',
        'approved_by',
        'customer_name',
        'customer_phone',
        'guest_count',
        'notes',
        'status',
        'subtotal',
        'grand_total',
        'submitted_at',
        'approved_at',
        'rejected_at',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'grand_total' => 'decimal:2',
            'submitted_at' => 'datetime',
            'approved_at' => 'datetime',
            'rejected_at' => 'datetime',
        ];
    }

    public function table(): BelongsTo
    {
        return $this->belongsTo(Table::class);
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class, 'linked_bill_id');
    }

    public function approvedOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'approved_order_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(QrOrderItem::class);
    }
}
