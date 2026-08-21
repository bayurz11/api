<?php

namespace App\Models\Concerns;

use App\Models\Branch;
use App\Support\BranchContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

trait BelongsToBranch
{
    public static function bootBelongsToBranch(): void
    {
        static::addGlobalScope('branch', function (Builder $builder): void {
            $branchId = app(BranchContext::class)->id();
            if ($branchId) {
                $builder->where($builder->qualifyColumn('branch_id'), $branchId);
            }
        });

        static::creating(function (Model $model): void {
            if ($model->getAttribute('branch_id')) {
                return;
            }

            $branchId = app(BranchContext::class)->id();

            // Commands, seeders, and legacy single-branch integrations do not
            // pass through the HTTP middleware. Keep them safe only when the
            // destination branch is unambiguous.
            if (! $branchId && Schema::hasTable('branches')) {
                $branchIds = Branch::query()
                    ->where('is_active', true)
                    ->limit(2)
                    ->pluck('id');

                if ($branchIds->count() === 1) {
                    $branchId = (int) $branchIds->first();
                }
            }

            if ($branchId) {
                $model->setAttribute('branch_id', $branchId);
            }
        });
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
