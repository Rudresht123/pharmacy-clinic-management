<?php

namespace App\Services\Messaging;

use App\Models\Tenant\Conversation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
use App\Models\Tenant\Location;
use App\Models\Tenant\User;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who is asking, as one side of a conversation.
 *
 * Resolved from the login, never from anything the client sends: a doctor is
 * the record their account points at, a patient the record EnsurePortalPatient
 * found. Every query in Messenger is narrowed through [scope], so there is no
 * code path that reads a conversation without first asking whose it is.
 */
final class Participant
{
    private function __construct(
        public readonly string $side,
        public readonly User $user,
        public readonly ?Doctor $doctor,
        public readonly ?Customer $customer,
        public readonly ?Location $branch = null,
    ) {}

    public static function doctor(User $user, Doctor $doctor): self
    {
        return new self(Conversation::DOCTOR, $user, $doctor, null);
    }

    public static function patient(User $user, Customer $customer): self
    {
        return new self(Conversation::PATIENT, $user, null, $customer);
    }

    /** The desk, writing as the branch rather than as any one doctor. */
    public static function frontDesk(User $user, Location $branch): self
    {
        return new self(Conversation::FRONT_DESK, $user, null, null, $branch);
    }

    public function isDoctor(): bool
    {
        return $this->side === Conversation::DOCTOR;
    }

    public function isFrontDesk(): bool
    {
        return $this->side === Conversation::FRONT_DESK;
    }

    public function name(): string
    {
        return match ($this->side) {
            Conversation::DOCTOR => (string) $this->doctor?->name,
            Conversation::FRONT_DESK => (string) $this->branch?->name,
            default => (string) $this->customer?->name,
        };
    }

    /** The column this side's own conversations are found by. */
    public function ownColumn(): string
    {
        return match ($this->side) {
            Conversation::DOCTOR => 'doctor_id',
            Conversation::FRONT_DESK => 'branch_id',
            default => 'customer_id',
        };
    }

    /** The id in [ownColumn] that is this participant's own. */
    public function ownId(): int
    {
        return (int) match ($this->side) {
            Conversation::DOCTOR => $this->doctor->getKey(),
            Conversation::FRONT_DESK => $this->branch->getKey(),
            default => $this->customer->getKey(),
        };
    }

    /** @param  Builder<Conversation>  $query */
    public function scope(Builder $query): Builder
    {
        return $query->where($this->ownColumn(), $this->ownId());
    }

    public function owns(Conversation $conversation): bool
    {
        return (int) $conversation->getAttribute($this->ownColumn()) === $this->ownId();
    }
}
