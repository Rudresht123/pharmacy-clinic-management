<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What a branch has taken away from one of the organization's roles.
 *
 * The level that was missing. Until now a role was one row shared by every
 * branch that used it, so "Doctor" meant exactly the same thing everywhere;
 * giving Delhi's doctors less than Lucknow's meant cloning the role, and a
 * chain ended up with branches × job titles roles drifting apart.
 *
 * A SPARSE SUBTRACTION, and only a subtraction. A row says "this branch has
 * removed this capability from this role"; no row means the organization's
 * answer stands. There is deliberately no `allowed` column and no way to add:
 *
 *   The rule is that a branch may never grant what the organization did not.
 *   Written as a deny list, that rule is not enforced — it is IMPOSSIBLE to
 *   express the violation. A boolean here would make the same rule a check
 *   somebody has to remember on every write, and a check that is forgotten
 *   once is a branch manager granting themselves anything they like.
 *
 * This is the same shape, and the same reasoning, as `user_permission_overrides`
 * one level down and `location_modules` one level up.
 *
 * Applies ONLY to roles the organization wrote (`roles.location_id IS NULL`)
 * that are assigned at branches (`roles.scope = 'branch'`). A role a branch
 * wrote for itself is simply edited — two ways to express the same change is
 * how they drift. An organization-scoped role is head office's and is not a
 * branch's to clip.
 *
 * `capability` is a varchar, not a foreign key, exactly as in `role_capabilities`
 * above it: the vocabulary is ModuleRegistry, in code, not a table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_role_capabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            /*
             * Cascades with the role: a role that no longer exists cannot have
             * customisations, and leaving them would resurrect somebody else's
             * decisions if the id were ever reused.
             */
            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            $table->string('capability', 100);

            /*
             * Not a foreign key, for the same reason activity_logs' actor is
             * not: the branch manager who made the decision may later be
             * removed, and the decision outlives them.
             */
            $table->unsignedBigInteger('created_by')->nullable();

            $table->timestamps();

            // One decision per capability per role per branch.
            $table->unique(['location_id', 'role_id', 'capability']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('branch_role_capabilities');
    }
};
