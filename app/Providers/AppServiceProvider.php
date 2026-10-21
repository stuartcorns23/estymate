<?php

namespace App\Providers;

use App\Models\Business;
use App\Services\Stripe\StripeClientGateway;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\ServiceProvider;
use Laravel\Cashier\Cashier;
use Stripe\StripeClient;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(StripeGateway::class, fn () => new StripeClientGateway(
            new StripeClient((string) config('services.stripe.secret')),
            config('services.stripe.connect_webhook_secret'),
        ));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Businesses, not users, subscribe to Estymate.
        Cashier::useCustomerModel(Business::class);
    }
}
