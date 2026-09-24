<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Sending to many patients at once, now or later.
 *
 * The messaging tables before this one cover a message to ONE patient,
 * triggered by something that happened to them. A campaign is the other half:
 * a clinic decides to say something, chooses who hears it, and picks when.
 *
 * Three things make that work, and each is a table:
 *
 *   A SEGMENT is who. Stored as filters rather than as a list of patients,
 *   because "patients with diabetes" means whoever that is on the morning the
 *   campaign goes out, not whoever it was when somebody saved the segment.
 *
 *   A CAMPAIGN is what, and when. `scheduled_at` is the whole point of the
 *   feature — a campaign with one is picked up by the dispatcher when its time
 *   comes, with nobody present.
 *
 *   A RECIPIENT is one patient's copy of it. Resolved when the campaign is
 *   dispatched and kept afterwards, so "who did this actually reach" survives
 *   the segment being edited later.
 */
return new class extends Migration
{
    private const CHANNELS = ['whatsapp', 'email', 'sms'];

    /**
     * A campaign's life.
     *
     * `sending` exists so a dispatcher that dies half way leaves evidence it
     * was running, rather than a scheduled campaign that silently never went.
     */
    private const CAMPAIGN_STATUSES = ['draft', 'scheduled', 'sending', 'completed', 'paused', 'failed', 'cancelled'];

    private const RECIPIENT_STATUSES = ['pending', 'sent', 'delivered', 'opened', 'clicked', 'failed', 'skipped'];

    public function up(): void
    {
        $in = fn (array $values) => "'".implode("', '", $values)."'";

        /*
        |------------------------------------------------------------------
        | Who hears it
        |------------------------------------------------------------------
        |
        | `filters` is a list of conditions the audience builder writes and
        | the resolver reads. System segments ship with the module and cannot
        | be deleted — "All patients" is not an opinion a clinic should be
        | able to remove and then wonder where it went.
        */
        Schema::create('audience_segments', function (Blueprint $table) {
            $table->id();
            $table->string('name', 150);
            $table->string('description', 500)->nullable();
            $table->string('icon', 60)->nullable();
            $table->string('tone', 20)->nullable();

            $table->json('filters')->nullable();

            $table->boolean('is_system')->default(false);
            $table->smallInteger('sort_order')->default(0);

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();
        });

        DB::statement(
            'CREATE UNIQUE INDEX audience_segments_name_unique ON audience_segments (lower(name)) '.
            'WHERE deleted_at IS NULL'
        );

        /*
        |------------------------------------------------------------------
        | What is sent, and when
        |------------------------------------------------------------------
        |
        | The sender is COPIED onto the campaign rather than read from the
        | channel at send time: a clinic that changes its from-address next
        | month must not change what an already-scheduled campaign claims to
        | be from.
        |
        | `scheduled_at` is timestampTz because a campaign set for 9am is set
        | for 9am in the clinic's timezone, and a dispatcher comparing it to
        | `now()` has to be comparing the same thing.
        */
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 20);
            $table->string('name', 200);

            // Promotional, transactional, announcement — what kind of thing
            // this is, which is also what decides whether it needs consent.
            $table->string('campaign_type', 40)->nullable();
            $table->string('goal', 60)->nullable();

            $table->string('subject', 255)->nullable();
            $table->string('preview_text', 255)->nullable();

            $table->foreignId('message_template_id')->nullable()
                ->constrained('message_templates')->nullOnDelete();
            $table->text('content')->nullable();

            // Copies, true to the moment the campaign was written.
            $table->string('from_name', 150)->nullable();
            $table->string('from_email', 150)->nullable();
            $table->string('reply_to', 150)->nullable();

            $table->foreignId('audience_segment_id')->nullable()
                ->constrained('audience_segments')->nullOnDelete();
            $table->json('audience_filters')->nullable();

            $table->string('status', 20)->default('draft');

            // The scheduling itself.
            $table->timestampTz('scheduled_at')->nullable();
            $table->timestampTz('started_at')->nullable();
            $table->timestampTz('completed_at')->nullable();

            // Counters, written by the dispatcher as it goes. Denormalised on
            // purpose: a campaign list shows six of these per row and counting
            // recipients for each would be six queries a row.
            $table->unsignedInteger('recipients_count')->default(0);
            $table->unsignedInteger('sent_count')->default(0);
            $table->unsignedInteger('delivered_count')->default(0);
            $table->unsignedInteger('opened_count')->default(0);
            $table->unsignedInteger('clicked_count')->default(0);
            $table->unsignedInteger('failed_count')->default(0);

            $table->string('failure_reason', 500)->nullable();

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->softDeletes();
            $table->foreignId('deleted_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('deletion_reason', 500)->nullable();

            // What the dispatcher asks for: everything due, oldest first.
            $table->index(['status', 'scheduled_at']);
            $table->index(['channel', 'created_at']);
        });

        DB::statement(
            'ALTER TABLE campaigns ADD CONSTRAINT campaigns_rules_check CHECK ('.
            'channel IN ('.$in(self::CHANNELS).') AND '.
            'status IN ('.$in(self::CAMPAIGN_STATUSES).') AND '.
            // A scheduled campaign without a time would never be picked up,
            // and would sit looking scheduled forever.
            "(status <> 'scheduled' OR scheduled_at IS NOT NULL))"
        );

        /*
        |------------------------------------------------------------------
        | One patient's copy
        |------------------------------------------------------------------
        |
        | Written when the campaign is dispatched, from the segment resolved
        | at that moment. The address is copied for the same reason the
        | message log copies it: where something was actually sent is history,
        | not something to re-derive from a patient who may have moved.
        */
        Schema::create('campaign_recipients', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained('campaigns')->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained('customers')->nullOnDelete();

            $table->string('recipient_name', 150)->nullable();
            $table->string('recipient', 150);

            $table->string('status', 20)->default('pending');
            $table->string('failure_reason', 500)->nullable();

            $table->timestampTz('sent_at')->nullable();
            $table->timestampTz('opened_at')->nullable();
            $table->timestampTz('clicked_at')->nullable();

            $table->timestamps();

            // One patient is not written to twice by the same campaign.
            $table->unique(['campaign_id', 'customer_id']);
            $table->index(['campaign_id', 'status']);
        });

        DB::statement(
            'ALTER TABLE campaign_recipients ADD CONSTRAINT campaign_recipients_status_check '.
            'CHECK (status IN ('.$in(self::RECIPIENT_STATUSES).'))'
        );

        // A send that belongs to a campaign says so, which is what lets the
        // delivery log stay one table across triggered and bulk sending.
        Schema::table('message_logs', function (Blueprint $table) {
            $table->foreignId('campaign_id')->nullable()->after('message_template_id')
                ->constrained('campaigns')->nullOnDelete();

            // When a queued message should actually go. Null means now.
            $table->timestampTz('scheduled_for')->nullable()->after('is_test');
        });

        // The dispatcher's other question: which single messages are due.
        DB::statement(
            'CREATE INDEX message_logs_due_index ON message_logs (scheduled_for) '.
            "WHERE status = 'queued' AND scheduled_for IS NOT NULL"
        );

        $this->seedSegments();
    }

    /**
     * The segments every clinic has, whether or not it builds its own.
     *
     * Public so it can be tested on its own, and safe to run twice.
     */
    public function seedSegments(): void
    {
        if (DB::connection()->pretending()) {
            return;
        }

        $segments = [
            ['name' => 'All patients', 'description' => 'Everybody on the register', 'icon' => 'ti ti-users', 'tone' => 'sky', 'filters' => []],
            ['name' => 'New patients', 'description' => 'Registered in the last three months', 'icon' => 'ti ti-user-plus', 'tone' => 'emerald', 'filters' => [['field' => 'registered', 'operator' => 'within_months', 'value' => 3]]],
            ['name' => 'Inactive patients', 'description' => 'No visit in the last six months', 'icon' => 'ti ti-hourglass', 'tone' => 'amber', 'filters' => [['field' => 'last_visit', 'operator' => 'older_than_months', 'value' => 6]]],
        ];

        foreach ($segments as $order => $segment) {
            DB::table('audience_segments')->updateOrInsert(
                ['name' => $segment['name']],
                [
                    'description' => $segment['description'],
                    'icon' => $segment['icon'],
                    'tone' => $segment['tone'],
                    'filters' => json_encode($segment['filters']),
                    'is_system' => true,
                    'sort_order' => $order,
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
            );
        }
    }

    public function down(): void
    {
        Schema::table('message_logs', function (Blueprint $table) {
            $table->dropConstrainedForeignId('campaign_id');
            $table->dropColumn('scheduled_for');
        });

        Schema::dropIfExists('campaign_recipients');
        Schema::dropIfExists('campaigns');
        Schema::dropIfExists('audience_segments');
    }
};
