<?php

use App\Http\Controllers\Settings\BillingController;
use App\Http\Controllers\Settings\BusinessController;
use App\Http\Controllers\Settings\PasswordController;
use App\Http\Controllers\Settings\PaymentsController;
use App\Http\Controllers\Settings\ProfileController;
use App\Http\Controllers\Settings\TeamController;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;

Route::middleware('auth')->group(function () {
    Route::redirect('settings', 'settings/profile');

    Route::get('settings/profile', [ProfileController::class, 'edit'])->name('profile.edit');
    Route::patch('settings/profile', [ProfileController::class, 'update'])->name('profile.update');
    Route::delete('settings/profile', [ProfileController::class, 'destroy'])->name('profile.destroy');

    Route::get('settings/password', [PasswordController::class, 'edit'])->name('password.edit');
    Route::put('settings/password', [PasswordController::class, 'update'])->name('password.update');

    Route::get('settings/appearance', function () {
        return Inertia::render('settings/appearance');
    })->name('appearance');
});

Route::middleware(['auth', 'business'])->group(function () {
    Route::get('settings/business', [BusinessController::class, 'edit'])->name('business.edit');
    Route::patch('settings/business', [BusinessController::class, 'update'])->name('business.update');

    Route::get('settings/billing', [BillingController::class, 'edit'])->name('billing.edit');
    Route::post('settings/billing/checkout/{plan}', [BillingController::class, 'checkout'])->name('billing.checkout');
    Route::post('settings/billing/portal', [BillingController::class, 'portal'])->name('billing.portal');

    Route::get('settings/payments', [PaymentsController::class, 'edit'])->name('payments.edit');
    Route::post('settings/payments/connect', [PaymentsController::class, 'connect'])->name('payments.connect');
    Route::get('settings/payments/connect/return', [PaymentsController::class, 'return'])->name('payments.connect.return');
    Route::get('settings/payments/connect/refresh', [PaymentsController::class, 'refresh'])->name('payments.connect.refresh');
    Route::delete('settings/payments/connect', [PaymentsController::class, 'disconnect'])->name('payments.disconnect');

    Route::get('settings/team', [TeamController::class, 'edit'])->name('team.edit');
    Route::post('settings/team/invitations', [TeamController::class, 'invite'])->name('team.invite');
    Route::delete('settings/team/invitations/{invitation}', [TeamController::class, 'cancelInvitation'])->name('team.invitations.destroy');
    Route::delete('settings/team/members/{member}', [TeamController::class, 'removeMember'])->name('team.members.destroy');
});
