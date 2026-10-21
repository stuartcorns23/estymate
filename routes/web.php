<?php

use App\Billing\Plan;
use App\Enums\DocumentType;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CurrentBusinessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentActionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\StripeWebhookController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome', [
        'plans' => array_map(fn (Plan $plan) => $plan->toArray(), Plan::all()),
        'trialDays' => config('plans.trial_days'),
    ]);
})->name('home');

Route::get('d/{token}', [PublicDocumentController::class, 'show'])->name('public.documents.show');
Route::get('d/{token}/pdf', [PublicDocumentController::class, 'pdf'])->name('public.documents.pdf');
Route::post('d/{token}/pay', [PublicDocumentController::class, 'pay'])->middleware('throttle:10,1')->name('public.documents.pay');
Route::post('stripe/webhook', StripeWebhookController::class)->name('stripe.webhook');

Route::middleware(['auth'])->group(function () {
    Route::get('businesses/create', [BusinessController::class, 'create'])->name('businesses.create');
    Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::put('current-business', [CurrentBusinessController::class, 'update'])->name('current-business.update');
    Route::get('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', DashboardController::class)->name('dashboard');

    Route::resource('clients', ClientController::class)->except('show');
    Route::resource('products', ProductController::class)->except('show');
    Route::resource('recurring-invoices', RecurringInvoiceController::class)->only(['index', 'update', 'destroy']);

    // Estimates and invoices share one controller; the "type" default tells it which.
    foreach (DocumentType::cases() as $type) {
        Route::prefix($type->plural())->name("{$type->plural()}.")->group(function () use ($type) {
            $routes = [
                Route::get('/', [DocumentController::class, 'index'])->name('index'),
                Route::get('create', [DocumentController::class, 'create'])->name('create'),
                Route::post('/', [DocumentController::class, 'store'])->name('store'),
                Route::get('{document}', [DocumentController::class, 'show'])->name('show'),
                Route::get('{document}/edit', [DocumentController::class, 'edit'])->name('edit'),
                Route::put('{document}', [DocumentController::class, 'update'])->name('update'),
                Route::delete('{document}', [DocumentController::class, 'destroy'])->name('destroy'),
                Route::patch('{document}/status', [DocumentController::class, 'updateStatus'])->name('status'),
                Route::get('{document}/pdf', [DocumentActionController::class, 'pdf'])->name('pdf'),
                Route::post('{document}/send', [DocumentActionController::class, 'send'])->name('send'),
            ];

            if ($type === DocumentType::Estimate) {
                $routes[] = Route::post('{document}/convert', [DocumentActionController::class, 'convert'])->name('convert');
            }

            if ($type === DocumentType::Invoice) {
                $routes[] = Route::post('{document}/payments', [PaymentController::class, 'store'])->name('payments.store');
                $routes[] = Route::delete('{document}/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
                $routes[] = Route::post('{document}/recurring', [RecurringInvoiceController::class, 'store'])->name('recurring.store');
            }

            foreach ($routes as $route) {
                $route->defaults('type', $type->value);
            }
        });
    }
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
