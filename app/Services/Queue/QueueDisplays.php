<?php

namespace App\Services\Queue;

use App\Broadcasting\BranchLabQueueChannel;
use App\Broadcasting\DoctorQueueChannel;
use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\QueueDisplay;
use App\Models\Tenant\User;
use App\Services\Realtime\RealtimeConfig;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The televisions: making them, what each one shows, and opening one.
 *
 * THE TOKEN IS TWO THINGS. `{organization}-{secret}`: the first half says which
 * clinic's database to look in, and is no secret at all (it names a channel in
 * every signed-in browser); the second half is 40 random characters. Only the
 * hash of the whole is stored, so the URL cannot be shown again after the moment
 * it is made, and a leaked backup opens no screen. There is no table in the
 * master database mapping screens to clinics, and no scanning of tenant
 * databases to find one: a screen belongs to the one database its token names.
 */
class QueueDisplays
{
    private const SECRET_LENGTH = 40;

    /** A screen re-reads this often anyway; writing every read would be pointless churn. */
    private const SEEN_WRITE_SECONDS = 30;

    public function __construct(
        private readonly QueueBoard $board,
        private readonly LabQueue $lab,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     * @return array{display: QueueDisplay, token: string}
     */
    public function create(Organization $organization, array $data, ?User $actor = null): array
    {
        return (new QueueDisplay)->getConnection()->transaction(function () use ($organization, $data, $actor) {
            $display = new QueueDisplay($this->values($data) + ['created_by' => $actor?->id]);

            $token = $this->newToken($organization);
            $display->forceFill(['secure_display_token_hash' => $this->hash($token)]);
            $display->save();

            $this->syncDoctors($display, $data);

            return ['display' => $display, 'token' => $token];
        });
    }

    /** @param  array<string, mixed>  $data */
    public function update(QueueDisplay $display, array $data): QueueDisplay
    {
        return (new QueueDisplay)->getConnection()->transaction(function () use ($display, $data) {
            $display->fill($this->values($data))->save();

            $this->syncDoctors($display, $data);

            return $display;
        });
    }

    /**
     * Replace the secret. The old URL stops working at once, and a screen that
     * was revoked is usable again at the new one.
     */
    public function rotate(Organization $organization, QueueDisplay $display): string
    {
        $token = $this->newToken($organization);

        $display->forceFill([
            'secure_display_token_hash' => $this->hash($token),
            'revoked_at' => null,
            'last_connected_at' => null,
        ])->save();

        return $token;
    }

    public function revoke(QueueDisplay $display): QueueDisplay
    {
        $display->forceFill(['revoked_at' => now()])->save();

        return $display;
    }

    /**
     * Which clinic a token names, by its public half alone. Nothing here has
     * looked at the secret yet.
     */
    public function organizationFor(string $token): ?Organization
    {
        // The secret holds no dash, so the last one is always the seam.
        $seam = strrpos($token, '-');

        if ($seam === false || $seam === 0 || $seam === strlen($token) - 1) {
            return null;
        }

        return Organization::query()->where('uuid', substr($token, 0, $seam))->first();
    }

    /** The screen this token opens, on the tenant connection already chosen. */
    public function find(string $token): ?QueueDisplay
    {
        return QueueDisplay::query()->where('secure_display_token_hash', $this->hash($token))->first();
    }

    /**
     * The doctors this screen shows, in the order it shows them.
     *
     * The ones picked for it, whatever they are doing today, because somebody
     * chose them. With none picked: everyone at the branch (or department) who
     * is sitting today.
     *
     * @return Collection<int, Doctor>
     */
    public function doctors(QueueDisplay $display): Collection
    {
        if ($display->display_type === QueueDisplay::LABORATORY) {
            return new Collection;
        }

        $picked = $display->doctors()->with('department')->get();

        if ($picked->isNotEmpty() || $display->display_type === QueueDisplay::DOCTOR) {
            return new Collection($picked->all());
        }

        return $this->board->roster(
            (int) $display->location_id,
            QueueClock::today(),
            $display->display_type === QueueDisplay::DEPARTMENT ? $display->department_id : null,
        );
    }

    /**
     * The channels this screen may hear, as Echo's signature check names them.
     *
     * One per doctor, so a room's television holds its own doctor's channel and
     * nobody else's, and a wall screen holds one for each card on it.
     *
     * @return list<string>
     */
    public function channels(QueueDisplay $display, Organization $organization): array
    {
        if ($display->display_type === QueueDisplay::LABORATORY) {
            return ['private-'.BranchLabQueueChannel::name($organization->uuid, (int) $display->location_id)];
        }

        return $this->doctors($display)
            ->map(fn (Doctor $doctor) => 'private-'.DoctorQueueChannel::name($organization->uuid, (int) $display->location_id, (int) $doctor->id))
            ->values()
            ->all();
    }

    /**
     * Everything one screen needs. Tokens, plus the patient being called right now.
     *
     * @return array<string, mixed>
     */
    public function state(QueueDisplay $display, Organization $organization, bool $withChannels = true): array
    {
        $location = Location::query()->find($display->location_id);

        return [
            'display' => [
                'id' => $display->id,
                'name' => $display->display_name,
                'type' => $display->display_type,
                'layout' => $display->layout,
                'theme' => $display->theme,
                'audio_enabled' => $display->audio_enabled,
                'audio_languages' => $display->audio_languages ?: ['en'],
            ],
            'organization' => [
                'name' => $organization->organization_name,
                'logo_url' => $organization->profile_image ? getFileUrl($organization->profile_image) : null,
            ],
            'branch' => ['id' => $location?->id, 'name' => $location?->name],

            'cards' => $display->display_type === QueueDisplay::LABORATORY
                ? []
                : $this->board->cards($this->doctors($display), (int) $display->location_id, QueueClock::today()),

            'lab' => $display->display_type === QueueDisplay::LABORATORY
                ? $this->lab->state((int) $display->location_id, QueueClock::today())
                : null,

            'channels' => $withChannels ? $this->channels($display, $organization) : [],
            'realtime' => $withChannels ? RealtimeConfig::forClient() : ['enabled' => false],
            'server_time' => now()->toIso8601String(),
        ];
    }

    /** Note that a screen is alive, without touching its history or its `updated_at`. */
    public function markSeen(QueueDisplay $display): void
    {
        if ($display->last_connected_at !== null
            && $display->last_connected_at->diffInSeconds(now()) < self::SEEN_WRITE_SECONDS) {
            return;
        }

        $display->timestamps = false;
        $display->forceFill(['last_connected_at' => now()])->saveQuietly();
        $display->timestamps = true;
    }

    /**
     * A display row for the management screen, with whether it is switched on.
     *
     * @return array<string, mixed>
     */
    public function summary(QueueDisplay $display): array
    {
        $display->loadMissing(['location', 'department', 'doctors']);

        return [
            'id' => $display->id,
            'display_name' => $display->display_name,
            'display_type' => $display->display_type,
            'location_id' => (int) $display->location_id,
            'location_name' => $display->location?->name,
            'department_id' => $display->department_id,
            'department_name' => $display->department?->name,
            'doctor_ids' => $display->doctors->pluck('id')->map(fn ($id) => (int) $id)->all(),
            'doctors' => $display->doctors->map(fn (Doctor $doctor) => ['id' => (int) $doctor->id, 'name' => $doctor->name])->all(),
            'layout' => $display->layout,
            'theme' => $display->theme,
            'audio_enabled' => $display->audio_enabled,
            'audio_languages' => $display->audio_languages ?: ['en'],
            'is_active' => $display->is_active,
            'expires_at' => $display->expires_at?->toIso8601String(),
            'revoked_at' => $display->revoked_at?->toIso8601String(),
            'last_connected_at' => $display->last_connected_at?->toIso8601String(),
            'is_online' => $display->isOnline() && $display->refusal() === null,
            'refusal' => $display->refusal(),
        ];
    }

    /**
     * The URL to open on the television. Available only at the moment the token
     * is made, because nothing after that can read it back.
     */
    public function url(string $token): string
    {
        // The address the admin is on: it is the one their network already reaches.
        return request()->getSchemeAndHttpHost().'/display/'.$token;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function values(array $data): array
    {
        return array_intersect_key($data, array_flip([
            'location_id', 'department_id', 'display_name', 'display_type', 'layout', 'theme',
            'audio_enabled', 'audio_languages', 'is_active', 'expires_at',
        ]));
    }

    /** @param  array<string, mixed>  $data */
    private function syncDoctors(QueueDisplay $display, array $data): void
    {
        if (! array_key_exists('doctor_ids', $data)) {
            return;
        }

        $ids = array_values(array_unique(array_map('intval', $data['doctor_ids'] ?? [])));

        if ($display->display_type === QueueDisplay::DOCTOR && count($ids) !== 1) {
            throw new RuntimeException('A room display shows exactly one doctor.');
        }

        $sync = [];

        foreach ($ids as $order => $id) {
            $sync[$id] = ['sort_order' => $order];
        }

        $display->doctors()->sync($sync);
    }

    private function newToken(Organization $organization): string
    {
        return $organization->uuid.'-'.Str::random(self::SECRET_LENGTH);
    }

    private function hash(string $token): string
    {
        return hash('sha256', $token);
    }
}
