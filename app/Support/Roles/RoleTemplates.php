<?php

namespace App\Support\Roles;

use App\Models\Tenant\Role;
use App\Support\Modules\ModuleRegistry;

/**
 * The jobs a clinic or a pharmacy actually staffs, and what each one needs.
 *
 * Code is authoritative here, the same way ModuleRegistry is about
 * capabilities: what a receptionist does is a property of the software's
 * modules, not data an administrator invents from an empty grid of fifty
 * checkboxes.
 *
 * These are SEEDED AS REAL ROLES, one set per tenant, rather than being a
 * front-end list that fills in ticks and is then forgotten. The difference
 * matters: a seeded role can be assigned on the day an organization is
 * provisioned, it can be reported on, and `template_key` lets a later release
 * OFFER an update when a module ships new capabilities — instead of silently
 * changing what people already hold.
 *
 * Once seeded a role is ORDINARY. The owner renames it, re-ticks it or deletes
 * it exactly as they would one they wrote themselves; nothing here is a
 * protected class of role, and `template_key` is a memory of where it came
 * from rather than a rule about what it must stay.
 *
 * LAB TECHNICIAN NOW EXISTS. It was absent for as long as there was no lab
 * module — every capability such a role could have held would have been one
 * no route ever checked, and a permission screen that offers permissions
 * nothing honours is how a permission screen starts lying. The `laboratory`
 * module and its four routes are what made the role buildable.
 *
 * ACCOUNTANT NOW EXISTS. The billing module ships, and with it a separate
 * counter that raises invoices for the whole visit — consultation fee, custom
 * services, and (where the pharmacy sells) the medicine line — rather than
 * reading money off the pharmacy's own bills. The role holds `billing.*`
 * without holding anything a shop counter does.
 */
