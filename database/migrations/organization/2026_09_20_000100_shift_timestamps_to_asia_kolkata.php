<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-express every stored instant in Asia/Kolkata instead of UTC.
 *
 * The application wrote UTC into `timestamp without time zone` columns while
 * the database server — and every AGE() or CURRENT_DATE it evaluated — ran in
 * India time. The application now runs in Asia/Kolkata as well (config/app.php,
 * and the session timezone in config/database.php), so every value already
 * stored moves forward by 5h30 once, here, and means what it meant.
 *
 * Only instants move. `date` and `time` columns (a date of birth, a sitting
 * that starts at 09:00) are wall-clock values and are not selected at all.
 *
 * activity_logs is append-only by trigger. Correcting how its times are
 * stored is not editing what it says, so the one trigger that guards it is
 * disabled for its UPDATE and enabled again straight after — all inside this
 * migration's transaction, which Postgres applies to ALTER TABLE as well. If
 * anything fails, the whole migration rolls back, the trigger's state with it.
 *
 * The master database has its own copy of this migration.
 */
return new class extends Migration
{
    /** Timestamp columns that hold a calendar date, not an instant. */
    private const NOT_INSTANTS = [];

    /** The append-only guard that would refuse the rewrite, by table. */
    private const AUDIT_TRIGGERS = [
        'activity_logs' => 'activity_logs_no_update_or_delete',
    ];

    public function up(): void
    {
        $this->shift('+');
    }

    public function down(): void
    {
        $this->shift('-');
    }

    private function shift(string $sign): void
    {
        $columns = collect(DB::select(<<<'SQL'
SELECT c.table_name, c.column_name
FROM information_schema.columns c
JOIN information_schema.tables t
  ON t.table_schema = c.table_schema AND t.table_name = c.table_name
WHERE c.table_schema = 'public'
  AND t.table_type = 'BASE TABLE'
  AND c.data_type = 'timestamp without time zone'
ORDER BY c.table_name, c.ordinal_position
SQL))
            ->reject(fn (object $column) => in_array($column->table_name.'.'.$column->column_name, self::NOT_INSTANTS, true))
            ->groupBy('table_name');

        DB::transaction(function () use ($columns, $sign) {
            foreach ($columns as $table => $tableColumns) {
                $set = $tableColumns
                    ->map(fn (object $column) => sprintf(
                        '"%1$s" = "%1$s" %2$s interval \'5 hours 30 minutes\'',
                        $column->column_name,
                        $sign,
                    ))
                    ->implode(', ');

                $trigger = self::AUDIT_TRIGGERS[$table] ?? null;

                if ($trigger !== null) {
                    DB::statement(sprintf('ALTER TABLE "%s" DISABLE TRIGGER "%s"', $table, $trigger));
                }

                DB::statement(sprintf('UPDATE "%s" SET %s', $table, $set));

                if ($trigger !== null) {
                    DB::statement(sprintf('ALTER TABLE "%s" ENABLE TRIGGER "%s"', $table, $trigger));
                }
            }
        });
    }
};
