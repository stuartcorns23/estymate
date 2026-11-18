<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Pro-forma invoices live in the documents table with their own type, and get their own numbering.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('proforma_prefix', 20)->default('PF-')->after('invoice_prefix');
            $table->unsignedInteger('next_proforma_number')->default(1)->after('next_invoice_number');
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['proforma_prefix', 'next_proforma_number']);
        });
    }
};
