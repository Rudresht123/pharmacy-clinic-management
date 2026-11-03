<?php

namespace App\Services\Communication;

use App\Models\Platform\Organization;
use App\Models\Tenant\ActivityLog;
use App\Models\Tenant\AppointmentRescheduleRequest;
use App\Models\Tenant\AutomationRule;
use App\Models\Tenant\BranchMembership;
use App\Models\Tenant\CommunicationChannel;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\Role;
use App\Models\Tenant\User;
use App\Services\Permissions\Permission;
use App\Support\Communication\RuleRecipients;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Who a rule's message goes to, this time — the places it names
 * (RuleRecipients) turned into addresses and logins.
 *
 * Read at the moment of sending, never saved: the visit's doctor, the branch
 * manager and the people at the desk are whoever they are TODAY, and a rule
 * that froze them when it was written would go on telling somebody who left.
 *
 * ONLY ACTIVE STAFF. A login that was switched off, deleted, or belongs to a
 * patient is skipped without a word — a rule naming somebody who has since
 * gone must not stop everybody else being told — and the patient is reached
 * as the patient, on a patient rule, and never as "staff".
 */
class RecipientResolver
{
    /** Who answers reschedule requests is who counts as the desk. */
    private const DESK_CAPABILITY = 'appointments.reschedule';

    public function __construct(
        private readonly Permission $permission,
    ) {}

    public function resolve(
        Organization $organization,
        AutomationRule $rule,
        ?Model $subject,
        ?Customer $patient,
        ?int $locationId,
    ): Recipients {
        $byEmail = $rule->channel === CommunicationChannel::EMAIL;

        $lists = [RuleRecipients::TO => [], RuleRecipients::CC => [], RuleRecipients::BCC => []];
        $seen = [];
        $staff = [];
        $patientOn = false;
        $patientAddress = null;

        // To, then CC, then BCC: whoever is named twice keeps the higher.
        foreach (RuleRecipients::of($rule) as $field => $entries) {
            foreach ($entries as $entry) {
                foreach ($this->people($organization, (array) $entry, $subject, $patient, $locationId) as $person) {
                    if ($person['patient']) {
                        // Staff wording never reaches the patient.
                        if ($rule->isForStaff()) {
                            continue;
                        }

                        $patientOn = true;
                    } elseif ($person['user'] !== null) {
                        $staff[$person['user']->id] ??= $person['name'];
                    }

                    $address = filled($person['email']) ? mb_strtolower(trim((string) $person['email'])) : null;

                    if (! $byEmail || $address === null || isset($seen[$address])) {
                        continue;
                    }

                    $seen[$address] = true;
                    $lists[$field][$address] = $person['name'];

                    if ($person['patient']) {
                        $patientAddress = $address;
                    }
                }
            }
        }

        return new Recipients(
            patient: $patientOn,
            to: $lists[RuleRecipients::TO],
            cc: $lists[RuleRecipients::CC],
            bcc: $lists[RuleRecipients::BCC],
            staff: $byEmail ? [] : $staff,
            patientAddress: $patientAddress,
        );
    }

    /**
     * Everybody one entry on a rule stands for.
     *
     * @param  array<string, mixed>  $entry
     * @return list<array{patient: bool, user: User|null, email: string|null, name: string}>
     */
    private function people(Organization $organization, array $entry, ?Model $subject, ?Customer $patient, ?int $locationId): array
    {
        $id = (int) ($entry['id'] ?? 0);

        return match ($entry['type'] ?? null) {
            RuleRecipients::PATIENT => $patient === null ? [] : [[
                'patient' => true,
                'user' => null,
                // A family member added from the app has no address of their
                // own: whoever books for them is who hears about their visits.
                'email' => filled($patient->email) ? $patient->email : $patient->accountHolder()?->email,
                'name' => (string) $patient->name,
            ]],
            RuleRecipients::DOCTOR => $this->doctor($subject),
            RuleRecipients::CREATOR => $this->staff([$this->creatorOf($subject)]),
            RuleRecipients::BRANCH_MANAGER => $this->staff([$locationId === null ? null : Location::query()->find($locationId)?->manager]),
            RuleRecipients::FRONT_DESK => $this->staff($this->frontDesk($organization, $locationId)),
            RuleRecipients::ROLE => $this->staff($id > 0 ? $this->holding($id, $locationId) : []),
            RuleRecipients::USER => $this->staff([$id > 0 ? User::query()->find($id) : null]),
            RuleRecipients::EMAIL => filled($entry['address'] ?? null) ? [[
                'patient' => false,
                'user' => null,
                'email' => (string) $entry['address'],
                'name' => (string) $entry['address'],
            ]] : [],
            default => [],
        };
    }

