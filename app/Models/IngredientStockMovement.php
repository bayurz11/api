<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IngredientStockMovement extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_id',
        'movement_type',
        'qty_delta',
        'stock_before',
        'stock_after',
        'unit_cost',
        'total_cost',
        'reason',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'qty_delta' => 'decimal:2',
            'stock_before' => 'decimal:2',
            'stock_after' => 'decimal:2',
            'unit_cost' => 'decimal:2',
            'total_cost' => 'decimal:2',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
