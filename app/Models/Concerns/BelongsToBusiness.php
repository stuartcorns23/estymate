<?php

namespace App\Models\Concerns;

use App\Models\Business;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Auth;

/**
 * Keeps a model's rows private to the signed-in user's current business.
 *
 * Queries are limited to the current business, and new rows are assigned to it.
 */
trait BelongsToBusiness
{
    public static function bootBelongsToBusiness(): void
    {
        static::addGlobalScope('business', function (Builder $query) {
            if ($businessId = Auth::user()?->current_business_id) {
                $query->where($query->qualifyColumn('business_id'), $businessId);
            }
        });

        static::creating(function ($model) {
            $model->business_id ??= Auth::user()?->current_business_id;
        });
    }

    /**
     * @return BelongsTo<Business, $this>
     */
    public function business(): BelongsTo
    {
        return $this->belongsTo(Business::class);
    }
}
