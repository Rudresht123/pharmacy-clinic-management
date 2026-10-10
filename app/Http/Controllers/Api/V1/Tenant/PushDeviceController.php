<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\FirebaseDevice;
use App\Models\Platform\Organization;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\User;
use App\Services\Push\DeviceRegistry;
use App\Services\Push\PushManager;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * The phones that can receive this clinic's App notifications.
 *
 * The registry itself lives in the master database (`firebase_devices`), and
 * the people in the clinic's own. EVERY QUERY HERE NAMES THE ORGANIZATION
 * FIRST: user ids are numbered per tenant, so user 7 exists in every clinic,
 * and a list keyed by user id alone would show somebody else's patients.
 *
 * The FCM token never leaves the server. It is what Firebase delivers to, and
 * the screen has no use for it beyond telling two phones apart — which the
 * last few characters do.
 *
 * @group Tenant > Devices & Push
 */
class PushDeviceController extends BaseApiController
{
    private const AUDIENCES = ['patients', 'staff'];

    public function __construct(
        private readonly DeviceRegistry $devices,
        private readonly PushManager $push,
    ) {}

    /**
     * A page of this clinic's phones, newest activity first, with who each
     * one belongs to — and the totals the figures above the list read.
     */
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'audience' => ['nullable', Rule::in(self::AUDIENCES)],
            'platform' => ['nullable', Rule::in(FirebaseDevice::PLATFORMS)],
            'status' => ['nullable', Rule::in(['active', 'inactive'])],
            'search' => ['nullable', 'string', 'max:100'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:50'],
            'days' => ['nullable', 'integer', Rule::in([7, 30, 90])],
        ]);

        $organization = $this->organization($request);

        $query = $this->registry($organization)
            ->when($validated['platform'] ?? null, fn (Builder $q, string $platform) => $q->where('platform', $platform))
            ->when(($validated['status'] ?? null) === 'active', fn (Builder $q) => $q->where('is_active', true))
            ->when(($validated['status'] ?? null) === 'inactive', fn (Builder $q) => $q->where('is_active', false));

        if (isset($validated['audience'])) {
            $patients = $this->patientLogins($this->registry($organization)->distinct()->pluck('user_id')->all());

            $validated['audience'] === 'patients'
                ? $query->whereIn('user_id', $patients)
                : $query->whereNotIn('user_id', $patients);
        }

        $search = trim((string) ($validated['search'] ?? ''));

        if ($search !== '') {
            $like = '%'.addcslashes($search, '%_\\').'%';
            $people = $this->usersMatching($like);

            $query->where(fn (Builder $q) => $q
                ->whereIn('user_id', $people)
                ->orWhere('device_id', 'ilike', $like)
                ->orWhere('app_version', 'ilike', $like));
        }

        $page = $query
            ->orderByDesc('is_active')
            ->orderByRaw('last_seen_at DESC NULLS LAST')
            ->orderByDesc('id')
            ->paginate((int) ($validated['per_page'] ?? 10));

        $people = $this->people(collect($page->items())->pluck('user_id')->unique()->all());

        return response()->json([
            'data' => collect($page->items())->map(fn (FirebaseDevice $device) => $this->row($device, $people))->all(),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
                'summary' => $this->summary($organization, (int) ($validated['days'] ?? 30)),
            ],
        ]);
    }

    /**
     * The people a notification can be sent to right now: anybody in this
     * clinic with at least one live phone. What the "Send to" picker lists.
     */
    public function recipients(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        $organization = $this->organization($request);

        $counts = $this->registry($organization)
            ->where('is_active', true)
            ->selectRaw('user_id, count(*) as devices')
            ->groupBy('user_id')
            ->limit(1000)
            ->pluck('devices', 'user_id');

        $search = trim((string) ($validated['search'] ?? ''));

        $users = User::query()
            ->with('userable')
            ->whereIn('id', $counts->keys()->all())
            ->where('is_active', true)
            ->when($search !== '', fn (Builder $q) => $q->whereIn('id', $this->usersMatching('%'.addcslashes($search, '%_\\').'%')))
            ->orderBy('name')
            ->limit(50)
            ->get();

        $me = Auth::guard('web')->id();

        return $this->ok($users->map(fn (User $user) => $this->person($user) + [
            'devices' => (int) $counts[$user->getKey()],
            'is_me' => $user->getKey() === $me,
        ])->values()->all());
    }

    /**
     * Stop sending to one phone. Deactivated rather than deleted: the row is
     * how support answers "why did my notifications stop", and the app puts
     * it back on its own the next time somebody signs in there.
     */
    public function destroy(Request $request, int $device): JsonResponse
    {
        $row = $this->registry($this->organization($request))->whereKey($device)->first();

        // Another clinic's phone answers exactly like no phone at all.
        abort_if($row === null, 404);

        $row->forceFill(['is_active' => false])->save();

        return $this->noContent('That phone will no longer get notifications.');
    }

    /**
     * A notification to one person, typed here rather than from a template.
     *
     * Only to somebody in this clinic with a live phone, and only while the
     * channel is switched on — unless it is the sender's own phone, which is
     * how somebody checks the wiring before switching it on. Logged as a test
     * so it never counts towards the channel's figures.
     */
    public function send(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer'],
            'title' => ['required', 'string', 'max:120'],
            'body' => ['required', 'string', 'max:500'],
        ], [
            'user_id.required' => 'Choose who to send it to.',
            'title.required' => 'Give the notification a title.',
            'body.required' => 'Write the message.',
        ]);

        $organization = $this->organization($request);
        $actor = Auth::guard('web')->user();

        $user = User::query()->with('userable')->whereKey($validated['user_id'])->where('is_active', true)->first();

        if ($user === null) {
            throw ValidationException::withMessages(['user_id' => 'That person is not in this clinic.']);
        }

        if ($this->devices->tokensFor($organization, [(int) $user->getKey()]) === []) {
            throw ValidationException::withMessages([
                'user_id' => 'No phone is registered for app notifications for this person yet.',
            ]);
        }

        $own = $actor !== null && $user->getKey() === $actor->getKey();

        if (! $own && ! CommunicationChannel::forChannel(CommunicationChannel::PUSH)->is_connected) {
            throw ValidationException::withMessages([
                'user_id' => 'Switch App notifications on before notifying anybody else.',
            ]);
        }

        $customer = $user->userable instanceof Customer ? $user->userable : null;

        $this->push->queueToUsers(
            $organization,
            [(int) $user->getKey()],
            (string) $user->name,
            $validated['title'],
            $validated['body'],
            ['type' => 'test'],
            [
                'template_name' => 'Test notification',
                'subject' => mb_substr($validated['title'], 0, 255),
                'is_test' => true,
                'customer_id' => $customer?->getKey(),
                'recipient_name' => $user->name,
                'created_by' => $actor?->getKey(),
            ],
        );

        return $this->ok(['recipient' => $user->name], "Notification sent to {$user->name}.");
    }

    private function organization(Request $request): Organization
    {
        $organization = $request->attributes->get('tenant.organization');

        abort_unless($organization instanceof Organization, 401);

        return $organization;
    }

    /** This clinic's rows of the registry, and nobody else's. */
    private function registry(Organization $organization): Builder
    {
        return FirebaseDevice::query()->where('organization_id', $organization->getKey());
    }

    /**
     * Which of these logins are patients' — the ones a portal sign-in made.
     *
     * @param  list<int>  $userIds
     * @return list<int>
     */
    private function patientLogins(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        return User::query()
            ->whereIn('id', $userIds)
            ->where('userable_type', Customer::class)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * Logins whose name or email, or whose patient's code or phone, looks
     * like the search.
     *
     * @return list<int>
     */
    private function usersMatching(string $like): array
    {
        return User::query()
            ->where(fn (Builder $q) => $q
                ->where('name', 'ilike', $like)
                ->orWhere('email', 'ilike', $like)
                // A patient's code and phone are on the patient, not the login.
                ->orWhereHasMorph('userable', [Customer::class], fn (Builder $c) => $c
                    ->where('code', 'ilike', $like)
                    ->orWhere('phone', 'ilike', $like)))
            ->limit(500)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, User>
     */
    private function people(array $userIds): Collection
    {
        return $userIds === []
            ? collect()
            : User::query()->with('userable')->whereIn('id', $userIds)->get()->keyBy('id');
    }

    /** @return array{id: int, name: string, kind: string, code: string|null, role: string|null} */
    private function person(User $user): array
    {
        $patient = $user->userable instanceof Customer;

        return [
            'id' => (int) $user->getKey(),
            'name' => (string) $user->name,
            'kind' => $patient ? 'patient' : 'staff',
            'code' => $patient ? $user->userable->code : null,
            'role' => $patient ? null : $user->role,
        ];
    }

    /**
     * @param  Collection<int, User>  $people
     * @return array<string, mixed>
     */
    private function row(FirebaseDevice $device, Collection $people): array
    {
        $user = $people->get($device->user_id);

        return [
            'id' => (int) $device->getKey(),
            'device_id' => $device->device_id,
            'platform' => $device->platform,
            'app_version' => $device->app_version,
            'is_active' => (bool) $device->is_active,
            'last_seen_at' => $device->last_seen_at?->toIso8601String(),
            'registered_at' => $device->created_at?->toIso8601String(),
            'token_hint' => '…'.mb_substr((string) $device->getAttribute('fcm_token'), -6),
            'user' => $user !== null
                ? $this->person($user)
                : ['id' => (int) $device->user_id, 'name' => 'Removed login', 'kind' => 'staff', 'code' => null, 'role' => null],
        ];
    }

    /**
     * How many phones, and how that moved over the period: live phones now
     * against the live phones that were already registered when it began.
     *
     * @return array{active: int, total: int, android: int, ios: int, change: float|null}
     */
    private function summary(Organization $organization, int $days): array
    {
        $active = $this->registry($organization)->where('is_active', true);

        $count = (clone $active)->count();
        $before = (clone $active)->where('created_at', '<', Carbon::now()->subDays($days))->count();

        return [
            'active' => $count,
            'total' => $this->registry($organization)->count(),
            'android' => (clone $active)->where('platform', 'android')->count(),
            'ios' => (clone $active)->where('platform', 'ios')->count(),
            'change' => $before > 0 ? round((($count - $before) / $before) * 100, 1) : null,
        ];
    }
}
