<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MenuOption extends Model
{
    use HasFactory;

    protected $fillable = [
        'menu_id',
        'stock_item_id',
        'stock_deduction_qty',
        'name',
        'price_delta',
        'is_available',
        'is_stock_available',
        'is_active',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price_delta' => 'decimal:2',
            'stock_deduction_qty' => 'decimal:2',
            'is_available' => 'boolean',
            'is_stock_available' => 'boolean',
            'is_active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function menu(): BelongsTo
    {
        return $this->belongsTo(Menu::class);
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'stock_item_id');
    }
}
