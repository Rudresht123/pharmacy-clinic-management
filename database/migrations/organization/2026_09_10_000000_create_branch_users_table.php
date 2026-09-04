<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Branch membership, and the scope a role is written for.
 *
 * `users.location_id` and `users.role_id` are single foreign keys, so a person
 * has one branch and one role. That cannot say "Rahul is a receptionist at
 * Lucknow and the manager at Delhi", cannot describe a doctor who sits at three
 * clinics, and turns a transfer into a rewrite of where somebody has always
 * worked. Membership is the relationship that was missing, and it carries the
 * role — because a role only means anything somewhere.
 *
 * ADDITIVE ONLY. Both columns stay, still written and still read; nothing
 * consults this table yet. Backfill runs here so the next release can switch
 * the readers over as a deploy rather than a data migration, and roll back the
 * same way.
 *
 * The DB facade rather than the models is deliberate and is not an exception to
 * the project's Models-not-facade rule: a migration has to keep replaying
 * correctly against the schema as it was the day it was written, and a model
 * changes underneath it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('branch_users', function (Blueprint $table) {
            $table->id();

            // A membership has no meaning once the person is gone.
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();

            /*
             * Restricted, not cascaded. Locations soft-delete, so this only
             * fires on a hard delete from a console — which is exactly where
             * somebody should be stopped from silently detaching every member
             * of a branch.
             */
            $table->foreignId('location_id')->constrained('locations')->restrictOnDelete();

            /*
             * Nullable: somebody can be a member of a branch while holding
             * nothing there yet. Restricted so a role in use cannot be deleted
             * out from under its holders — the same rule `users.role_id`
             * already follows.
             */
            $table->foreignId('role_id')->nullable()->constrained('roles')->restrictOnDelete();

            /*
             * Which branch the app opens on for somebody who works at three.
             * A convenience for the client and never an input to permission —
             * being "primary" somewhere grants nothing extra there.
             */
            $table->boolean('is_primary')->default(false);

            $table->timestamps();
        });

        /*
         * One membership per person per branch. Not partial: this table does
         * not soft-delete, because a membership that ended is a row that was
         * removed — and the activity log already records who removed it.
         */
        DB::statement(
            'CREATE UNIQUE INDEX branch_users_unique ON branch_users (user_id, location_id)'
        );

        // "Who works here" is the staff screen's hot path.
        DB::statement('CREATE INDEX branch_users_location_idx ON branch_users (location_id)');

        // At most one primary per person.
        DB::statement(
            'CREATE UNIQUE INDEX branch_users_primary_unique ON branch_users (user_id) '.
            'WHERE is_primary'
        );

        Schema::table('roles', function (Blueprint $table) {
            $table->string('scope', 20)->default('branch')->after('slug');
        });

        // Varchar + CHECK rather than a native enum, matching the house style.
        DB::statement(
            "ALTER TABLE roles ADD CONSTRAINT roles_scope_check ".
            "CHECK (scope IN ('organization', 'branch'))"
        );

        $this->backfill();
    }

    /**
     * Turn every existing placement into a membership, changing nobody's reach.
     *
     * Two shapes exist in the data and they need different answers:
     *
     *   a branch and a role  → a membership carrying that role, marked primary
     *   a role and NO branch → head office. There is no branch to make a
     *                          membership at, so the role stays on
     *                          `users.role_id` and has to be organization-scoped
     *                          to mean anything.
     *
     * The second case cannot simply re-scope the role in place: the same role
     * is usually also held by branch staff, and flipping it would hand them the
     * whole network. Nor can it be left alone, which would silently strip the
     * head-office account. So the role is COPIED at organization scope, and only
     * the branch-less holders are moved onto the copy. The original keeps its
     * name, its holders and its scope.
     */
    private function backfill(): void
    {
        $now = now();

        $withBranch = DB::table('users')
            ->whereNotNull('location_id')
            ->whereNull('deleted_at')
            ->get(['id', 'location_id', 'role_id']);

        foreach ($withBranch as $user) {
            DB::table('branch_users')->insert([
                'user_id' => $user->id,
                'location_id' => $user->location_id,
                'role_id' => $user->role_id,
                'is_primary' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        $headOffice = DB::table('users')
            ->where('role', 'staff')
            ->whereNull('location_id')
            ->whereNotNull('role_id')
            ->whereNull('deleted_at')
            ->get(['id', 'role_id']);

        // Copies are cached so twenty head-office staff on one role produce one
        // organization role between them, not twenty.
        $copies = [];

        foreach ($headOffice as $user) {
            if (! isset($copies[$user->role_id])) {
                $copies[$user->role_id] = $this->organizationCopyOf($user->role_id, $now);
            }

            DB::table('users')
                ->where('id', $user->id)
                ->update(['role_id' => $copies[$user->role_id], 'updated_at' => $now]);
        }
    }

    /** An organization-scoped role holding exactly what the original holds. */
    private function organizationCopyOf(int $roleId, mixed $now): int
    {
        $original = DB::table('roles')->where('id', $roleId)->first();

        $name = $original->name.' (organization)';
        $slug = $original->slug.'-organization';

        $existing = DB::table('roles')->where('slug', $slug)->first();

        if ($existing) {
            return $existing->id;
        }

        $copyId = DB::table('roles')->insertGetId([
            'name' => $name,
            'slug' => $slug,
            'scope' => 'organization',
            'description' => 'Head-office equivalent of "'.$original->name.'", created when '.
                'branch membership was introduced. Rename it freely.',
            'icon' => $original->icon,

            // Not a system role: the software does not depend on it existing,
            // and an owner who does not want it should be able to delete it.
            'is_system' => false,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $capabilities = DB::table('role_capabilities')
            ->where('role_id', $roleId)
            ->pluck('capability');

        if ($capabilities->isNotEmpty()) {
            DB::table('role_capabilities')->insert(
                $capabilities->map(fn (string $capability) => [
                    'role_id' => $copyId,
                    'capability' => $capability,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->all()
            );
        }

        return $copyId;
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE roles DROP CONSTRAINT IF EXISTS roles_scope_check');

        Schema::table('roles', function (Blueprint $table) {
            $table->dropColumn('scope');
        });

        /*
         * The organization copies are left behind on purpose. Deleting them
         * would strip whoever was moved onto one, and `users.role_id` still
         * points at them — rolling the schema back is not a reason to take
         * somebody's permissions away.
         */
        Schema::dropIfExists('branch_users');
    }
};
