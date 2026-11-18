<?php

use App\Billing\Plan;
use App\Enums\DocumentType;
use App\Http\Controllers\AssistantController;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\ClientImportController;
use App\Http\Controllers\CurrentBusinessController;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\DocumentActionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseController;
use App\Http\Controllers\ExpenseImportController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\InvoiceImportController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\PublicDocumentController;
use App\Http\Controllers\RecurringInvoiceController;
use App\Http\Controllers\StripeWebhookController;
use App\Http\Controllers\TaxReturnController;
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

    Route::get('assistant', [AssistantController::class, 'index'])->name('assistant.index');
    Route::post('assistant/messages', [AssistantController::class, 'store'])->middleware('throttle:20,1')->name('assistant.messages.store');
    Route::delete('assistant/{conversation}', [AssistantController::class, 'destroy'])->name('assistant.destroy');
    Route::post('assistant/drafts/{draft}/confirm', [AssistantController::class, 'confirm'])->name('assistant.drafts.confirm');
    Route::post('assistant/drafts/{draft}/cancel', [AssistantController::class, 'cancel'])->name('assistant.drafts.cancel');

    Route::get('clients/import', [ClientImportController::class, 'create'])->name('clients.import');
    Route::post('clients/import/preview', [ClientImportController::class, 'preview'])->name('clients.import.preview');
    Route::post('clients/import', [ClientImportController::class, 'store'])->name('clients.import.store');
    Route::resource('clients', ClientController::class)->except('show');
    Route::resource('products', ProductController::class)->except('show');
    Route::get('expenses/import', [ExpenseImportController::class, 'create'])->name('expenses.import');
    Route::post('expenses/import/preview', [ExpenseImportController::class, 'preview'])->name('expenses.import.preview');
    Route::post('expenses/import', [ExpenseImportController::class, 'store'])->name('expenses.import.store');
    Route::post('expenses/scan', [ExpenseController::class, 'scan'])->middleware('throttle:20,1')->name('expenses.scan');
    Route::resource('expenses', ExpenseController::class)->except('show');
    Route::get('expenses/{expense}/attachment', [ExpenseController::class, 'attachment'])->name('expenses.attachment');
    Route::get('tax-return', [TaxReturnController::class, 'index'])->name('tax-return.index');
    Route::get('tax-return/csv', [TaxReturnController::class, 'csv'])->name('tax-return.csv');
    Route::get('tax-return/pdf', [TaxReturnController::class, 'pdf'])->name('tax-return.pdf');
    Route::resource('recurring-invoices', RecurringInvoiceController::class)->only(['index', 'update', 'destroy']);

    // Before the invoice routes, so "import" isn't taken for an invoice.
    Route::get('invoices/import', [InvoiceImportController::class, 'create'])->name('invoices.import');
    Route::post('invoices/import/preview', [InvoiceImportController::class, 'preview'])->name('invoices.import.preview');
    Route::post('invoices/import', [InvoiceImportController::class, 'store'])->name('invoices.import.store');

    // Every document type shares one controller; the "type" default tells it which.
    foreach (DocumentType::cases() as $type) {
        Route::prefix($type->plural())->name("{$type->plural()}.")->group(function () use ($type) {
            $routes = [
                Route::get('/', [DocumentController::class, 'index'])->name('index'),
                // Credit notes are made from their invoice, so "create" only points the way.
                Route::get('create', [DocumentController::class, 'create'])->name('create'),
                Route::get('{document}', [DocumentController::class, 'show'])->name('show'),
                Route::get('{document}/edit', [DocumentController::class, 'edit'])->name('edit'),
                Route::put('{document}', [DocumentController::class, 'update'])->name('update'),
                Route::delete('{document}', [DocumentController::class, 'destroy'])->name('destroy'),
                Route::patch('{document}/status', [DocumentController::class, 'updateStatus'])->name('status'),
                Route::get('{document}/pdf', [DocumentActionController::class, 'pdf'])->name('pdf'),
                Route::post('{document}/send', [DocumentActionController::class, 'send'])->name('send'),
            ];

            if (! $type->createdFromInvoice()) {
                $routes[] = Route::post('/', [DocumentController::class, 'store'])->name('store');
            }

            if ($type->makesDeliveryNotes()) {
                $routes[] = Route::post('{document}/delivery-note', [DocumentActionController::class, 'deliveryNote'])->name('delivery-note');
            }

            if ($type === DocumentType::CreditNote) {
                $routes[] = Route::post('{document}/refund', [DocumentActionController::class, 'refund'])->name('refund.store');
                $routes[] = Route::delete('{document}/refund', [DocumentActionController::class, 'destroyRefund'])->name('refund.destroy');
            }

            if ($type->convertsToInvoice()) {
                $routes[] = Route::post('{document}/convert', [DocumentActionController::class, 'convert'])->name('convert');
            }

            if ($type === DocumentType::Invoice) {
                $routes[] = Route::post('{document}/payments', [PaymentController::class, 'store'])->name('payments.store');
                $routes[] = Route::delete('{document}/payments/{payment}', [PaymentController::class, 'destroy'])->name('payments.destroy');
                $routes[] = Route::post('{document}/credit-note', [DocumentActionController::class, 'creditNote'])->name('credit-note');
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
