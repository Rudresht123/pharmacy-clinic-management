<?php

use App\Support\Modules\ModuleRegistry;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every role is a branch's own.
 *
 * Until now the organization wrote roles (Receptionist, Doctor, Head office…)
 * and every branch borrowed them. From here each branch sets its own roles and
 * the organization only decides which modules a branch runs. So:
 *
 *   1. every organization role is copied into every branch — the copy keeps
 *      whatever the role had been edited to, minus organization-level
 *      permissions, which a branch role cannot hold;
 *   2. everybody holding the shared role at a branch is moved onto that
 *      branch's copy;
 *   3. somebody who held one on their person (`users.role_id` — doctors and
 *      head office) is seated at their branches on the copy instead: a doctor
 *      at the branches they are posted to, anybody else at every branch;
 *   4. the organization roles go — except the software's own two, `staff`
 *      (which nothing is given any more, kept because code still names it)
 *      and the patient app's role.
 *
 * Nobody's access changes on the day: each person holds, at each branch, what
 * the shared role gave them there.
 *
 * The DB facade rather than the models: a migration has to keep replaying
 * against the schema as it was on the day it was written.
 */
return new class extends Migration
{
    /** The software's own roles, which stay where they are. */
    private const KEPT = ['staff', 'patient'];

    public function up(): void
    {
        $now = now();
        $branches = DB::table('locations')->whereNull('deleted_at')->orderBy('id')->pluck('id')->all();

        $shared = DB::table('roles')
            ->whereNull('location_id')
            ->whereNotIn('slug', self::KEPT)
            ->orderBy('id')
            ->get();

        foreach ($shared as $role) {
            $capabilities = DB::table('role_capabilities')
                ->where('role_id', $role->id)
                ->pluck('capability')
                ->filter(fn (string $capability) => ModuleRegistry::capabilityScope($capability) !== ModuleRegistry::SCOPE_ORGANIZATION)
                ->values()
                ->all();

            $copies = [];

            foreach ($branches as $branchId) {
                $copies[$branchId] = $this->copyAt($role, (int) $branchId, $capabilities, $now);
            }

            // The Export Center's column choices for the role go with each copy.
            if (Schema::hasTable('export_column_rules')) {
                $rules = DB::table('export_column_rules')->where('role_id', $role->id)->get();

                foreach ($copies as $copyId) {
                    foreach ($rules as $rule) {
                        DB::table('export_column_rules')->updateOrInsert(
                            ['role_id' => $copyId, 'dataset' => $rule->dataset],
                            ['hidden_columns' => $rule->hidden_columns, 'created_at' => $now, 'updated_at' => $now],
                        );
                    }
                }
            }

            // 2. Who held it at a branch holds that branch's copy.
            foreach ($copies as $branchId => $copyId) {
                DB::table('branch_users')
                    ->where('location_id', $branchId)
                    ->where('role_id', $role->id)
                    ->update(['role_id' => $copyId, 'updated_at' => $now]);
            }

            // 3. Who held it on their person is seated at their branches.
            $holders = DB::table('users')->where('role_id', $role->id)->get(['id', 'userable_type', 'userable_id']);

            foreach ($holders as $holder) {
                $seatedAt = str_ends_with((string) $holder->userable_type, 'Doctor')
                    ? DB::table('doctor_locations')->where('doctor_id', $holder->userable_id)->pluck('location_id')->all()
                    : $branches;

                foreach ($seatedAt as $branchId) {
                    if (isset($copies[$branchId])) {
                        $this->seat((int) $holder->id, (int) $branchId, $copies[$branchId], $now);
                    }
                }

                DB::table('users')->where('id', $holder->id)->update(['role_id' => null, 'updated_at' => $now]);
            }

            // 4. Nothing points at it any more.
            DB::table('role_capabilities')->where('role_id', $role->id)->delete();
            DB::table('branch_role_capabilities')->where('role_id', $role->id)->delete();
            DB::table('roles')->where('id', $role->id)->delete();
        }
    }

    public function down(): void
    {
        // Not reversible: which branch copies were once one shared role is not
        // recorded, and guessing would merge roles branches have since changed.
    }

    /** The branch's copy of a shared role — reusing one of the same name it already has. */
    private function copyAt(object $role, int $branchId, array $capabilities, mixed $now): int
    {
        $existing = DB::table('roles')
            ->where('location_id', $branchId)
            ->whereRaw('LOWER(name) = ?', [mb_strtolower($role->name)])
            ->value('id');

        if ($existing) {
            return (int) $existing;
        }

        $slug = "{$role->slug}-{$branchId}";
        $suffix = 2;

        while (DB::table('roles')->where('slug', $slug)->exists()) {
            $slug = "{$role->slug}-{$branchId}-".$suffix++;
        }

        $copyId = DB::table('roles')->insertGetId([
            'name' => $role->name,
            'slug' => $slug,
            'scope' => 'branch',
            'location_id' => $branchId,
            'description' => $role->description,
            'icon' => $role->icon,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($capabilities !== []) {
            DB::table('role_capabilities')->insert(array_map(fn (string $capability) => [
                'role_id' => $copyId,
                'capability' => $capability,
                'created_at' => $now,
                'updated_at' => $now,
            ], $capabilities));
        }

        return $copyId;
    }

    /** A seat at a branch on a role — unless they already have one there with a role. */
    private function seat(int $userId, int $branchId, int $roleId, mixed $now): void
    {
        $seat = DB::table('branch_users')->where('user_id', $userId)->where('location_id', $branchId)->first();

        if ($seat !== null) {
            if ($seat->role_id === null) {
                DB::table('branch_users')->where('id', $seat->id)->update(['role_id' => $roleId, 'updated_at' => $now]);
            }

            return;
        }

        $hasPrimary = DB::table('branch_users')->where('user_id', $userId)->where('is_primary', true)->exists();

        DB::table('branch_users')->insert([
            'user_id' => $userId,
            'location_id' => $branchId,
            'role_id' => $roleId,
            'is_primary' => ! $hasPrimary,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }
};
