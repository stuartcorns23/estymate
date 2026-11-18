<?php

/*
| Subscription plans for businesses using Estymate.
|
| Limits are per business; null means unlimited. Monthly limits count
| estimates, invoices, pro-forma invoices and delivery notes created since
| the 1st of the month, including deleted ones. Credit notes are never
| limited, since they correct invoices. Converting a pro-forma or an estimate
| creates an invoice, so it counts towards the invoice limit too. Prices are in pence and only used for display: the real
| prices live on the Stripe Prices whose ids are set in .env. A plan can
| have a monthly and a yearly Stripe Price on the same Stripe Product.
| assistant_messages_per_month counts questions asked of the in-app assistant.
| Features are switched on per plan; tax_return is the tax year report.
*/

return [

    // New businesses get this plan free for this many days.
    'trial_days' => 14,
    'trial_plan' => 'pro',

    // Plan for businesses with no subscription and no trial.
    'default' => 'free',

    // Charge UK VAT on subscriptions with Stripe Tax. Turn on once Stripe Tax is
    // set up with your VAT registration, and the Stripe Prices' tax behaviour is set.
    'stripe_tax' => (bool) env('STRIPE_TAX', false),

    'plans' => [
        'free' => [
            'name' => 'Free',
            'price' => 0,
            'yearly_price' => 0,
            'stripe_price' => null,
            'stripe_yearly_price' => null,
            'limits' => [
                'clients' => 5,
                'invoices_per_month' => 5,
                'estimates_per_month' => 5,
                'proformas_per_month' => 5,
                'deliverynotes_per_month' => 5,
                'team_members' => 1,
                'assistant_messages_per_month' => 20,
            ],
            'features' => [
                'tax_return' => false,
            ],
        ],

        'starter' => [
            'name' => 'Starter',
            'price' => 900,
            'yearly_price' => 9000,
            'stripe_price' => env('STRIPE_PRICE_STARTER'),
            'stripe_yearly_price' => env('STRIPE_PRICE_STARTER_YEARLY'),
            'limits' => [
                'clients' => 50,
                'invoices_per_month' => 50,
                'estimates_per_month' => 50,
                'proformas_per_month' => 50,
                'deliverynotes_per_month' => 50,
                'team_members' => 2,
                'assistant_messages_per_month' => 200,
            ],
            'features' => [
                'tax_return' => false,
            ],
        ],

        'pro' => [
            'name' => 'Pro',
            'price' => 2400,
            'yearly_price' => 24000,
            'stripe_price' => env('STRIPE_PRICE_PRO'),
            'stripe_yearly_price' => env('STRIPE_PRICE_PRO_YEARLY'),
            'limits' => [
                'clients' => null,
                'invoices_per_month' => null,
                'estimates_per_month' => null,
                'proformas_per_month' => null,
                'deliverynotes_per_month' => null,
                'team_members' => 10,
                'assistant_messages_per_month' => null,
            ],
            'features' => [
                'tax_return' => true,
            ],
        ],
    ],

];
