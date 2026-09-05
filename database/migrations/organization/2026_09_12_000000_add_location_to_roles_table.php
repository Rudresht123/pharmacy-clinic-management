<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a branch write its own roles.
 *
 * Roles were organization-wide definitions, on the reasoning that five copies
 * of "Receptionist" drift apart. That holds for a small organization and stops
 * holding for a large one: the owner of a fifty-branch chain is not going to
 * sit writing every role, and the branch manager is the person who knows what
 * their receptionist actually does.
 *
 * What keeps the drift bounded is the cascade, not central authorship:
 *
 *   the super admin sells modules to the organization
 *   the owner decides which of those run at each branch
 *   a branch writes roles only from the modules IT was given
 *
 * A branch therefore cannot invent a permission the organization never had,
 * which was the real worry — not that two branches might name a role
 * differently.
 *
 * `location_id` null means an organization-wide role, written by the owner and
 * available everywhere. Set means a role belonging to that branch, offered
 * nowhere else. Every existing role becomes organization-wide, which is what
 * they already were.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roles', function (Blueprint $table) {
            /*
             * Cascade: a branch's own roles have no meaning once the branch is
             * gone. Locations soft-delete, so this only fires on a hard delete
             * from a console — and organization-wide roles, which are null
             * here, are untouched by it either way.
             */
            $table->foreignId('location_id')
                ->nullable()
                ->after('scope')
                ->constrained('locations')
                ->cascadeOnDelete();
        });

        /*
         * The name is unique per owner, not globally. Two branches may each
         * have a "Receptionist" — that is the point of the change — but one
         * branch may not have two.
         *
         * Partial, because Postgres treats every NULL as distinct: without the
         * second index the organization-wide roles would lose their uniqueness
         * entirely.
         */
        DB::statement('DROP INDEX IF EXISTS roles_name_unique');
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_name_unique');

        DB::statement(
            'CREATE UNIQUE INDEX roles_name_per_branch_unique ON roles (location_id, name) '.
            'WHERE location_id IS NOT NULL'
        );

        DB::statement(
            'CREATE UNIQUE INDEX roles_name_organization_unique ON roles (name) '.
            'WHERE location_id IS NULL'
        );
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS roles_name_per_branch_unique');
        DB::statement('DROP INDEX IF EXISTS roles_name_organization_unique');

        /*
         * A branch's own roles are deleted rather than promoted to
         * organization-wide. Promoting them would offer every branch a role
         * written for one, which is a wider grant than anybody chose — and the
         * foreign keys on the memberships holding them will refuse the delete
         * loudly if anyone still does, which is the right way to find out.
         */
        DB::table('roles')->whereNotNull('location_id')->delete();

        Schema::table('roles', function (Blueprint $table) {
            $table->dropConstrainedForeignId('location_id');
        });

        DB::statement('CREATE UNIQUE INDEX roles_name_unique ON roles (name)');
    }
};
