<?php

namespace App\Services\Portal;

use App\Models\Tenant\Appointment;
use App\Models\Tenant\Customer;
use App\Models\Tenant\FamilyMember;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * The people a patient looks after from the app.
 *
 * Adding somebody always opens a NEW patient record; there is no way to name
 * an existing one from a phone, because that would hand a patient somebody
 * else's history for the price of typing their number. For the same reason a
 * family member has no phone on their record: signing in matches a record by
 * its number, so a number typed in here could later land a stranger's own
 * sign-in on a record this family can read. They are reached through the
 * account holder instead (ChannelSender, PushManager).
 *
 * Every query starts from the patient, as PatientAppointments' do, so nothing
 * here can be asked about anybody else's family.
 */
class PatientFamily
{
    /** A family, not a way of opening patient records in bulk. */
    public const MAX_MEMBERS = 10;

    public function __construct(
        private readonly CustomerRepositoryInterface $customers,
    ) {}

    /** Oldest first, so the list keeps the order they were added in. */
    public function members(Customer $patient): Collection
    {
        return $this->of($patient)->oldest('id')->get();
    }

    /**
     * One of this patient's family, by the id of their patient record.
     *
     * @throws ModelNotFoundException when they are not in this family
     */
    public function find(Customer $patient, int $memberId): FamilyMember
    {
        return $this->of($patient)->where('member_id', $memberId)->firstOrFail();
    }

    /**
     * @param  array{name: string, relation: string, gender: string, date_of_birth?: string|null}  $data
     *
     * @throws RuntimeException with a sentence the patient can act on
     */
    public function add(Customer $patient, array $data): FamilyMember
    {
        $members = $this->members($patient);

        if ($members->count() >= self::MAX_MEMBERS) {
            throw new RuntimeException('You can add up to '.self::MAX_MEMBERS.' family members. Please ask the clinic to help with more.');
        }

        $name = trim($data['name']);

        // Tapping Save twice on a slow connection should not open two records.
        $already = $members->first(fn (FamilyMember $link) => mb_strtolower((string) $link->member->name) === mb_strtolower($name)
            && $link->member->date_of_birth?->toDateString() === ($data['date_of_birth'] ?? null));

        if ($already !== null) {
            throw new RuntimeException("{$name} is already in your family.");
        }

        $link = DB::connection('organization')->transaction(function () use ($patient, $data, $name) {
            $member = $this->customers->create([
                'name' => $name,
                'gender' => $data['gender'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
                'is_active' => true,
            ]);

            return FamilyMember::query()->create([
                'customer_id' => $patient->getKey(),
                'member_id' => $member->getKey(),
                'relation' => $data['relation'],
            ]);
        });

        return $link->load('member.photograph');
    }

    /** @param  array{name: string, relation: string, gender: string, date_of_birth?: string|null}  $data */
    public function update(FamilyMember $link, array $data): FamilyMember
    {
        DB::connection('organization')->transaction(function () use ($link, $data) {
            $link->member->update([
                'name' => trim($data['name']),
                'gender' => $data['gender'],
                'date_of_birth' => $data['date_of_birth'] ?? null,
            ]);

            $link->update(['relation' => $data['relation']]);
        });

        return $link;
    }

    /**
     * Take somebody out of the family. Their patient record stays: it is the
     * clinic's, with whatever history it has.
     *
     * Not while they have a visit ahead: the app that booked it is the only
     * way the patient could call it off, and no number on the record for the
     * clinic to ring about it either.
     *
     * @throws RuntimeException with a sentence the patient can act on
     */
    public function remove(FamilyMember $link): void
    {
        $booked = Appointment::query()
            ->where('customer_id', $link->member_id)
            ->whereIn('status', PatientAppointments::LIVE)
            ->whereDate('appointment_date', '>=', today())
            ->exists();

        if ($booked) {
            throw new RuntimeException("{$link->member->name} has a visit booked. Please cancel it first.");
        }

        $link->delete();
    }

    private function of(Customer $patient): Builder
    {
        return FamilyMember::query()
            ->with('member.photograph')
            ->where('customer_id', $patient->getKey())
            // A record the clinic has since removed is not somebody to book for.
            ->whereHas('member');
    }
}
