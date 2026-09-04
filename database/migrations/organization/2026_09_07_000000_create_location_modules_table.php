<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Level two of the permission flow: which modules a branch actually uses.
 *
 * The super admin sells a module to the organization (`organization_modules`,
 * master database). This says which of the organization's branches it runs at,
 * and the owner decides it. A chain can hold the appointments module and run an
 * OPD at one branch while the other two are a plain counter.
 *
 * A SPARSE OVERRIDE, not an allow-list. A row exists only where the owner has
 * switched something off; a branch with no rows uses everything the
 * organization has. That is the same shape `entity_field_settings` already uses
 * for field configuration, and it has two properties worth having: the feature
 * is opt-in, so nothing changes for an organization that never opens the
 * screen, and a module bought later is live at every branch immediately instead
 * of silently reaching none of them.
 *
 * `module_key` is a varchar, not a foreign key. The `modules` catalogue lives in
 * the master database and this table lives in a tenant one; Postgres cannot
 * reference across databases. ModuleRegistry is the vocabulary both sides
 * share, and a key naming a module that has since been retired simply stops
 * matching — level one already refused it before this table is consulted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('location_modules', function (Blueprint $table) {
            $table->id();

            $table->foreignId('location_id')->constrained('locations')->cascadeOnDelete();

            $table->string('module_key', 100);

            /*
             * Always false in practice today — a row only gets written to turn
             * something off. The column is here rather than implied by the
             * row's existence because "there is a row, therefore it is off" is
             * the kind of rule that reads backwards to whoever finds it next,
             * and because turning a branch back on should be a value changing
             * rather than history being deleted.
             */
            $table->boolean('is_enabled')->default(false);

            $table->timestamps();

            $table->unique(['location_id', 'module_key']);
        });

        // Every permission check for a member of staff walks this.
        DB::statement(
            'CREATE INDEX location_modules_disabled_idx ON location_modules '.
            '(location_id) WHERE is_enabled = false'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('location_modules');
    }
};
