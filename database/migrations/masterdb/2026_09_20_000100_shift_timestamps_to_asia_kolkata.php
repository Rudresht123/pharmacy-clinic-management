<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Re-express every stored instant in Asia/Kolkata instead of UTC — the
 * master database's copy of the organization migration with the same name,
 * which explains the reasoning.
 *
 * Two differences here:
 *
 * - platform_audit_logs is the append-only table, guarded by its own trigger.
 * - organization_modules.starts_at / expires_at are left alone. They are
 *   calendar dates kept in timestamp columns — entered as a date, stored at
 *   midnight, read back with toDateString() and endOfDay() — so moving them
 *   would make a module start at 05:30 on its first day.
 */
return new class extends Migration
{
    /** Timestamp columns that hold a calendar date, not an instant. */
    private const NOT_INSTANTS = [
        'organization_modules.starts_at',
        'organization_modules.expires_at',
    ];

    /** The append-only guard that would refuse the rewrite, by table. */
    private const AUDIT_TRIGGERS = [
        'platform_audit_logs' => 'platform_audit_logs_no_update_or_delete',
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
