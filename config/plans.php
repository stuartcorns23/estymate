<?php

/*
| Subscription plans for businesses using Estymate.
|
| Limits are per business; null means unlimited. Monthly limits count
| estimates and invoices created since the 1st of the month, including
| deleted ones. Prices are in pence and only used for display: the real
| price lives on the Stripe Price whose id is set in .env.
*/

return [

    // New businesses get this plan free for this many days.
    'trial_days' => 14,
    'trial_plan' => 'pro',

    // Plan for businesses with no subscription and no trial.
    'default' => 'free',

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'stripe_price' => null,
            'limits' => [
                'clients' => 5,
                'invoices_per_month' => 5,
                'estimates_per_month' => 5,
                'team_members' => 1,
            ],
        ],

        'starter' => [
            'name' => 'Starter',
            'price' => 900,
            'stripe_price' => env('STRIPE_PRICE_STARTER'),
            'limits' => [
                'clients' => 50,
                'invoices_per_month' => 50,
                'estimates_per_month' => 50,
                'team_members' => 2,
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'price' => 2400,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
            'limits' => [
                'clients' => null,
                'invoices_per_month' => null,
                'estimates_per_month' => null,
                'team_members' => 10,
            ],
        ],
    ],

];
