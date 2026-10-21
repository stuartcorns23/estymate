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
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('document_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('amount');
            $table->date('paid_on');
            $table->string('method');
            $table->string('reference')->nullable();
            $table->string('stripe_payment_intent_id')->nullable()->unique();
            $table->timestamps();
        });

        Schema::create('recurring_invoices', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            // The invoice whose client, lines and notes each new invoice copies.
            $table->foreignId('template_document_id')->constrained('documents')->cascadeOnDelete();
            $table->string('frequency');
            $table->date('next_issue_date');
            $table->date('ends_on')->nullable();
            $table->boolean('auto_send')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_issued_at')->nullable();
            $table->timestamps();
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->unsignedBigInteger('amount_paid')->default(0)->after('total');
            $table->unsignedTinyInteger('reminders_sent')->default(0)->after('paid_at');
            $table->timestamp('last_reminder_at')->nullable()->after('reminders_sent');
            $table->foreignId('recurring_invoice_id')->nullable()->after('source_document_id')->constrained()->nullOnDelete();
        });

        Schema::table('businesses', function (Blueprint $table) {
            $table->boolean('send_reminders')->default(true);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn('send_reminders');
        });

        Schema::table('documents', function (Blueprint $table) {
            $table->dropConstrainedForeignId('recurring_invoice_id');
            $table->dropColumn(['amount_paid', 'reminders_sent', 'last_reminder_at']);
        });

        Schema::dropIfExists('recurring_invoices');
        Schema::dropIfExists('payments');
    }
};
