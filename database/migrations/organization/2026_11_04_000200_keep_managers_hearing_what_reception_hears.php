<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Reception no longer includes the branch manager, who has their own tick now.
 *
 * Managers can answer reschedule requests, so "Front desk" used to reach them. A
 * rule naming it today keeps reaching them: it names the manager beside Reception,
 * and the clinic can untick either from here on.
 *
 * The DB facade rather than the models: a migration has to keep replaying against
 * the schema as it was on the day it was written.
 */
return new class extends Migration
{
    public function up(): void
    {
        $rules = DB::connection('organization')
            ->table('automation_rules')
            ->whereNotNull('recipients')
            ->get(['id', 'recipients']);

        foreach ($rules as $rule) {
            $lists = json_decode((string) $rule->recipients, true);

            if (! is_array($lists)) {
                continue;
            }

            $changed = false;

            foreach ($lists as $field => $entries) {
                $types = array_column((array) $entries, 'type');

                if (in_array('front_desk', $types, true) && ! in_array('branch_manager', $types, true)) {
                    $lists[$field][] = ['type' => 'branch_manager'];
                    $changed = true;
                }
            }

            if ($changed) {
                DB::connection('organization')
                    ->table('automation_rules')
                    ->where('id', $rule->id)
                    ->update(['recipients' => json_encode($lists)]);
            }
        }
    }

    /**
     * Left alone: telling a manager twice over is impossible, and taking the tick
     * off here could silence somebody a clinic chose on purpose.
     */
    public function down(): void {}
};
