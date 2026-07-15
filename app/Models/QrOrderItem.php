<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class QrOrderItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'qr_order_id',
        'menu_id',
        'menu_option_id',
        'menu_name',
        'station_type',
        'qty',
        'unit_price',
        'line_total',
        'notes',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'unit_price' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function qrOrder(): BelongsTo
    {
        return $this->belongsTo(QrOrder::class);
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function menuOption(): BelongsTo
    {
        return $this->belongsTo(MenuOption::class);
    }
}
