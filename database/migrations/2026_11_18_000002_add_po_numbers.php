<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The client's purchase order number on invoices, estimates and pro-formas. Each client can
     * have a default PO number that new documents for them start with.
     */
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->string('default_po_number', 100)->nullable()->after('vat_number');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->string('po_number', 100)->nullable()->after('reference');
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table) {
            $table->dropColumn('default_po_number');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn('po_number');
        });
    }
};
