<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Credit notes and delivery notes live in the documents table with their own types and numbering.
     *
     * A credit note points at the invoice it credits through source_document_id. The invoice keeps a
     * running amount_credited, which comes off its balance. Money paid back to the client against a
     * credit note is recorded on the credit note as its refund.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('creditnote_prefix', 20)->default('CN-')->after('proforma_prefix');
            $table->string('deliverynote_prefix', 20)->default('DN-')->after('creditnote_prefix');
            $table->unsignedInteger('next_creditnote_number')->default(1)->after('next_proforma_number');
            $table->unsignedInteger('next_deliverynote_number')->default(1)->after('next_creditnote_number');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_credited')->default(0)->after('amount_paid');
            $table->unsignedBigInteger('refund_amount')->default(0)->after('amount_credited');
            $table->date('refunded_on')->nullable()->after('refund_amount');
            $table->string('refund_method')->nullable()->after('refunded_on');
            $table->string('refund_reference')->nullable()->after('refund_method');
            $table->text('delivery_address')->nullable()->after('notes');
        });
    }

    public function down(): void
    {
        Schema::table('documents', function (Blueprint $table) {
            $table->dropColumn(['amount_credited', 'refund_amount', 'refunded_on', 'refund_method', 'refund_reference', 'delivery_address']);
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn(['creditnote_prefix', 'deliverynote_prefix', 'next_creditnote_number', 'next_deliverynote_number']);
        });
    }
};
