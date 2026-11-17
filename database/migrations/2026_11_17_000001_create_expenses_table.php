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
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->string('supplier');
            // The supplier's own invoice or receipt number.
            $table->string('reference')->nullable();
            $table->date('date');
            $table->string('category');
            $table->string('description')->nullable();
            // Money is stored in pence. Total includes VAT; net is total minus VAT.
            $table->unsignedBigInteger('total');
            $table->unsignedBigInteger('vat_amount')->default(0);
            $table->string('currency', 3)->default('GBP');
            $table->date('paid_on')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['business_id', 'date']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
