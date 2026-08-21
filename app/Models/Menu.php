<?php

namespace App\Models;

use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

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
        $relation = $this->belongsToMany(Ingredient::class, 'menu_ingredients')
            ->withPivot('qty_per_portion')
            ->withTimestamps();

        $branchId = app(BranchContext::class)->id();

        return $branchId ? $relation->wherePivot('branch_id', $branchId) : $relation;
    }

    public function options(): HasMany
    {
        return $this->hasMany(MenuOption::class)->orderBy('sort_order')->orderBy('id');
    }

    public function branchSettings(): HasMany
    {
        return $this->hasMany(BranchMenu::class);
    }

    public function scopeForBranch(Builder $query, int $branchId): Builder
    {
        return $query
            ->join('branch_menus as active_branch_menu', function ($join) use ($branchId): void {
                $join->on('active_branch_menu.menu_id', '=', 'menus.id')
                    ->where('active_branch_menu.branch_id', $branchId)
                    ->where('active_branch_menu.is_active', true);
            })
            ->select('menus.*')
            ->selectRaw('COALESCE(active_branch_menu.local_sku, menus.sku) as sku')
            ->selectRaw('COALESCE(active_branch_menu.price, menus.price) as price')
            ->selectRaw('COALESCE(active_branch_menu.station_type, menus.station_type) as station_type')
            ->selectRaw('CASE WHEN menus.is_available = 1 AND active_branch_menu.is_available = 1 THEN 1 ELSE 0 END as is_available');
    }
}
