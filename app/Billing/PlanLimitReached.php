<?php

namespace App\Billing;

use Exception;
use Illuminate\Http\RedirectResponse;

class PlanLimitReached extends Exception
{
    public function __construct(public readonly Plan $plan, public readonly string $resource)
    {
        parent::__construct("The {$plan->name} plan limit for {$resource} has been reached.");
    }

    /**
     * Send the person to the billing page with a friendly prompt to upgrade.
     */
    public function render(): RedirectResponse
    {
        $what = match ($this->resource) {
            'clients' => "{$this->plan->limit('clients')} clients",
            'invoices_per_month' => "{$this->plan->limit('invoices_per_month')} invoices a month",
            'estimates_per_month' => "{$this->plan->limit('estimates_per_month')} estimates a month",
            'proformas_per_month' => "{$this->plan->limit('proformas_per_month')} pro-forma invoices a month",
            'deliverynotes_per_month' => "{$this->plan->limit('deliverynotes_per_month')} delivery notes a month",
            'team_members' => "{$this->plan->limit('team_members')} team members",
            'assistant_messages_per_month' => "{$this->plan->limit('assistant_messages_per_month')} assistant questions a month",
            default => 'this feature',
        };

        return redirect()->route('billing.edit')->with('status', "The {$this->plan->name} plan includes {$what}. Upgrade to add more.");
    }
}
