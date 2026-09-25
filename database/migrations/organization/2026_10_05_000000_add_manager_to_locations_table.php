<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Who runs this branch.
 *
 * A CACHE OF A FACT, NOT THE PERMISSION. What the manager may actually do
 * still comes from their `branch_users` row and the role on it, exactly as it
 * does for everybody else — so nothing downstream has to learn a new concept,
 * and a `manager_id` that somehow disagreed with the memberships would grant
 * nothing at all.
 *
 * What it buys is the question that could not be asked before: "who is the
 * manager of Gurgaon". Reading that off the memberships means scanning every
 * membership at the branch for one holding a role that happens to be called
 * something — which is not a fact, it is a guess about a name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            /*
             * nullOnDelete rather than cascade: removing a person must not
             * remove the branch they ran. The branch is then simply unmanaged,
             * which is a real state — it is how every branch starts.
             */
            $table->foreignId('manager_id')
                ->nullable()
                ->after('is_active')
                ->constrained('users')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('manager_id');
        });
    }
};
