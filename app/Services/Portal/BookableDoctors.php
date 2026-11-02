<?php

namespace App\Services\Portal;

use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\DoctorSchedule;
use App\Models\Tenant\Location;
use App\Repositories\Tenant\Contracts\DoctorRepositoryInterface;
use App\Services\Opd\BookingService;
use App\Services\Permissions\Permission;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Carbon;

/**
 * Which doctors a patient can book, and when.
 *
 * A thin layer over what the desk already uses — DoctorRepository for who,
 * BookingService for when — adding only the rules that are about a patient
 * booking from home rather than a receptionist booking at a counter:
 *
 *   only branches that are open and run OPD are offered, because the desk
 *   knows not to book a closed branch and a patient cannot;
 *   a time earlier today is not offered, because it has already happened;
 *   bookings open DAYS_AHEAD days out and no further.
 */
class BookableDoctors
{
    public const DAYS_AHEAD = 30;

    /** How far "next available" looks before saying nothing. */
    public const NEXT_AVAILABLE_WITHIN = 7;

    /** What a doctor's card and the booking page say about each branch. */
    public const LOCATION_COLUMNS = 'location:id,name,address,city';

    /** @var array<int, true>|null branches open to booking, resolved once */
    private ?array $bookable = null;

    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly BookingService $booking,
        private readonly Permission $permission,
    ) {}

    /** Active doctors with at least one active sitting, optionally searched or at one branch. */
    public function search(?string $term = null, ?string $specialisation = null, ?int $locationId = null): Collection
    {
        $term = trim((string) $term);

        return $this->doctors->listing('active')
            ->with(['department', 'photograph', 'postings', 'schedules' => fn ($query) => $query
                ->where('is_active', true)
                ->with(self::LOCATION_COLUMNS)])
            ->whereHas('schedules', fn (Builder $query) => $query->where('is_active', true))
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('specialisation', 'ilike', "%{$term}%")
            ))
            ->when(filled($specialisation), fn (Builder $query) => $query->where('specialisation', $specialisation))
            ->when($locationId !== null, fn (Builder $query) => $query->whereHas(
                'schedules',
                fn (Builder $inner) => $inner->where('is_active', true)->where('location_id', $locationId),
            ))
            ->orderBy('name')
            ->get();
    }

    /**
     * Whether this doctor has a weekly sitting at all — the same rule
     * `search()` filters the list by.
     *
     * A one-off extra session (added for the desk, not for patients) can
     * still produce open slots in `openSlots()`/`offers()` with no weekly
     * row behind it at all. Without this check, a doctor invisible in the
     * list stays reachable, and bookable, by id — a stale card, a deep
     * link, a favourite.
     */
    public function hasSchedule(Doctor $doctor): bool
    {
        return $doctor->schedules()->where('is_active', true)->exists();
    }

    /**
     * The specialities there are doctors for, for the filter chips.
     *
     * @return list<string>
     */
    public function specialisations(): array
    {
        return $this->search()
            ->pluck('specialisation')
            ->filter()
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    /**
     * The branches a patient can choose between, for the filter on the doctors
     * list: open, running OPD, and with a doctor sitting there.
     *
     * @return list<array{id: int, name: string, address: ?string}>
     */
    public function branches(Organization $organization): array
    {
        return $this->search()
            ->flatMap(fn (Doctor $doctor) => $doctor->schedules->pluck('location'))
            ->filter(fn (?Location $location) => $location !== null && $this->isBookable($organization, (int) $location->id))
            ->unique('id')
            ->sortBy('name')
            ->map(fn (Location $location) => [
                'id' => (int) $location->id,
                'name' => (string) $location->name,
                'address' => $location->addressLine(),
            ])
            ->values()
            ->all();
    }

    /**
     * Drop a doctor's loaded sittings at branches a patient cannot book, so
     * that the branches and days their card lists are ones the booking page
     * can actually offer — a closed branch is not a choice.
     */
    public function withoutClosedBranches(Organization $organization, Doctor $doctor): Doctor
    {
        return $doctor->setRelation('schedules', $doctor->schedules
            ->filter(fn (DoctorSchedule $schedule) => $this->isBookable($organization, (int) $schedule->location_id))
            ->values());
    }

    /**
     * The free times on a date, by sitting, at branches a patient may book.
     *
     * @return list<array{location_id: int, location_name: ?string, name: ?string, starts_at: string, ends_at: string, slots: list<string>}>
     */
    public function openSlots(Organization $organization, Doctor $doctor, Carbon $date): array
    {
        $isToday = $date->isToday();
        $now = now()->format('H:i');

        $sessions = [];

        foreach ($this->booking->openSlots($doctor, $date) as $session) {
            if (! $this->isBookable($organization, (int) $session['location_id'])) {
                continue;
            }

            $slots = $isToday
                ? array_values(array_filter($session['slots'], fn (string $slot) => $slot > $now))
                : $session['slots'];

            if ($slots === []) {
                continue;
            }

            $sessions[] = [
                'location_id' => (int) $session['location_id'],
                'location_name' => $session['location_name'] ?? null,
                'name' => $session['name'] ?? null,
                'starts_at' => $session['starts_at'],
                'ends_at' => $session['ends_at'],
                'slots' => $slots,
                'booked_slots' => array_values($session['taken'] ?? []),
            ];
        }

        return $sessions;
    }

    /**
     * The first day, within the next week, this doctor has a free time — at
     * one branch when asked, and saying which so the booking page opens there.
     *
     * @return array{date: string, first_slot: string, location_id: int}|null
     */
    public function nextAvailable(Organization $organization, Doctor $doctor, ?int $locationId = null): ?array
    {
        $day = today();

        for ($i = 0; $i < self::NEXT_AVAILABLE_WITHIN; $i++, $day = $day->copy()->addDay()) {
            $sessions = array_values(array_filter(
                $this->openSlots($organization, $doctor, $day),
                fn (array $session) => $locationId === null || $session['location_id'] === $locationId,
            ));

            if ($sessions !== []) {
                return [
                    'date' => $day->toDateString(),
                    'first_slot' => $sessions[0]['slots'][0],
                    'location_id' => $sessions[0]['location_id'],
                ];
            }
        }

        return null;
    }

    /** Whether a time is on offer — the same answer the patient was shown. */
    public function offers(Organization $organization, Doctor $doctor, Carbon $date, int $locationId, string $slot): bool
    {
        foreach ($this->openSlots($organization, $doctor, $date) as $session) {
            if ($session['location_id'] === $locationId && in_array($slot, $session['slots'], true)) {
                return true;
            }
        }

        return false;
    }

    /** Open, and running OPD. Worked out once per request. */
    private function isBookable(Organization $organization, int $locationId): bool
    {
        if ($this->bookable === null) {
            $this->bookable = [];

            foreach (Location::query()->where('is_active', true)->pluck('id') as $id) {
                if ($this->permission->hasModule($organization, 'appointments', (int) $id)) {
                    $this->bookable[(int) $id] = true;
                }
            }
        }

        return isset($this->bookable[$locationId]);
    }
}
