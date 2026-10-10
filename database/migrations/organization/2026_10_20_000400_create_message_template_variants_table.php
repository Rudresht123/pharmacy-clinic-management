<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One branch's own wording of a template — the BRANCH level of
 * Global → Organisation → Branch.
 *
 * The template itself is the organisation's (and began as the platform's
 * default). A variant rewords it for one branch: Gurgaon can say "Gurgaon
 * clinic" where the organisation says "the clinic". Sparse: no row means the
 * branch sends the organisation's wording; an inactive row means the same.
 *
 * Email and App notifications only. A WhatsApp template's wording is what the
 * provider approved, under its name — a branch rewording it would be a
 * different, unapproved message, so WhatsApp templates take no variants.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('message_template_variants', function (Blueprint $table) {
            $table->id();
            $table->foreignId('message_template_id')->constrained('message_templates')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            // The title for an App notification, the subject for an email.
            $table->string('subject', 255)->nullable();
            $table->text('content');

            $table->boolean('is_active')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['message_template_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('message_template_variants');
    }
};
