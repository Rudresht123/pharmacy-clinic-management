<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Hands the Smart Queue's ticks to the roles already doing that work.
 *
 * A template only writes a role when it is first seeded, so nobody already set
 * up would see the live board until something unrelated rewrote their role.
 * Decided by what a role already HOLDS rather than by its slug: each branch has
 * its own copy of every role, renamed as it likes, and an owner who took the
 * queue away from a role has said what it is for and is not overruled here.
 *
 *   queue.board       whoever may see today's queue
 *   queue.my_console  whoever has a doctor's own queue
 *   queue.control     whoever works the queue, doctors included
 *   queue.priority    the same
 *   queue.reassign    the desk: works the queue and sees all of it
 *   queue.settings    whoever may change the branch's timetable
 *   queue.displays    the same
 *
 * These do nothing until the organization has the module: Permission asks about
 * the module before it reads a role. So no clinic gains anything by this running,
 * and one that buys the module later finds its people ready.
 *
 * `queue.any_doctor` is given to nobody: it is the explicit grant that lets one
 * doctor drive another's queue, and defaults to no.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->grant('queue.board', ['appointments.queue_view']);
        $this->grant('queue.my_console', ['appointments.my_queue']);
        $this->grant('queue.control', ['appointments.queue']);
        $this->grant('queue.priority', ['appointments.queue']);
        $this->grant('queue.reassign', ['appointments.queue', 'appointments.queue_view']);
        $this->grant('queue.settings', ['appointments.schedule']);
        $this->grant('queue.displays', ['appointments.schedule']);

        $this->addTokenCalledNotification();
    }

    public function down(): void
    {
        DB::table('role_capabilities')->where('capability', 'like', 'queue.%')->delete();

        DB::table('automation_rules')->where('event_key', 'queue.token_called')->delete();
        DB::table('message_templates')->where('channel', 'push')->where('name', 'Your token is called')->delete();
    }

    /**
     * @param  list<string>  $holding  a role must already hold all of these
     */
    private function grant(string $capability, array $holding): void
    {
        $now = now();

        $roleIds = null;

        foreach ($holding as $held) {
            $ids = DB::table('role_capabilities')->where('capability', $held)->pluck('role_id');
            $roleIds = $roleIds === null ? $ids : $roleIds->intersect($ids);
        }

        foreach ($roleIds as $roleId) {
            DB::table('role_capabilities')->updateOrInsert(
                ['role_id' => $roleId, 'capability' => $capability],
                ['updated_at' => $now, 'created_at' => $now],
            );
        }
    }

    /**
     * The phone half of "your token is called". Off, like every other patient
     * rule (see PushDefaults): reaching a phone is the clinic's decision. The
     * in-app bell needs no rule and always rings.
     *
     * Not added to PushDefaults, whose order older migrations read by position.
     */
    private function addTokenCalledNotification(): void
    {
        $now = now();

        $templateId = DB::table('message_templates')
            ->where('channel', 'push')
            ->whereRaw('lower(name) = ?', ['your token is called'])
            ->whereNull('deleted_at')
            ->value('id');

        $templateId ??= DB::table('message_templates')->insertGetId([
            'channel' => 'push',
            'name' => 'Your token is called',
            'category' => 'Appointment',
            'subject' => 'Your token is called',
            'content' => 'Token {{token_no}} has been called. Please go to {{doctor_name}} in {{room}}.',
            'status' => 'approved',
            'icon' => 'ti ti-bell-ringing',
            'tone' => 'sky',
            'is_active' => true,
            'sort_order' => (int) DB::table('message_templates')->where('channel', 'push')->max('sort_order') + 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        DB::table('automation_rules')->updateOrInsert(
            ['channel' => 'push', 'event_key' => 'queue.token_called'],
            [
                'title' => 'Tell the patient their token is called',
                'description' => 'A push to the patient\'s phone the moment their token is called',
                'icon' => 'ti ti-bell-ringing',
                'message_template_id' => $templateId,
                'is_enabled' => false,
                'lead_minutes' => null,
                'sort_order' => (int) DB::table('automation_rules')->where('channel', 'push')->max('sort_order') + 1,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        );
    }
};
