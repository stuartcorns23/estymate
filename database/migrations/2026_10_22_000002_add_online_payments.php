<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('stripe_account_id')->nullable()->unique();
            $table->boolean('stripe_charges_enabled')->default(false);
        });

        Schema::table('documents', function (Blueprint $table) {
            // Unguessable token for the client-facing page, so clients don't need an account.
            $table->string('public_token', 64)->nullable()->unique();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('public_token');
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropUnique(['stripe_account_id']);
            $table->dropColumn(['stripe_account_id', 'stripe_charges_enabled']);
        });
    }
};
