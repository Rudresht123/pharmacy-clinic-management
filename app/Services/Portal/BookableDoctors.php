<?php

namespace App\Services\Portal;

use App\Models\Platform\Organization;
use App\Models\Tenant\Doctor;
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

    /** @var array<int, true>|null branches open to booking, resolved once */
    private ?array $bookable = null;

    public function __construct(
        private readonly DoctorRepositoryInterface $doctors,
        private readonly BookingService $booking,
        private readonly Permission $permission,
    ) {}

    /** Active doctors with at least one active sitting, optionally searched. */
    public function search(?string $term = null, ?string $specialisation = null): Collection
    {
        $term = trim((string) $term);

        return $this->doctors->listing('active')
            ->with(['department', 'photograph', 'schedules' => fn ($query) => $query
                ->where('is_active', true)
                ->with('location:id,name')])
            ->whereHas('schedules', fn (Builder $query) => $query->where('is_active', true))
            ->when($term !== '', fn (Builder $query) => $query->where(
                fn (Builder $inner) => $inner
                    ->where('name', 'ilike', "%{$term}%")
                    ->orWhere('specialisation', 'ilike', "%{$term}%")
            ))
            ->when(filled($specialisation), fn (Builder $query) => $query->where('specialisation', $specialisation))
            ->orderBy('name')
            ->get();
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
     * The first day, within the next week, this doctor has a free time.
     *
     * @return array{date: string, first_slot: string}|null
     */
    public function nextAvailable(Organization $organization, Doctor $doctor): ?array
    {
        $day = today();

        for ($i = 0; $i < self::NEXT_AVAILABLE_WITHIN; $i++, $day = $day->copy()->addDay()) {
            $sessions = $this->openSlots($organization, $doctor, $day);

            if ($sessions !== []) {
                return ['date' => $day->toDateString(), 'first_slot' => $sessions[0]['slots'][0]];
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