class RoleTemplates
{
    /**
     * Every template, in the order a role list should show them.
     *
     * Capabilities are filtered through the organization's entitlements when
     * a template is seeded, so a clinic that never bought pharmacy gets no
     * Pharmacist role at all — rather than one that grants nothing.
     *
     * @return list<array{
     *     key: string,
     *     name: string,
     *     slug: string,
     *     scope: string,
     *     icon: string,
     *     description: string,
     *     requires: list<string>,
     *     capabilities: list<string>,
     * }>
     */
    public static function all(): array
    {
        return [
            /*
             * The branch's own administrator.
             *
             * Branch-scoped, which is what makes "Rahul runs Gurgaon and
             * nothing else" true: the role applies at the branch its
             * membership names and nowhere else.
             *
             * `people.roles` is the interesting one — it lets a branch write
             * its OWN roles, drawing only on the modules that branch was
             * given, so a manager can shape their team without the owner
             * sitting there and without being able to invent a permission the
             * organization never had.
             *
             * `people.across_branches` and `people.assign_branch` are NOT
             * here, on purpose. The first lifts branch scope on staff
             * administration — the exact boundary this role exists to have.
             * The second is what makes cross-branch reach transitive: a
             * manager who could move people between branches would only have
             * to move themselves.
             */
            [
                'key' => 'branch_manager',
                'name' => 'Branch manager',
                'slug' => 'branch-manager',
                'scope' => Role::SCOPE_BRANCH,
                'icon' => 'ti ti-briefcase',
                'description' => 'Runs one branch — its team, its day and its stock.',
                'requires' => [],
                'capabilities' => [
                    'branches.view',
                    'people.view',
                    'people.create',
                    'people.edit',
                    'people.roles',
                    'customers.view',
                    'customers.create',
                    'customers.edit',
                    'appointments.view',
                    'appointments.book',
                    'appointments.queue',
                    'appointments.cancel',
                    'appointments.doctors',
                    'appointments.schedule',
                    'prescriptions.view',

                    /*
                     * Sees the lab's worklist and its results; does not work
                     * the bench. Running a branch means knowing what is
                     * outstanding, which is not the same as signing a result
                     * off — that stays with whoever ran the sample.
                     */
                    'laboratory.view',

                    'medicines.view',
                    'pharmacy.view',
                    'pharmacy.sell',
                    /* Covers the counter, so dispenses as well as sells. */
                    'pharmacy.dispense',
                    'pharmacy.inward',
                    'pharmacy.adjust',
                    'pharmacy.batches',
                    /* Runs the branch's books as well as its counter — all six,
                       including what it earns and what it owes in tax. */
                    'reports.sales',
                    'reports.purchases',
                    'reports.stock',
                    'reports.expiry',
                    'reports.profit',
                    'reports.gst',
                    'documents.view',
                    'documents.view_clinical',
                    'documents.upload',
                    'documents.generate',
                    /*
                     * Their own branch's letterhead, all three verbs — but
                     * NOT `documents.template_org`, which reaches every
                     * branch and locks fields. That is the boundary.
                     */
                    'documents.template_view',
                    'documents.template_edit',
                    'documents.template_publish',
                    /*
                     * The branch's own books. They may raise, edit, cancel and
                     * take payment on any invoice at their branch — running the
                     * counter as well as the shop is why the role exists.
                     * `billing.refund` too: a manager who cannot undo a wrong
                     * charge has to escalate the correction to head office.
                     * Not the organisation-scoped `manage_settings` /
                     * `manage_services`, which stay with head office so the
                     * numbering, prefix and named services read the same at
                     * every branch.
                     */
                    'billing.view',
                    'billing.create',
                    'billing.edit',
                    'billing.cancel',
                    'billing.collect_payment',
                    'billing.refund',
                    'communication.view',
                ],
            ],

            /*
             * A doctor administers nothing. Every screen they hold is their
             * own work — their queue, their patients, what they wrote.
             */
            [
                'key' => 'doctor',
                'name' => 'Doctor',
                'slug' => 'doctor',
                'scope' => Role::SCOPE_ORGANIZATION,
                'icon' => 'ti ti-stethoscope',
                'description' => 'Sees their own patients and their own day.',
                /*
                 * Organization-scoped on purpose, and it is the one exception
                 * in this list. A doctor sits wherever their timings put them;
                 * their branch reach comes from `doctor_locations` rather than
                 * from a membership, and pinning the login to one branch would
                 * hide the other branches' lists from the one person who has
                 * to work them.
                 */
                'requires' => ['appointments'],
                'capabilities' => [
                    'customers.view',
                    'appointments.view',
                    'appointments.queue',

                    /*
                     * The consultation is theirs, both ends of it. These two
                     * are what the receptionist deliberately does NOT hold —
                     * the whole point of splitting them out of
                     * `appointments.queue`.
                     */
                    'appointments.consult_start',
                    'appointments.consult_complete',

                    'prescriptions.view',
                    'prescriptions.write',

                    /*
                     * Orders tests and reads what comes back. Not
                     * `laboratory.process` or `.complete`: a doctor who could
                     * sign off their own order has removed the bench from the
                     * loop, and the result they act on would be one nobody
                     * ran.
                     */
                    'laboratory.view',
                    'laboratory.order',

                    'medicines.view',
                    'documents.view',
                    'documents.view_clinical',
                    'documents.upload',
                    /* Prints a prescription; does not design one. */
                    'documents.generate',
                    'documents.template_view',
                ],
            ],

            [
                'key' => 'receptionist',
                'name' => 'Receptionist',
                'slug' => 'receptionist',
                'scope' => Role::SCOPE_BRANCH,
                'icon' => 'ti ti-headset',
                'description' => 'Books patients in, runs the queue, keeps records up to date.',
                'requires' => ['appointments'],
                /*
                 * `documents.view` without `documents.view_clinical`: a desk
                 * files an ID proof and an insurance letter, and has no
                 * business reading a discharge summary. That split is the
                 * whole point of the documents module.
                 */
                /*
                 * `appointments.queue` and NOT `appointments.consult_*`.
                 *
                 * This is the boundary the whole workflow rests on. The desk
                 * checks patients in and calls them through; whether the
                 * doctor has started, and whether they have finished, is not
                 * theirs to assert. They still SEE all of it — the queue
                 * shows "With doctor", "Waiting for pharmacy", "Visit
                 * completed" — because running a waiting room means knowing
                 * where everybody is. Seeing is `appointments.view`.
                 *
                 * `laboratory.view` so the desk can answer "are my results
                 * back", which is the question they are asked all day. Not
                 * `.process` or `.complete`.
                 */
                'capabilities' => [
                    'branches.view',
                    'customers.view',
                    'customers.create',
                    'customers.edit',
                    'appointments.view',
                    'appointments.book',
                    'appointments.queue',
                    'laboratory.view',
                    'documents.view',
                    'documents.upload',
                    'documents.generate',
                    /*
                     * The desk sees a bill and can take payment — the same
                     * counter as check-in, so the same person answers "please
                     * pay for your visit". They do not raise, edit, cancel or
                     * refund; the software draws the invoice from the
                     * consultation, and corrections are the accountant's or
                     * the branch manager's.
                     */
                    'billing.view',
                    'billing.collect_payment',
                    'communication.view',
                ],
            ],

            /*
             * The bench.
             *
             * Branch-scoped: a lab is a room at a branch, and a technician at
             * Gurgaon has no business in Lucknow's worklist.
             *
             * Deliberately NARROW. They hold `customers.view` because a
             * sample has to be matched to a person, and `documents.upload`
             * because a scanned report is how half of all results actually
             * arrive. They do not hold `documents.view_clinical`: running a
             * blood count is not a reason to read somebody's discharge
             * summary, and that split is the documents module's whole point.
             *
             * No `laboratory.order` — ordering is the doctor's.
             */
            [
                'key' => 'lab_technician',
                'name' => 'Lab technician',
                'slug' => 'lab-technician',
                'scope' => Role::SCOPE_BRANCH,
                'icon' => 'ti ti-flask',
                'description' => 'Works the lab bench — takes on orders, enters results, signs them off.',
                'requires' => ['laboratory'],
                'capabilities' => [
                    'branches.view',
                    'customers.view',
                    'laboratory.view',
                    'laboratory.process',
                    'laboratory.complete',
                    'documents.view',
                    'documents.upload',
                ],
            ],

            [
                'key' => 'pharmacist',
                'name' => 'Pharmacist',
                'slug' => 'pharmacist',
                'scope' => Role::SCOPE_BRANCH,
                'icon' => 'ti ti-pill',
                'description' => 'Runs the counter, the shelf and what comes in.',
                'requires' => ['pharmacy'],
                /*
                 * `prescriptions.view` and `documents.view` — but NOT
                 * `documents.view_clinical`. A pharmacist reads the
                 * prescription they are dispensing, which is its own record;
                 * a scanned psychiatric summary attached to the same patient
                 * is not part of that job.
                 *
                 * `pharmacy.sale_cancel`, `.transfer`, `.stores`, `.reverse`
                 * and `.restore` are absent: cancelling a bill and reversing a
                 * dispensing change what the day is recorded as having been,
                 * and plenty of shops want those with somebody senior.
                 *
                 * The reports are split the same way the counter itself is:
                 * what moved (sales, purchases, stock, expiry) is a
                 * pharmacist's own day. What it MADE (profit) and what it
                 * OWES the tax office (GST) are the owner's or the branch
                 * manager's to read — this is the demonstration of why the
                 * six reports are six capabilities rather than one.
                 */
                'capabilities' => [
                    'branches.view',
                    'customers.view',
                    'medicines.view',
                    'prescriptions.view',
                    'pharmacy.view',
                    'pharmacy.sell',
                    'pharmacy.dispense',
                    'pharmacy.inward',
                    'pharmacy.adjust',
                    'pharmacy.batches',
                    'reports.sales',
                    'reports.purchases',
                    'reports.stock',
                    'reports.expiry',
                    'documents.view',
                    /* Bills and receipts come off this counter. */
                    'documents.generate',
                    /*
                     * The clinic's own invoice, for the medicine line the shop
                     * dispensed. Reading and taking payment only — a
                     * pharmacist is not raising a consultation charge, and
                     * cancelling a clinic invoice is a step senior to
                     * cancelling a shop bill (which stays under
                     * `pharmacy.sale_cancel`, held apart from `pharmacy.sell`
                     * on this same list).
                     */
                    'billing.view',
                    'billing.collect_payment',
                ],
            ],

            /*
             * The books.
             *
             * Branch-scoped: an accountant sits at a branch and reads that
             * branch's invoices. The organisation-wide numbering, prefix and
             * named services stay with head office.
             *
             * Deliberately narrow outside billing. They hold `customers.view`
             * because an invoice is somebody's, and `documents.generate` to
             * print a receipt or a bill from the letterhead. They do NOT hold
             * `documents.view_clinical` — reading a discharge summary is not
             * part of collecting money — and they do not hold
             * `appointments.consult_*` or any pharmacy action; billing is
             * about what was owed, not what was done or dispensed.
             */
            [
                'key' => 'accountant',
                'name' => 'Accountant',
                'slug' => 'accountant',
                'scope' => Role::SCOPE_BRANCH,
                'icon' => 'ti ti-calculator',
                'description' => 'Runs the branch\'s billing counter — invoices, payments and receipts.',
                'requires' => ['billing'],
                'capabilities' => [
                    'branches.view',
                    'customers.view',
                    'billing.view',
                    'billing.create',
                    'billing.edit',
                    'billing.cancel',
                    'billing.collect_payment',
                    'billing.refund',
                    'documents.view',
                    'documents.generate',
                ],
            ],

            /*
             * Head office. The one organization-scoped administrative role,
             * and the only place `people.across_branches` and
             * `people.assign_branch` belong — administering the whole network
             * is a thing an owner grants on purpose, not a side effect of
             * having no branch of one's own.
             */
            [
                'key' => 'head_office',
                'name' => 'Head office',
                'slug' => 'head-office',
                'scope' => Role::SCOPE_ORGANIZATION,
                'icon' => 'ti ti-users',
                'description' => 'Administers the whole network rather than one branch.',
                'requires' => [],
                'capabilities' => [
                    'branches.view',
                    'branches.create',
                    'branches.edit',
                    'branches.manage_manager',
                    'people.view',
                    'people.create',
                    'people.edit',
                    'people.across_branches',
                    'people.assign_branch',
                    'customers.view',
                    'customers.create',
                    'customers.edit',
                    'appointments.view',
                    'documents.view',
                    'documents.template_view',
                    'documents.template_edit',
                    'documents.template_publish',
                    /* Every branch's letterhead, and the locks on it. */
                    'documents.template_org',
                    /*
                     * The books, the way head office holds them: the two
                     * organisation-scoped keys that decide how EVERY branch
                     * bills — the trigger, tax, numbering prefix and terms;
                     * and the named services (Consultation, Injection…) an
                     * invoice pulls from. The four branch-scoped acts
                     * (view/create/edit/collect) come along by scope-lift, so
                     * head office can also work an invoice at any branch —
                     * this is deliberate, and mirrors how `people.view` at
                     * organisation scope reaches every branch's staff.
                     */
                    'billing.manage_settings',
                    'billing.manage_services',
                    'billing.view',
                    'billing.create',
                    'billing.edit',
                    'billing.cancel',
                    'billing.collect_payment',
                    'billing.refund',
                    'settings.audit',
                ],
            ],
        ];
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $template) {
            if ($template['key'] === $key) {
                return $template;
            }
        }

        return null;
    }

    /**
     * A template's capabilities, narrowed to what this organization can grant.
     *
     * @param  list<string>  $modules  the module keys the organization holds
     * @return list<string>
     */
    public static function capabilitiesFor(array $template, array $modules): array
    {
        $pool = ModuleRegistry::capabilitiesFor($modules);

        return array_values(array_intersect($template['capabilities'], $pool));
    }

    /**
     * Whether this organization can use this template at all.
     *
     * A Pharmacist role in a clinic with no pharmacy module would hold
     * nothing. Better not to create it: an empty role on the list reads as a
     * broken role rather than as a module nobody bought.
     *
     * @param  list<string>  $modules
     */
    public static function appliesTo(array $template, array $modules): bool
    {
        foreach ($template['requires'] as $required) {
            if (! in_array($required, $modules, true)) {
                return false;
            }
        }

        return self::capabilitiesFor($template, $modules) !== [];
    }
}
