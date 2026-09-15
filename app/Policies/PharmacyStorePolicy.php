<?php

namespace App\Policies;

use App\Models\Tenant\PharmacyStore;
use App\Models\Tenant\User;
use App\Policies\Concerns\AsksAboutBranch;

/**
 * May this person touch this store?
 *
 * The first Policy in the project, and deliberately not a second permission
 * system: every answer is the existing one, asked about the store's branch
 * (see AsksAboutBranch). The route's `permission:` middleware has already
 * asked about the acting branch; this asks about the store's, which is the
 * one that matters for a record that lives at a particular branch.
 *
 * Discovered by name (App\Models\Tenant\PharmacyStore → this class), and
 * called through Gate::forUser() with the tenant user, because the
 * application's default guard is the platform's.
 */
class PharmacyStorePolicy
{
    use AsksAboutBranch;

    /** Adding a store at a branch. */
    public function create(User $user, int $locationId): bool
    {
        return $this->mayAt($user, $locationId, 'pharmacy.stores');
    }

    public function view(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.view');
    }

    /**
     * What the store holds of a medicine — asked by a doctor choosing what to
     * prescribe, who holds `medicines.view` rather than `pharmacy.view`.
     */
    public function checkAvailability(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'medicines.view');
    }

    public function update(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.stores');
    }

    public function delete(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.stores');
    }

    public function restore(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.restore');
    }

    /** Which medicines the store stocks, and their levels. */
    public function configure(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.stores');
    }

    /** Receive goods into this store. */
    public function inward(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.inward');
    }

    /** Cancel a goods received note, or adjust stock, here. */
    public function adjust(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.adjust');
    }

    /**
     * Move stock out of or into this store. A transfer asks it of both
     * stores, which is what "needs access to both branches" means.
     */
    public function transfer(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.transfer');
    }
}
