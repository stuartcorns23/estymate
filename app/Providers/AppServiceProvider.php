<?php

namespace App\Providers;

use App\Services\Stripe\StripeClientGateway;
use App\Services\Stripe\StripeGateway;
use Illuminate\Support\ServiceProvider;
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
        //
    }
}
