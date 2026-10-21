<?php

use App\Enums\DocumentType;
use App\Http\Controllers\BusinessController;
use App\Http\Controllers\ClientController;
use App\Http\Controllers\CurrentBusinessController;
use App\Http\Controllers\DocumentActionController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\InvitationController;
use App\Http\Controllers\ProductController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::get('/', function () {
    return Inertia::render('welcome');
})->name('home');

Route::middleware(['auth'])->group(function () {
    Route::get('businesses/create', [BusinessController::class, 'create'])->name('businesses.create');
    Route::post('businesses', [BusinessController::class, 'store'])->name('businesses.store');
    Route::put('current-business', [CurrentBusinessController::class, 'update'])->name('current-business.update');
    Route::get('invitations/{token}', [InvitationController::class, 'accept'])->name('invitations.accept');
});

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('dashboard', function () {
        return Inertia::render('dashboard');
    })->name('dashboard');

    Route::resource('clients', ClientController::class)->except('show');
    Route::resource('products', ProductController::class)->except('show');

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

            foreach ($routes as $route) {
                $route->defaults('type', $type->value);
            }
        });
    }
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
