<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who gets a message at one branch, where the branch has chosen for itself.
 *
 * The same shape as a branch's own wording (message_template_variants). The rule
 * is the organisation's, so a branch manager writing on it would change every other
 * branch's messages too. Sparse: no row means the branch follows the organisation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('automation_rule_branch_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('automation_rule_id')->constrained('automation_rules')->cascadeOnDelete();
            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();
            $table->jsonb('recipients');
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['automation_rule_id', 'location_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('automation_rule_branch_recipients');
    }
};
