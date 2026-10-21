<?php

namespace App\Actions;

use App\Enums\BusinessRole;
use App\Models\Business;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class CreateBusiness
{
    /**
     * Create a business owned by the user and switch them to it.
     *
     * @param  array<string, mixed>  $attributes
     */
    public function handle(User $owner, array $attributes): Business
    {
        return DB::transaction(function () use ($owner, $attributes) {
            $business = Business::create($attributes);

            $business->addUser($owner, BusinessRole::Owner);
            $owner->switchBusiness($business);

            return $business;
        });
    }
}
