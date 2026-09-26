<?php

namespace App\Support\Modules;

/**
 * Every module the software has, and what each one lets somebody do.
 *
 * Code is authoritative here, the same way App\Support\Fields\FieldRegistry
 * is authoritative about fields: a module's capabilities are a property of
 * the code that implements them, not data an administrator can invent. The
 * `modules` table is a synced catalogue of this list — it exists so bindings
 * can point at a row, and so a module can be retired platform-wide — and
 * `organization_modules` stores only which organization has which.
 *
 * The capabilities are the point, not decoration. Permission is decided at
 * three levels, and this list is the vocabulary all three speak:
 *
 *   1. The super admin sells a module to an organization
 *      (`organization_modules`, master database).
 *   2. The owner decides which branches use it
 *      (`location_modules`, tenant database).
 *   3. The owner puts capabilities on roles, and roles on staff
 *      (`roles` / `role_capabilities`, tenant database).
 *
 * App\Services\Permissions\Permission asks them in that order. The pool an
 * owner may distribute at level 3 is exactly the capabilities of the modules
 * won at level 1 — an unsold module's capabilities do not appear on the role
 * screen at all, rather than appearing and being denied.
 *
 * Capability keys are prefixed with their module's key and are never reused
 * across modules — a permission has to say which module granted it, or
 * revoking a module cannot know what to take away.
 */
class ModuleRegistry
{
    public const GROUP_FOUNDATION = 'foundation';

    public const GROUP_PHARMACY = 'pharmacy';

    public const GROUP_CLINICAL = 'clinical';

    public const GROUP_COMMERCE = 'commerce';

    public const GROUP_COMMUNICATION = 'communication';

    /**
     * Where a capability can mean anything.
     *
     * `settings.manage` changes what every branch calls a patient — there is
     * no version of it that applies at one branch and not another, so putting
     * it on a branch role would be offering a choice the software cannot
     * honour. `customers.view` is the opposite: it means "here", and a
     * receptionist holding it at Lucknow has no business reading Delhi's book.
     *
     * The role screen filters by this, so an organization-scoped capability is
     * never offered on a branch role — not shown greyed out, not shown at all.
     */
    public const SCOPE_ORGANIZATION = 'organization';

    public const SCOPE_BRANCH = 'branch';

    /** Both, in the order a role screen should show them. */
    public const SCOPES = [self::SCOPE_ORGANIZATION, self::SCOPE_BRANCH];

