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
        Schema::create('assistant_conversations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('title')->nullable();
            // The conversation exactly as it is sent to the Claude API, so it can be replayed unchanged.
            $table->json('transcript');
            $table->timestamps();

            $table->index(['business_id', 'user_id', 'updated_at']);
        });

        // What the person sees: their questions, the assistant's replies and invoice drafts.
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role');
            $table->text('body');
            // Tokens used to answer, summed over every API call for this reply.
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('cache_read_tokens')->default(0);
            $table->unsignedInteger('cache_write_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->timestamps();

            $table->index(['business_id', 'role', 'created_at']);
        });

        // Invoices the assistant has prepared, waiting for the person to confirm.
        Schema::create('assistant_invoice_drafts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('business_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_conversation_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assistant_message_id')->nullable()->constrained()->nullOnDelete();
            $table->json('data');
            $table->string('status')->default('pending');
            $table->foreignId('document_id')->nullable()->constrained()->nullOnDelete();
            // Whether the assistant has been told the person confirmed or cancelled it.
            $table->boolean('reported')->default(false);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('assistant_invoice_drafts');
        Schema::dropIfExists('assistant_messages');
        Schema::dropIfExists('assistant_conversations');
    }
};
