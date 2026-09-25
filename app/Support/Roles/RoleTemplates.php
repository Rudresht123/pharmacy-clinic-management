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
 * WHAT IS DELIBERATELY ABSENT: Lab Technician and Accountant. Both were asked
 * for, and neither can be built — there is no lab module and no billing
 * module, so every capability such a role could hold would be one no route
 * will ever check. A permission screen that offers permissions nothing honours
 * is how a permission screen starts lying.
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
                    'medicines.view',
                    'pharmacy.view',
                    'pharmacy.sell',
                    'pharmacy.inward',
                    'pharmacy.adjust',
                    'pharmacy.batches',
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
                    'prescriptions.view',
                    'prescriptions.write',
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
                'capabilities' => [
                    'branches.view',
                    'customers.view',
                    'customers.create',
                    'customers.edit',
                    'appointments.view',
                    'appointments.book',
                    'appointments.queue',
                    'documents.view',
                    'documents.upload',
                    'documents.generate',
                    'communication.view',
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
                    'documents.view',
                    /* Bills and receipts come off this counter. */
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
