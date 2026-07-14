<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Menu extends Model
{
    use HasFactory;

    protected $fillable = [
        'category_id',
        'stock_item_id',
        'stock_deduction_qty',
        'sku',
        'name',
        'description',
        'image_url',
        'price',
        'station_type',
        'is_available',
        'is_stock_available',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_deduction_qty' => 'decimal:2',
            'is_available' => 'boolean',
            'is_stock_available' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(MenuCategory::class, 'category_id');
    }

    public function stockItem(): BelongsTo
    {
        return $this->belongsTo(Ingredient::class, 'stock_item_id');
    }

    public function recipeIngredients(): BelongsToMany
    {
        return $this->belongsToMany(Ingredient::class, 'menu_ingredients')
            ->withPivot('qty_per_portion')
            ->withTimestamps();
    }
}
