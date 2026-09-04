<?php

namespace App\Support\History;

use App\Models\Platform\PlatformAuditLog;
use App\Models\Tenant\ActivityLog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

/**
 * Records what changed on this model, field by field.
 *
 * Put the trait on a model and every create, update, delete and restore is
 * written to the append-only log — the tenant's own `activity_logs` for a
 * tenant model, `platform_audit_logs` for a central one. Nothing in a
 * controller changes: a change made from an API, a console command or a
 * tinker session is recorded the same way, because the hook is on the model
 * rather than on the request that happened to cause it.
 *
 * Only the fields that actually changed are stored, with their before and
 * after values. Storing whole rows would make every log entry a haystack and
 * would quietly accumulate copies of data the organization may later ask to
 * have deleted.
 *
 * Opt-in on purpose. Hooking every model would sooner or later write a
 * password hash, a remember token or a session payload into a table designed
 * to be impossible to edit afterwards.
 */
trait RecordsHistory
{
    /**
     * Never written, whatever the model says.
     *
     * A belt-and-braces list on top of each model's own `$hidden`: these are
     * the values that must not exist in an append-only table, and relying on
     * every future model to remember that is exactly the mistake this list
     * exists to survive.
     */
    private const NEVER_LOG = [
        'password',
        'password_confirmation',
        'remember_token',
        'api_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
    ];

    /** Noise: these change on every write and say nothing about intent. */
    private const IGNORED = [
        'created_at',
        'updated_at',
        'deleted_at',
        'created_by',
        'updated_by',
        'last_login_at',
        'remember_token',
    ];

    public static function bootRecordsHistory(): void
    {
        static::created(fn (Model $model) => $model->writeHistory('created'));

        static::updated(fn (Model $model) => $model->writeHistory('updated'));

        /*
         * Fires for a soft delete as well as a hard one, which is what the
         * reader means by "deleted" either way. SoftDeletes writes deleted_at
         * through the query builder and never raises `updated`, so there is
         * no second event to guard against — and `deleted_at` is ignored in
         * the diff, so a hand-written update that sets it records nothing
         * rather than a confusing half-event.
         */
        static::deleted(fn (Model $model) => $model->writeHistory('deleted'));

        if (method_exists(static::class, 'restored')) {
            static::restored(fn (Model $model) => $model->writeHistory('restored'));
        }
    }

    /**
     * Which fields this model refuses to log, on top of the global list.
     *
     * Override on a model that carries something sensitive under a name the
     * global list does not know.
     *
     * @return list<string>
     */
    protected function historyExcept(): array
    {
        return [];
    }

    /**
     * Which organization this change is about, if any.
     *
     * Lets a screen ask "everything that happened to this organization"
     * without guessing from the entity type — assigning a module writes an
     * OrganizationModule row whose subject is the binding, not the
     * organization it belongs to.
     */
    protected function historyOrganizationId(): ?int
    {
        return null;
    }

    /**
     * What this record is called, for a log that has to stay readable after
     * the record is gone.
     */
    protected function historyLabel(): ?string
    {
        foreach (['name', 'organization_name', 'title', 'label', 'email'] as $attribute) {
            if (! empty($this->{$attribute}) && is_string($this->{$attribute})) {
                return mb_substr($this->{$attribute}, 0, 191);
            }
        }

        return null;
    }

    public function writeHistory(string $event): void
    {
        [$before, $after] = $this->historyDiff($event);

        // An update that changed nothing worth recording is not an event.
        if ($event === 'updated' && $before === [] && $after === []) {
            return;
        }

        $actor = $this->historyActor();

        $row = [
            'event' => $event,
            'entity_type' => class_basename($this),
            'entity_id' => $this->getKey(),
            'entity_label' => $this->historyLabel(),
            'before' => $before ?: null,
            'after' => $after ?: null,
            'ip_address' => Request::ip(),
        ];

        /*
         * A tenant model writes to the tenant's own database. Deciding by
         * connection rather than by namespace means a model that is moved
         * keeps logging to the right place.
         */
        if ($this->getConnectionName() === 'organization') {
            ActivityLog::on('organization')->create([
                ...$row,
                'user_id' => $actor['type'] === 'tenant' ? $actor['id'] : null,
                'actor_name' => $actor['name'],
                'actor_type' => $actor['type'],
            ]);

            return;
        }

        PlatformAuditLog::create([
            'organization_id' => $this->historyOrganizationId(),
            'platform_user_id' => $actor['type'] === 'platform' ? $actor['id'] : null,
            'actor_name' => $actor['name'],
            'action' => $event,
            'entity_type' => $row['entity_type'],
            'entity_id' => $row['entity_id'],
            'entity_label' => $row['entity_label'],
            'before' => $row['before'],
            'after' => $row['after'],
            'ip_address' => $row['ip_address'],
        ]);
    }

    /**
     * The changed fields, with their old and new values.
     *
     * @return array{0: array<string, mixed>, 1: array<string, mixed>}
     */
    private function historyDiff(string $event): array
    {
        $skip = array_merge(
            self::NEVER_LOG,
            self::IGNORED,
            $this->getHidden(),
            $this->historyExcept(),
        );

        if ($event === 'created') {
            $after = array_diff_key($this->attributesToArray(), array_flip($skip));

            // A row full of nulls says nothing; only what was actually set.
            return [[], array_filter($after, fn ($value) => $value !== null)];
        }

        if ($event === 'deleted' || $event === 'restored') {
            /*
             * No field diff: the event is the whole story, and copying the
             * row into the log on the way out would preserve exactly the
             * data a deletion was meant to remove.
             */
            return [[], []];
        }

        $changes = array_diff_key($this->getChanges(), array_flip($skip));

        $before = [];
        $after = [];

        foreach ($changes as $field => $value) {
            $original = $this->getOriginal($field);

            // Casts are applied so a date reads as a date, not a raw string.
            $before[$field] = $this->historyValue($field, $original);
            $after[$field] = $this->historyValue($field, $value);
        }

        return [$before, $after];
    }

    /** Something that survives json_encode and still means what it meant. */
    private function historyValue(string $field, mixed $value): mixed
    {
        if ($value === null || is_scalar($value) || is_array($value)) {
            return $value;
        }

        return (string) $value;
    }

    /**
     * Who is making this change.
     *
     * The platform guard is asked first, the same order Record::actorId()
     * uses — a super administrator acting inside a tenant database is a
     * platform actor, and the log has to say so rather than attributing it
     * to whichever tenant user happens to share that id.
     *
     * @return array{id: int|string|null, name: string|null, type: string}
     */
    private function historyActor(): array
    {
        if ($platform = Auth::guard('platform')->user()) {
            return [
                'id' => $platform->getKey(),
                'name' => $platform->name ?? $platform->email ?? null,
                'type' => 'platform',
            ];
        }

        if ($tenant = Auth::guard('web')->user()) {
            return [
                'id' => $tenant->getKey(),
                'name' => $tenant->name ?? $tenant->email ?? null,
                'type' => 'tenant',
            ];
        }

        // A console command, a queued job, a seeder — a real actor, just not
        // a person, and saying "system" is more honest than saying nobody.
        return ['id' => null, 'name' => null, 'type' => 'system'];
    }
}
