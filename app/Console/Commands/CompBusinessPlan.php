<?php

namespace App\Console\Commands;

use App\Billing\Plan;
use App\Models\Business;
use Illuminate\Console\Command;

class CompBusinessPlan extends Command
{
    protected $signature = 'business:comp {business : The business id} {plan? : Plan key, or leave out to remove the comp}';

    protected $description = 'Give a business a plan for free (for example your own), or remove it';

    public function handle(): int
    {
        $business = Business::findOrFail($this->argument('business'));
        $plan = $this->argument('plan');

        if ($plan !== null) {
            Plan::find($plan);
        }

        $business->forceFill(['plan_override' => $plan])->save();

        $this->info($plan ? "{$business->name} is now on the {$business->plan()->name} plan for free." : "{$business->name} no longer has a free plan.");

        return self::SUCCESS;
    }
}
