<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Contact and bank details for the document footer, and an optional signature on documents.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('website')->nullable();
            $table->string('contact_name')->nullable();
            $table->string('bank_name')->nullable();
            $table->string('bank_account_name')->nullable();
            $table->string('bank_sort_code', 20)->nullable();
            $table->string('bank_account_number', 20)->nullable();
            $table->string('bank_iban', 40)->nullable();
            $table->string('bank_bic', 20)->nullable();
            $table->boolean('document_footer_details')->default(true);
            $table->string('document_signature_path')->nullable();
            $table->boolean('document_show_signature')->default(false);
            $table->string('document_signature_name')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'website',
                'contact_name',
                'bank_name',
                'bank_account_name',
                'bank_sort_code',
                'bank_account_number',
                'bank_iban',
                'bank_bic',
                'document_footer_details',
                'document_signature_path',
                'document_show_signature',
                'document_signature_name',
            ]);
        });
    }
};
