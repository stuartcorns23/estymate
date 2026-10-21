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
            $table->string('estimate_prefix', 20)->default('EST-');
            $table->string('invoice_prefix', 20)->default('INV-');
            $table->unsignedInteger('next_estimate_number')->default(1);
            $table->unsignedInteger('next_invoice_number')->default(1);
            $table->unsignedSmallInteger('payment_terms_days')->default(30);
            $table->text('payment_details')->nullable();
        });

        // Estimates, invoices and later document types share one table, told apart by type.
        Schema::create('documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained();
            $table->string('type');
            $table->string('number');
            $table->string('status');
            $table->date('issue_date');
            $table->date('due_date')->nullable();
            $table->string('reference')->nullable();
            $table->text('notes')->nullable();
            $table->string('currency', 3)->default('GBP');
            $table->unsignedBigInteger('subtotal')->default(0);
            $table->unsignedBigInteger('tax_total')->default(0);
            $table->unsignedBigInteger('total')->default(0);
            $table->foreignId('source_document_id')->nullable()->constrained('documents')->nullOnDelete();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->unique(['business_id', 'type', 'number']);
            $table->index(['business_id', 'type', 'status']);
        });

        Schema::create('document_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('position')->default(0);
            $table->string('description', 1000);
            $table->decimal('quantity', 10, 2);
            $table->string('unit')->nullable();
            $table->unsignedBigInteger('unit_price');
            // The rate is copied onto the line so later rate changes don't alter issued documents.
            $table->decimal('tax_rate', 5, 2);
            $table->unsignedBigInteger('net_amount');
            $table->unsignedBigInteger('tax_amount');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('document_items');
        Schema::dropIfExists('documents');

        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'estimate_prefix', 'invoice_prefix', 'next_estimate_number', 'next_invoice_number',
                'payment_terms_days', 'payment_details',
            ]);
        });
    }
};
