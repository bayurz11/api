<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ShoppingNote extends Model
{
    use HasFactory;

    protected $fillable = [
        'ingredient_id',
        'item_name',
        'item_unit',
        'requested_qty',
        'current_stock',
        'minimum_stock',
        'estimated_unit_price',
        'status',
        'source',
        'notes',
        'created_by',
        'updated_by',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'requested_qty' => 'decimal:2',
            'current_stock' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
            'estimated_unit_price' => 'decimal:2',
            'completed_at' => 'datetime',
        ];
    }

    public function ingredient(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class);
    }
}
