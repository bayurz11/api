<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Ingredient extends Model
{
    use HasFactory;

    protected $fillable = [
        'code',
        'name',
        'unit',
        'current_stock',
        'minimum_stock',
        'purchase_price',
        'last_purchase_price',
        'notes',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'current_stock' => 'decimal:2',
            'minimum_stock' => 'decimal:2',
            'purchase_price' => 'decimal:2',
            'last_purchase_price' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function menus(): BelongsToMany
    {
        return $this->belongsToMany(Menu::class, 'menu_ingredients')
            ->withPivot('qty_per_portion')
            ->withTimestamps();
    }

    public function linkedMenus()
    {
        return $this->hasMany(Menu::class, 'stock_item_id');
    }

    public function stockMovements(): HasMany
    {
        return $this->hasMany(IngredientStockMovement::class);
    }

    public function shoppingNotes(): HasMany
    {
        return $this->hasMany(ShoppingNote::class);
    }
}
