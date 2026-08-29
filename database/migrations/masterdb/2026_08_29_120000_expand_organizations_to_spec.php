<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Bring `organizations` up to Build Spec §5.
 *
 * Three things happen here: the identity columns the spec exposes (uuid and
 * slug), the business and lifecycle columns it lists, and a wider set of
 * statuses.
 *
 * `plan_id` is added without a foreign key on purpose — the `plans` table
 * arrives in step 6. The constraint goes on then, rather than blocking this
 * step behind a table nothing yet writes to.
 */
return new class extends Migration
{
    /** Old value => new value. */
    private const STATUS_MAP = [
        'pending_setup' => 'pending',
        'expired' => 'pending',   // the invitation lapsed; the org can be re-invited
        'active' => 'active',
        'suspended' => 'suspended',
    ];

    private const STATUSES = [
        'pending',
        'provisioning',
        'active',
        'suspended',
        'cancelled',
        'failed',
    ];

    public function up(): void
    {
        Schema::table('organizations', function (Blueprint $table) {
            // Identity. Nullable for now so the backfill below can run; made
            // non-null and unique once every row has a value.
            $table->ulid('uuid')->nullable()->after('id');
            $table->string('slug', 60)->nullable()->after('organization_name');

            // Required before an organization can go live, per §5.
            $table->string('legal_name')->nullable()->after('slug');
            $table->string('gstin', 20)->nullable()->after('legal_name');
            $table->string('drug_license_no', 60)->nullable()->after('gstin');

            // Set in step 6, when plans exist.
            $table->unsignedBigInteger('plan_id')->nullable()->after('status');

            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamp('suspended_at')->nullable();
            $table->string('suspension_reason', 255)->nullable();

            $table->string('timezone', 64)->default('Asia/Kolkata');
            $table->string('currency', 3)->default('INR');
            $table->string('country', 2)->default('IN');

            $table->text('notes')->nullable();
        });

        $this->backfillIdentity();
        $this->widenStatus();

        Schema::table('organizations', function (Blueprint $table) {
            $table->string('uuid', 26)->nullable(false)->change();
            $table->string('slug', 60)->nullable(false)->change();

            $table->unique('uuid');
            $table->unique('slug');

            // §5 indexes. `email` is this schema's owner_email.
            $table->index('status');
            $table->index('plan_id');
            $table->index('email');
        });
    }

    /**
     * Give every existing row a ULID and a slug.
     *
     * The slug comes from the subdomain, which is already unique and already
     * URL-shaped — inventing one from the display name risked colliding with
     * a subdomain that is meant to match it.
     */
    private function backfillIdentity(): void
    {
        $rows = DB::table('organizations')
            ->select('id', 'subdomain', 'organization_name')
            ->get();

        foreach ($rows as $row) {
            $slug = Str::slug($row->subdomain ?: $row->organization_name);

            DB::table('organizations')
                ->where('id', $row->id)
                ->update([
                    'uuid' => (string) Str::ulid(),
                    'slug' => Str::limit($slug, 60, ''),
                ]);
        }
    }

    /**
     * Replace the check constraint, mapping the old values across first.
     *
     * Laravel's enum() compiles to a varchar plus a CHECK on PostgreSQL, so
     * widening it means dropping that constraint and adding the new one —
     * ->change() cannot do it.
     */
    private function widenStatus(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_status_check');

        foreach (self::STATUS_MAP as $old => $new) {
            if ($old === $new) {
                continue;
            }

            DB::table('organizations')->where('status', $old)->update(['status' => $new]);
        }

        DB::statement('ALTER TABLE organizations ALTER COLUMN status SET DEFAULT \'pending\'');

        $list = collect(self::STATUSES)->map(fn ($s) => "'{$s}'")->implode(', ');

        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ({$list}))");
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE organizations DROP CONSTRAINT IF EXISTS organizations_status_check');

        foreach (array_unique(self::STATUS_MAP) as $new) {
            $old = array_search($new, self::STATUS_MAP, true);

            if ($old !== $new) {
                DB::table('organizations')->where('status', $new)->update(['status' => $old]);
            }
        }

        // Anything in a status that did not exist before goes back to the
        // original default rather than violating the narrower constraint.
        DB::table('organizations')
            ->whereNotIn('status', ['pending_setup', 'active', 'suspended', 'expired'])
            ->update(['status' => 'pending_setup']);

        DB::statement('ALTER TABLE organizations ALTER COLUMN status SET DEFAULT \'pending_setup\'');
        DB::statement("ALTER TABLE organizations ADD CONSTRAINT organizations_status_check CHECK (status IN ('pending_setup', 'active', 'suspended', 'expired'))");

        Schema::table('organizations', function (Blueprint $table) {
            $table->dropUnique(['uuid']);
            $table->dropUnique(['slug']);
            $table->dropIndex(['status']);
            $table->dropIndex(['plan_id']);
            $table->dropIndex(['email']);

            $table->dropColumn([
                'uuid',
                'slug',
                'legal_name',
                'gstin',
                'drug_license_no',
                'plan_id',
                'trial_ends_at',
                'activated_at',
                'suspended_at',
                'suspension_reason',
                'timezone',
                'currency',
                'country',
                'notes',
            ]);
        });
    }
};
