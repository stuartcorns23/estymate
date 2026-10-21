<?php

use App\Http\Controllers\BusinessController;
use App\Http\Controllers\CurrentBusinessController;
use App\Http\Controllers\InvitationController;
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
});

require __DIR__.'/settings.php';
require __DIR__.'/auth.php';
