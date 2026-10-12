<?php

namespace App\Services\Messaging;

use App\Models\Tenant\Conversation;
use App\Models\Tenant\Customer;
use App\Models\Tenant\Doctor;
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
    ) {}

    public static function doctor(User $user, Doctor $doctor): self
    {
        return new self(Conversation::DOCTOR, $user, $doctor, null);
    }

    public static function patient(User $user, Customer $customer): self
    {
        return new self(Conversation::PATIENT, $user, null, $customer);
    }

    public function isDoctor(): bool
    {
        return $this->side === Conversation::DOCTOR;
    }

    public function name(): string
    {
        return (string) ($this->isDoctor() ? $this->doctor?->name : $this->customer?->name);
    }

    /** @param  Builder<Conversation>  $query */
    public function scope(Builder $query): Builder
    {
        return $this->isDoctor()
            ? $query->where('doctor_id', $this->doctor->getKey())
            : $query->where('customer_id', $this->customer->getKey());
    }

    public function owns(Conversation $conversation): bool
    {
        return $this->isDoctor()
            ? (int) $conversation->doctor_id === (int) $this->doctor->getKey()
            : (int) $conversation->customer_id === (int) $this->customer->getKey();
    }
}
