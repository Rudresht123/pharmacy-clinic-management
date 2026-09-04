<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Level three of the permission flow: what a person may do.
 *
 * Roles are organization-wide rather than defined per branch. Which branches a
 * role's holder may touch is already answered twice over — `users.location_id`
 * says where they work and `location_modules` says what runs there — so a
 * per-branch role would only produce five copies of "Receptionist" that drift
 * apart.
 *
 * `users.role` is NOT replaced. It stays the account's kind: an owner is the
 * organization's account holder, not a configurable role, and bypasses this
 * table entirely. What an owner does not bypass is level one — a module the
 * organization was never sold is refused to them exactly as it is to anybody
 * else. `users.role_id` is the new thing, and it only means anything for staff.
 *
 * Capabilities are a row each rather than a jsonb array. The permission check
 * itself would be happy with either, since it loads the role once and asks in
 * PHP; "which roles can delete a patient" is the question that wants rows, and
 * it is the question somebody asks after something has gone wrong.
 */
return new class extends Migration
{
    /**
     * What a member of staff can do today, before roles exist.
     *
     * Read off the tenant routes as they stand: staff read branches, read and
     * write customers, and work the OPD queue. Everything else is owner-only.
     * Seeding exactly this, and putting every existing staff member on it,
     * is what makes the day this ships a day on which nothing changes.
     */
    private const STAFF_CAPABILITIES = [
        'branches.view',
        'customers.view',
        'customers.manage',
        'appointments.view',
        'appointments.book',
    ];

    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();

            $table->string('name', 60);
            $table->string('slug', 60);
            $table->string('description', 255)->nullable();

            /*
             * A system role is one the software depends on being there — today
             * only `staff`, the role every existing member of staff was moved
             * onto. Its capabilities are the owner's to change; its existence
             * is not, or staff created before the change would be left
             * pointing at nothing.
             */
            $table->boolean('is_system')->default(false);

            $table->timestamps();

            $table->unique('slug');
        });

        Schema::create('role_capabilities', function (Blueprint $table) {
            $table->id();

            $table->foreignId('role_id')->constrained('roles')->cascadeOnDelete();

            /*
             * A varchar naming a ModuleRegistry capability. Not a foreign key
             * for the same reason `location_modules.module_key` is not one: the
             * catalogue lives in the master database. Validation checks the key
             * against the registry before it is stored, so a typo cannot be
             * saved and then quietly match nothing forever.
             */
            $table->string('capability', 100);

            $table->timestamps();

            $table->unique(['role_id', 'capability']);
        });

        Schema::table('users', function (Blueprint $table) {
            /*
             * Restricted, not nulled. Deleting a role out from under the people
             * holding it would strip them of every permission at once, and the
             * failure would show up as somebody unable to work rather than as
             * an error anybody could read. The controller refuses it with a
             * sentence naming how many people hold the role.
             */
            $table->foreignId('role_id')
                ->nullable()
                ->after('role')
                ->constrained('roles')
                ->restrictOnDelete();
        });

        $this->seedStaffRole();
    }

    /**
     * Create the `staff` role and move every existing member of staff onto it.
     *
     * The DB facade rather than the Role and User models is deliberate: a
     * migration has to keep replaying correctly against the schema as it was
     * the day it was written, and a model changes underneath it. The project's
     * Models-not-facade rule is about application code.
     */
    private function seedStaffRole(): void
    {
        $now = now();

        $roleId = DB::table('roles')->insertGetId([
            'name' => 'Staff',
            'slug' => 'staff',
            'description' => 'What everybody who is not the owner could already do.',
            'is_system' => true,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('role_capabilities')->insert(array_map(
            fn (string $capability) => [
                'role_id' => $roleId,
                'capability' => $capability,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            self::STAFF_CAPABILITIES,
        ));

        DB::table('users')
            ->where('role', 'staff')
            ->whereNull('role_id')
            ->update(['role_id' => $roleId]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('role_id');
        });

        Schema::dropIfExists('role_capabilities');
        Schema::dropIfExists('roles');
    }
};