    /**
     * @return list<array{
     *     key: string,
     *     name: string,
     *     description: string,
     *     icon: string,
     *     group: string,
     *     is_core: bool,
     *     requires?: list<string>,
     *     capabilities: list<array{key: string, name: string}>,
     * }>
     */
    public static function all(): array
    {
        return [
            /*
            |------------------------------------------------------------
            | Core — every organization has these, always.
            |------------------------------------------------------------
            |
            | Marked is_core so the binding screen cannot take them away.
            | An organization with no branches and no people is not a
            | cheaper organization, it is a broken one, and a super admin
            | should not be able to produce that state by mistake.
            */
            [
                'key' => 'branches',
                'name' => 'Branches',
                'description' => 'The stores, warehouses and sites an organization operates.',
                'icon' => 'ti ti-building-store',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                /*
                 * Creating, editing and removing are three capabilities, not
                 * one. "Can add a branch but not delete one" is an ordinary
                 * thing to want, and a single `manage` could not express it —
                 * an owner delegating any of the three had to delegate all
                 * three. The same split runs through every module below.
                 */
                'capabilities' => [
                    ['key' => 'branches.view', 'name' => 'View branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.create', 'name' => 'Add branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.edit', 'name' => 'Edit branches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'branches.delete', 'name' => 'Remove branches', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * Who runs a branch — organization-scoped, and held apart
                     * from `branches.edit` on purpose.
                     *
                     * Editing a branch changes its address and its opening
                     * hours. Naming its manager decides who administers the
                     * people there, so a manager holding it could appoint
                     * themselves at another branch, or appoint somebody who
                     * would then appoint them back. It belongs with the owner
                     * and with head office, and nowhere else.
                     */
                    ['key' => 'branches.manage_manager', 'name' => 'Appoint and change branch managers', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
            [
                'key' => 'people',
                'name' => 'People',
                'description' => 'The staff who sign in, and the branch each one works at.',
                'icon' => 'ti ti-users-group',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'people.view', 'name' => 'View staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.create', 'name' => 'Add staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.edit', 'name' => 'Edit staff', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'people.delete', 'name' => 'Remove staff', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * The four above reach the holder's own branch. This one
                     * lifts that, and is what organization HR holds rather
                     * than a branch manager.
                     *
                     * A capability rather than something inferred from having
                     * no branch: administering the whole network is a thing an
                     * owner decides to grant, not a side effect of a column
                     * being null.
                     */
                    ['key' => 'people.across_branches', 'name' => 'Manage staff at every branch', 'scope' => self::SCOPE_ORGANIZATION],

                    /*
                     * Held apart from the four above, and organization-scoped
                     * on purpose: moving somebody between branches is what
                     * makes cross-branch reach transitive. A branch manager
                     * who could do it would only have to move themselves.
                     */
                    ['key' => 'people.assign_branch', 'name' => 'Assign staff to branches', 'scope' => self::SCOPE_ORGANIZATION],

                    /*
                     * Lets a branch write its OWN roles, from the modules that
                     * branch was given. Branch-scoped, so a manager holding it
                     * at Lucknow writes Lucknow's roles and nobody else's.
                     *
                     * The owner never needs it — they bypass roles entirely —
                     * and it cannot widen anything: the pool a branch may draw
                     * from is what the organization switched on there.
                     */
                    ['key' => 'people.roles', 'name' => 'Write roles for this branch', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'customers',
                'name' => 'Customers & Patients',
                'description' => 'One record per person served, shared by every branch.',
                'icon' => 'ti ti-users',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'customers.view', 'name' => 'View customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.create', 'name' => 'Add customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.edit', 'name' => 'Edit customers', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'customers.delete', 'name' => 'Remove customers', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'settings',
                'name' => 'Configuration',
                'description' => 'Field settings and what the organization calls each record.',
                'icon' => 'ti ti-adjustments',
                'group' => self::GROUP_FOUNDATION,
                'is_core' => true,
                'capabilities' => [
                    ['key' => 'settings.manage', 'name' => 'Change field settings and naming', 'scope' => self::SCOPE_ORGANIZATION],
                    ['key' => 'settings.audit', 'name' => 'Read the organization-wide activity log', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],

            /*
            |------------------------------------------------------------
            | Sold separately — bound per organization.
            |------------------------------------------------------------
            |
            | A module belongs here once its screens exist, or once it is
            | the next phase of a plan that is already settled. Modules
            | invented ahead of any of that were removed: a catalogue row
            | an administrator can switch on that then does nothing is a
            | promise the software does not keep, and it puts capabilities
            | on the role screen that no route will ever ask about.
            */
            [
                'key' => 'appointments',
                'name' => 'Appointments',
                'description' => 'Doctors, their sittings, and the OPD queue.',
                'icon' => 'ti ti-calendar-event',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                /*
                 * Split along the lines a busy desk actually draws. Taking a
                 * booking and calling the next patient in are what everybody
                 * at the desk does all day; cancelling one and marking a
                 * no-show change what the day is recorded as having been, and
                 * plenty of clinics want those with somebody senior.
                 *
                 * Who the doctors are is a separate question again from when
                 * they sit — the first changes rarely, the second every time
                 * somebody takes leave.
                 */
                'capabilities' => [
                    ['key' => 'appointments.view', 'name' => 'View doctors, availability and the queue', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.book', 'name' => 'Book appointments and take walk-ins', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * THE DESK'S KEY, and it no longer reaches the
                     * consultation.
                     *
                     * It used to be named "Check in, call through and
                     * complete", and that third verb was the problem: one
                     * capability let whoever ran the queue start AND finish a
                     * doctor's consultation, so the receptionist's screen
                     * carried a Done button that closed a clinical record.
                     * Reception runs the queue; the two keys below run the
                     * consultation, and they are held by different people.
                     */
                    ['key' => 'appointments.queue', 'name' => 'Check in patients and call them through', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * THE DOCTOR'S TWO KEYS.
                     *
                     * Split rather than one `appointments.consult`, because
                     * the two acts are genuinely different: starting takes
                     * the patient out of the queue and opens the record, and
                     * completing closes it and sets the whole visit moving
                     * downstream. A clinic that wants a junior to open the
                     * write-up and a consultant to sign it off can now say so.
                     *
                     * They live in this module rather than in `prescriptions`
                     * because a consultation is a visit reaching the room —
                     * the routes that write it are already behind the
                     * appointments module, and a clinic that stops running
                     * OPD stops consulting in the same breath.
                     *
                     * Holding either is NOT enough on its own: the controller
                     * still checks the visit belongs to the signed-in doctor,
                     * which no capability can know.
                     */
                    ['key' => 'appointments.consult_start', 'name' => 'Start a consultation', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.consult_complete', 'name' => 'Complete and reopen a consultation', 'scope' => self::SCOPE_BRANCH],

                    ['key' => 'appointments.cancel', 'name' => 'Cancel and mark no-shows', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.doctors', 'name' => 'Add and edit doctors', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'appointments.schedule', 'name' => 'Set timings, leave and extra sessions', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * The patient's side of the same module, in the patient
                     * app. Own-data capabilities: each one is about the
                     * signed-in patient's own record and nobody else's, which
                     * the portal routes enforce (EnsurePortalPatient). They
                     * live in this module rather than one of their own so
                     * that a clinic that stops running OPD stops taking
                     * bookings from the app in the same breath.
                     *
                     * Organization-scoped: a patient is not posted to a
                     * branch, and books at whichever one their doctor sits at.
                     */
                    ['key' => 'portal.appointments.view', 'name' => 'Patients: see their own appointments', 'scope' => self::SCOPE_ORGANIZATION],
                    ['key' => 'portal.appointments.book', 'name' => 'Patients: find a doctor and book for themselves', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
            [
                'key' => 'prescriptions',
                'name' => 'Prescriptions',
                'description' => 'Consultations, prescriptions and the patient timeline.',
                'icon' => 'ti ti-stethoscope',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                /*
                 * A prescription line names a medicine from the master, so
                 * there is nothing to prescribe from without it.
                 */
                'requires' => ['medicines'],
                'capabilities' => [
                    ['key' => 'prescriptions.view', 'name' => 'Read prescriptions', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'prescriptions.write', 'name' => 'Write prescriptions', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'prescriptions.cancel', 'name' => 'Cancel prescriptions', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                /*
                 * The other thing a consultation produces.
                 *
                 * Sold separately from appointments, and requiring them,
                 * because a lab order hangs off a visit: there is nothing to
                 * order against without one. A clinic that refers its bloods
                 * out never buys this and never sees a technician's queue.
                 *
                 * FOUR CAPABILITIES ALONG THE LINE THE WORK ACTUALLY SPLITS.
                 * Ordering is the doctor's and nobody else's. Reading results
                 * is wider — the doctor who ordered them, the desk telling a
                 * patient whether they are back. Processing and completing
                 * are the bench's, and they are two keys rather than one for
                 * the same reason `consult_start` and `consult_complete` are:
                 * signing a result off is what the doctor will act on, and
                 * plenty of labs want that with somebody senior to whoever
                 * ran the sample.
                 */
                'key' => 'laboratory',
                'name' => 'Laboratory',
                'description' => 'Lab orders raised at a visit, and the results that come back.',
                'icon' => 'ti ti-flask',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                'requires' => ['appointments'],
                'capabilities' => [
                    ['key' => 'laboratory.view', 'name' => 'View lab orders and results', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'laboratory.order', 'name' => 'Order tests at a consultation', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'laboratory.process', 'name' => 'Take on lab work and enter results', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'laboratory.complete', 'name' => 'Sign off and cancel lab orders', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'documents',
                'name' => 'Patient documents',
                'description' => 'Scans, reports and letters kept against a patient or a visit.',
                'icon' => 'ti ti-folder',
                'group' => self::GROUP_CLINICAL,
                'is_core' => false,
                /*
                 * A document hangs off a patient, so there is nothing to
                 * attach one to without the register.
                 */
                'requires' => ['customers'],
                /*
                 * Reading is split in two, and the split is the whole point of
                 * the module.
                 *
                 * A pharmacist needs the prescription in front of them and has
                 * no business reading a discharge summary; a billing clerk
                 * needs the insurance letter and none of the medicine. One
                 * `documents.view` could not say that — everybody who could
                 * open the folder could open all of it.
                 *
                 * So `documents.view` reaches the administrative files (ID,
                 * insurance, consent, bills) and `documents.view_clinical`
                 * reaches the medical ones. Which is which is decided by the
                 * category in App\Support\Documents\DocumentCategories, not by
                 * whoever uploaded the file.
                 *
                 * Uploading is deliberately ONE capability rather than one per
                 * sensitivity: a desk that may attach an ID proof and a lab
                 * that may attach a result are the same act, and the category
                 * chosen at upload is recorded with the uploader's name.
                 */
                'capabilities' => [
                    ['key' => 'documents.view', 'name' => 'View ID, insurance and billing documents', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'documents.view_clinical', 'name' => 'View medical documents (reports, scans, summaries)', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'documents.upload', 'name' => 'Attach documents to a patient or visit', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'documents.delete', 'name' => 'Remove documents', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * Printing a document is its own permission, separate from
                     * reading one. A desk that may re-read an insurance letter
                     * is not necessarily somebody who may produce a
                     * prescription on the clinic's letterhead — what comes out
                     * of this carries the organization's name and a doctor's
                     * registration number.
                     *
                     * The document's own category still decides who may open
                     * it afterwards, so generating a clinical document does
                     * not let somebody read it back.
                     */
                    ['key' => 'documents.generate', 'name' => 'Print documents from a template', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * The letterhead itself, split three ways, because seeing
                     * how a branch's prescription is laid out, changing it,
                     * and putting a change into use are three different
                     * decisions — the last one is what every patient handed a
                     * document after it will see.
                     */
                    ['key' => 'documents.template_view', 'name' => 'View document templates', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'documents.template_edit', 'name' => 'Edit this branch’s document templates', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'documents.template_publish', 'name' => 'Put a template version into use', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * The whole authority model, in one key.
                     *
                     * ORGANIZATION-SCOPED, and it is what separates an
                     * organization administrator from a branch manager here:
                     * it reaches EVERY branch's template, sets the
                     * organization default that branches inherit, and locks
                     * fields a branch may then not change.
                     *
                     * A branch role cannot hold it — SaveRoleRequest refuses
                     * an organization-scoped capability on a branch role — so
                     * a manager cannot be given it for their own branch and
                     * thereby reach everybody else's.
                     */
                    ['key' => 'documents.template_org', 'name' => 'Manage every branch’s templates and lock fields', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
            [
                'key' => 'medicines',
                'name' => 'Medicines',
                'description' => 'The medicine master every prescription and stock record points at.',
                'icon' => 'ti ti-pill',
                'group' => self::GROUP_PHARMACY,
                'is_core' => false,
                /*
                 * Reading the list is what a doctor or counter does at their
                 * branch. Changing it changes what every branch prescribes and
                 * sells, so it is an organization decision.
                 */
                'capabilities' => [
                    ['key' => 'medicines.view', 'name' => 'View medicines', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'medicines.manage', 'name' => 'Add, edit and remove medicines', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
            [
                'key' => 'pharmacy',
                'name' => 'Pharmacy',
                'description' => 'Stores, batches, stock movements and dispensing.',
                'icon' => 'ti ti-building-hospital',
                'group' => self::GROUP_PHARMACY,
                'is_core' => false,
                'requires' => ['medicines'],
                /*
                 * Receiving, adjusting and moving stock are separate because
                 * each changes the ledger in a different way, and a counter
                 * that dispenses all day has no business writing stock off.
                 * Which stores exist, and bringing back a removed record, are
                 * organization decisions.
                 */
                'capabilities' => [
                    ['key' => 'pharmacy.view', 'name' => 'View stock and the dispensing queue', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.dispense', 'name' => 'Dispense prescriptions', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.sell', 'name' => 'Sell at the counter', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.sale_cancel', 'name' => 'Cancel a bill', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.inward', 'name' => 'Receive stock (GRN)', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.adjust', 'name' => 'Adjust stock', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.transfer', 'name' => 'Transfer stock between stores', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.batches', 'name' => 'Manage batches', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.reverse', 'name' => 'Reverse a dispensing', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'pharmacy.stores', 'name' => 'Set up stores', 'scope' => self::SCOPE_ORGANIZATION],
                    ['key' => 'pharmacy.restore', 'name' => 'Restore removed pharmacy records', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
            [
                /*
                 * Sold separately from the rest of the pharmacy, and REQUIRES
                 * it — a superadmin binds this the same way as any other
                 * module, on its own row, with its own dates.
                 *
                 * Six capabilities, one per report, is the whole point of the
                 * split. Before this, every one of Sales, Purchases, Stock,
                 * Expiry, Profit and GST hung off `pharmacy.view` — the same
                 * key that also opens the dashboard, the stock list and the
                 * settings screen, so there was no way to hand somebody the
                 * sales report without also handing them the shop's margins.
                 * Profit and GST in particular are commercially sensitive in
                 * a way "how much stock is on the shelf" is not, and plenty
                 * of pharmacies want a cashier to see the first and never the
                 * second.
                 */
                'key' => 'reports',
                'name' => 'Reports',
                'description' => 'Sales, purchases, stock, expiry, profit and GST reports for the pharmacy.',
                'icon' => 'ti ti-report-analytics',
                'group' => self::GROUP_PHARMACY,
                'is_core' => false,
                'requires' => ['pharmacy'],
                'capabilities' => [
                    ['key' => 'reports.sales', 'name' => 'View the sales report', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'reports.purchases', 'name' => 'View the purchases report', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'reports.stock', 'name' => 'View the stock report', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'reports.expiry', 'name' => 'View the expiry report', 'scope' => self::SCOPE_BRANCH],

                    /*
                     * Commercially sensitive — what the shop actually makes,
                     * and what it owes in tax. Held apart from the four above
                     * so an owner can staff a counter that sees stock and
                     * sales without seeing margins.
                     */
                    ['key' => 'reports.profit', 'name' => 'View the profit report', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'reports.gst', 'name' => 'View the GST report', 'scope' => self::SCOPE_BRANCH],
                ],
            ],
            [
                'key' => 'communication',
                'name' => 'Communication',
                'description' => 'WhatsApp integration, Email and SMS notifications.',
                'icon' => 'ti ti-messages',
                'group' => self::GROUP_COMMUNICATION,
                'is_core' => false,
                'capabilities' => [
                    ['key' => 'communication.view', 'name' => 'View communication logs and dashboards', 'scope' => self::SCOPE_BRANCH],
                    ['key' => 'communication.manage', 'name' => 'Manage communication settings and integrations', 'scope' => self::SCOPE_ORGANIZATION],
                ],
            ],
        ];
    }

    /**
     * The modules this one cannot run without.
     *
     * @return list<string>
     */
    public static function requires(string $key): array
    {
        return self::find($key)['requires'] ?? [];
    }

    /**
     * For a set of switched-on modules, which of them are missing something
     * they need — keyed by module, listing what is missing.
     *
     * Empty when the set is consistent. Both levels that switch modules on
     * (the platform binding and the branch screen) ask this, so neither can
     * produce a prescriptions module with no medicines behind it.
     *
     * CORE MODULES ARE ALWAYS TREATED AS PRESENT, whether or not `$enabledKeys`
     * names them. Both call sites build that list from a payload of the
     * TOGGLEABLE modules alone — a core module has no toggle to appear in one,
     * `ModuleAccess::enabled()` includes every core key for every organization
     * unconditionally. Before this, a module that required a core one (as
     * `documents` requires `customers`) could never be switched on: the
     * requirement read as unmet however things stood, because the one module
     * that would have satisfied it was structurally never in the list being
     * checked.
     *
     * @param  list<string>  $enabledKeys
     * @return array<string, list<string>>
     */
    public static function unmetRequirements(array $enabledKeys): array
    {
        $satisfied = [...$enabledKeys, ...self::coreKeys()];

        $unmet = [];

        foreach ($enabledKeys as $key) {
            $missing = array_values(array_diff(self::requires($key), $satisfied));

            if ($missing !== []) {
                $unmet[$key] = $missing;
            }
        }

        return $unmet;
    }

    /**
     * "Prescriptions needs Medicines." — one sentence per module, for a 422.
     *
     * @param  array<string, list<string>>  $unmet
     */
    public static function describeUnmet(array $unmet): string
    {
        $name = fn (string $key) => self::find($key)['name'] ?? $key;

        return implode(' ', array_map(
            fn (string $key, array $missing) => sprintf(
                '%s needs %s. Enable %s too.',
                $name($key),
                implode(' and ', array_map($name, $missing)),
                count($missing) === 1 ? 'it' : 'them',
            ),
            array_keys($unmet),
            $unmet,
        ));
    }

    /**
     * Why a branch may not switch these off, as a sentence.
     *
     * @param  list<string>  $locked
     */
    public static function describeLocked(array $locked): string
    {
        $names = array_map(fn (string $key) => self::find($key)['name'] ?? $key, $locked);

        return sprintf(
            '%s %s required by your organisation and cannot be switched off here.',
            implode(' and ', $names),
            count($names) === 1 ? 'is' : 'are',
        );
    }

    /** @return list<string> */
    public static function keys(): array
    {
        return array_column(self::all(), 'key');
    }

    public static function supports(string $key): bool
    {
        return in_array($key, self::keys(), true);
    }

    /** @return array<string, mixed>|null */
    public static function find(string $key): ?array
    {
        foreach (self::all() as $module) {
            if ($module['key'] === $key) {
                return $module;
            }
        }

        return null;
    }

    /**
     * The modules nobody can be without.
     *
     * @return list<string>
     */
    public static function coreKeys(): array
    {
        return array_column(
            array_filter(self::all(), fn (array $module) => $module['is_core']),
            'key'
        );
    }

    /**
     * Which module grants a capability.
     *
     * The join that makes route-level permissions possible: a route says
     * which capability it needs, and this says which entitlement has to be
     * in place before anybody's role can even be asked. Level two adds the
     * role check beside it; nothing here changes.
     */
    public static function moduleForCapability(string $capability): ?string
    {
        foreach (self::all() as $module) {
            foreach ($module['capabilities'] as $entry) {
                if ($entry['key'] === $capability) {
                    return $module['key'];
                }
            }
        }

        return null;
    }

    /**
     * Every capability these modules grant, in registry order.
     *
     * @param  list<string>  $moduleKeys
     * @return list<string>
     */
    public static function capabilitiesFor(array $moduleKeys): array
    {
        $capabilities = [];

        foreach (self::all() as $module) {
            if (! in_array($module['key'], $moduleKeys, true)) {
                continue;
            }

            foreach ($module['capabilities'] as $capability) {
                $capabilities[] = $capability['key'];
            }
        }

        return $capabilities;
    }

    /** Every capability the software defines, in registry order. */
    /** @return list<string> */
    public static function allCapabilities(): array
    {
        return self::capabilitiesFor(self::keys());
    }

    /**
     * Whether a capability exists at all.
     *
     * Validation asks this before storing it on a role, so a typo — or a
     * capability left behind by a module that was retired — cannot be saved
     * and then silently never match anything.
     */
    public static function supportsCapability(string $capability): bool
    {
        return in_array($capability, self::allCapabilities(), true);
    }

    /**
     * Where a capability can mean anything — see the SCOPE_* constants.
     *
     * Null for a key the registry does not know, so a caller can tell "no
     * scope recorded" from "organization" rather than guessing.
     */
    public static function capabilityScope(string $capability): ?string
    {
        foreach (self::all() as $module) {
            foreach ($module['capabilities'] as $entry) {
                if ($entry['key'] === $capability) {
                    return $entry['scope'];
                }
            }
        }

        return null;
    }

    /**
     * The catalogue shaped for the role screen: modules, each with its
     * capabilities, limited to what these module keys allow.
     *
     * `$scope` narrows it further to the capabilities THAT SCOPE MAY HOLD — and
     * the rule is asymmetric, the same asymmetry `SaveRoleRequest` enforces on
     * save:
     *
     *   SCOPE_BRANCH        only branch-scoped capabilities. A branch role is
     *                       never offered `settings.manage`, because there is
     *                       no version of it that applies at one branch and
     *                       not another — offering it would be a choice the
     *                       software could not honour, and ticking it would
     *                       only fail on save.
     *   SCOPE_ORGANIZATION  everything, branch-scoped included. An
     *                       organization-wide role applies everywhere, so a
     *                       branch capability on it simply applies at every
     *                       branch — which is what lets head office hold
     *                       `people.view`.
     *   null                everything, for a caller that is not asking on
     *                        behalf of a role of either scope (the validation
     *                        pool in Permission::grantable(), which checks
     *                        "does this exist here at all" before scope is
     *                        judged separately).
     *
     * Modules left with no capabilities after the filter are dropped: an empty
     * heading is worse than an absent one.
     *
     * @param  list<string>  $moduleKeys
     * @return list<array{key: string, name: string, icon: string, capabilities: list<array<string, string>>}>
     */
    public static function grantable(array $moduleKeys, ?string $scope = null): array
    {
        $grantable = [];

        foreach (self::all() as $module) {
            if (! in_array($module['key'], $moduleKeys, true)) {
                continue;
            }

            $capabilities = $scope === self::SCOPE_BRANCH
                ? array_values(array_filter(
                    $module['capabilities'],
                    fn (array $entry) => $entry['scope'] === self::SCOPE_BRANCH,
                ))
                : $module['capabilities'];

            if ($capabilities === []) {
                continue;
            }

            $grantable[] = [
                'key' => $module['key'],
                'name' => $module['name'],
                'icon' => $module['icon'],
                'capabilities' => $capabilities,
            ];
        }

        return $grantable;
    }
}
