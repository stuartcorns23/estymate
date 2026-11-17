<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * How each business's estimates and invoices look: layout, logo, colours and footer.
     */
    public function up(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->string('document_layout', 20)->default('classic');
            $table->string('document_colour', 7)->default('#1F2937');
            $table->string('document_accent_colour', 7)->default('#2563EB');
            $table->string('document_font', 20)->default('sans');
            $table->string('document_logo_path')->nullable();
            $table->text('document_footer')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('businesses', function (Blueprint $table) {
            $table->dropColumn([
                'document_layout',
                'document_colour',
                'document_accent_colour',
                'document_font',
                'document_logo_path',
                'document_footer',
            ]);
        });
    }
};
