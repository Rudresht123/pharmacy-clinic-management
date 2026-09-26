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
     * One of the six reports, at this store's branch.
     *
     * Its own capability per report (`reports.sales`, `reports.profit`, …) —
     * not `pharmacy.view`, which also opens the dashboard, the stock list and
     * settings. A cashier who may see what sold today has no structural need
     * to see what it cost, and this is what lets an owner staff the counter
     * with the first and keep the second for themselves.
     */
    public function viewReport(User $user, PharmacyStore $store, string $report): bool
    {
        return $this->mayAt($user, $store->location_id, "reports.{$report}");
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

    /** Sell at this store's counter — a bill with no prescription behind it. */
    public function sell(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.sell');
    }

    /**
     * Hand over what a doctor prescribed.
     *
     * The same endpoint as a counter sale and a different question, which is
     * why `pharmacy.dispense` has existed since the pharmacy shipped and was
     * checked by no route: there was nothing that could tell the two apart,
     * because a dispensing did not credit the prescription it carried. Now
     * that it does, the bill that moves a clinical record is the one this
     * asks about.
     *
     * Either key is enough. Somebody trusted to ring up a bill is not being
     * refused a prescription they are standing there holding, and plenty of
     * small shops staff one counter with one person; the split exists for the
     * clinics that want a dispensing pharmacist distinct from a cashier.
     */
    public function dispense(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.dispense')
            || $this->mayAt($user, $store->location_id, 'pharmacy.sell');
    }

    /**
     * Cancel a bill sold here.
     *
     * Its own capability, not the cashier's: cancelling puts stock back and
     * takes money off the day's takings, which is a supervisor's decision.
     */
    public function cancelSale(User $user, PharmacyStore $store): bool
    {
        return $this->mayAt($user, $store->location_id, 'pharmacy.sale_cancel');
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