    /**
     * The doctor on the record: their login for a push, and for email the
     * address the clinic keeps on the doctor themselves, their login's when
     * there is none.
     *
     * @return list<array{patient: bool, user: User|null, email: string|null, name: string}>
     */
    private function doctor(?Model $subject): array
    {
        $doctor = $subject !== null && method_exists($subject, 'doctor') ? $subject->doctor : null;

        if (! $doctor instanceof Doctor) {
            return [];
        }

        $login = $doctor->user;
        $login = $login instanceof User && $login->is_active ? $login : null;
        $email = filled($doctor->email) ? (string) $doctor->email : $login?->email;

        if ($login === null && blank($email)) {
            return [];
        }

        return [['patient' => false, 'user' => $login, 'email' => $email, 'name' => (string) $doctor->name]];
    }

    /**
     * Whoever made the record, as its history wrote it down.
     *
     * A reschedule request is the patient's own; who "booked it" is whoever
     * booked the visit it asks to move.
     */
    private function creatorOf(?Model $subject): ?User
    {
        if ($subject instanceof AppointmentRescheduleRequest) {
            $subject = $subject->appointment;
        }

        if ($subject === null) {
            return null;
        }

        $id = ActivityLog::query()
            ->where('entity_type', class_basename($subject))
            ->where('entity_id', $subject->getKey())
            ->where('event', 'created')
            ->orderBy('id')
            ->value('user_id');

        return $id === null ? null : User::query()->find($id);
    }

    /**
     * Everybody at the branch who answers reschedule requests — the desk by
     * what they may do, not by what their role happens to be called.
     *
     * @return iterable<User>
     */
    private function frontDesk(Organization $organization, ?int $locationId): iterable
    {
        if ($locationId === null) {
            return [];
        }

        return User::query()
            ->whereIn('id', BranchMembership::query()->where('location_id', $locationId)->select('user_id'))
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereNull('userable_type')
                ->orWhereNotIn('userable_type', [Doctor::class, Customer::class]))
            ->get()
            ->filter(fn (User $user) => $this->permission->allows($organization, $user, self::DESK_CAPABILITY, $locationId));
    }

    /**
     * Everybody holding the role named by [$roleId] at the branch. With no
     * branch, everybody who holds it anywhere.
     *
     * By NAME, because each branch keeps its own copy: a rule saying
     * "Receptionist" means the receptionist at the visit's branch, not the
     * one whose copy happened to be picked when the rule was written.
     *
     * @return iterable<User>
     */
    private function holding(int $roleId, ?int $locationId): iterable
    {
        $name = Role::query()->whereKey($roleId)->value('name');
        $roleIds = $name === null ? [] : Role::query()->where('name', $name)->pluck('id')->all();

        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $query) => $query
                ->whereIn('role_id', $roleIds)
                ->orWhereIn('id', BranchMembership::query()
                    ->whereIn('role_id', $roleIds)
                    ->when($locationId !== null, fn (Builder $memberships) => $memberships->where('location_id', $locationId))
                    ->select('user_id')))
            ->get();
    }

    /**
     * @param  iterable<User|null>  $users
     * @return list<array{patient: bool, user: User|null, email: string|null, name: string}>
     */
    private function staff(iterable $users): array
    {
        $people = [];

        foreach ($users as $user) {
            if (! $user instanceof User
                || ! $user->is_active
                || $user->role === User::PATIENT
                || $user->userable_type === Customer::class) {
                continue;
            }

            $people[] = ['patient' => false, 'user' => $user, 'email' => $user->email, 'name' => (string) $user->name];
        }

        return $people;
    }
}
