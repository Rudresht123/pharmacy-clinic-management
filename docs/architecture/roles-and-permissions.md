# Roles, permissions and branch scope — inspection and proposal

Written for the person deciding whether to approve a change to this system's
authorization model. Read the CURRENT section first; a lot of what was asked
for is already built under different names, and the proposal is much smaller
than the brief implies.

**Status: the proposal below has been implemented**, with two deviations
recorded at the bottom under "What changed during implementation". The CURRENT
section describes the system as it was before that work and is kept as the
reasoning behind it.

---

# PART ONE — CURRENT SYSTEM

## 1. Authentication architecture

Three separate populations, three guards, two databases.

| Who | Guard | Lives in | Signs in at |
|---|---|---|---|
| Platform super admin | `platform` | master DB (`platform_users`) | `/api/v1/admin/...` |
| Organization staff (owner + staff) | `web`, `tenant-api` | that tenant's own DB (`users`) | `POST /tenant/auth/login` (session) or `/tenant/auth/token` (Sanctum, for mobile) |
| Patients | `web` as `role = patient` | same tenant `users` table | one-time code, portal routes only |

Tenancy is **physical**: one PostgreSQL database per organization. The tenant is
resolved before authentication — from the session (`ResolveTenantFromSession`)
or from an `X-Organization` header (`ResolveTenantFromHeader`) — because tokens
themselves live in the tenant's database.

Every tenant model declares `protected $connection = 'organization'`, which
`TenantConnectionService` points at the right database per request.

Middleware stack on a tenant API route, in order:

```
api
→ ResolveTenantFromSession / ResolveTenantFromHeader
→ Authenticate:web,tenant-api
→ AdoptTokenUser
→ ResolveActingBranch          ← decides WHICH BRANCH this request is in
→ EnsureTenantHasModule:<key>  ← level 1 + 2
→ EnsureTenantCan:<capability> ← level 3
```

## 2. User model — `App\Models\Tenant\User`

Relevant columns:

| Column | Meaning |
|---|---|
| `role` | the account KIND — `owner`, `staff`, `patient`. Not a permission. |
| `role_id` | an **organization-scoped** role. Applies across the whole network. Head office wears this. Null for owners and for branch staff. |
| `location_id` | legacy. Still fillable; **decides nothing any more** — `TenantBranchAccess` and `StaffScope` both read memberships instead. |
| `userable_type` / `userable_id` | polymorphic link to a `Doctor` or `Customer` record |
| `department_id`, `is_active`, `custom_fields` | |

Key methods: `isOwner()`, `memberships()`, `membershipAt(?int)`,
`defaultBranchId()`, `permissionRole()` (named so it does not shadow the `role`
column).

## 3. Organization model

`App\Models\Platform\Organization`, in the **master** database. Carries
`uuid`, `subdomain`, `database_name`, `email`, type, and
`moduleEntitlements()` → `organization_modules` (which modules were sold, with
`starts_at` / `expires_at`).

There is no `organization_id` column anywhere in a tenant database, and there
should not be — the database itself *is* the organization.

## 4. Branch model

`App\Models\Tenant\Location` — table `locations`. This is your "branch".
Fields: `name`, `code`, `type` (clinic / warehouse / …), `is_active`, address,
`gstin`, `drug_license_no`, `custom_fields`.

**There is no `manager_id` and no manager concept on this table.**

## 5. Existing role system

`App\Models\Tenant\Role` — table `roles`, per tenant.

- `name`, `slug`, `description`, `icon` (closed list)
- `scope` — `organization` or `branch`. Set at creation, **never editable**
  (changing it would silently move every holder's permissions).
- `location_id` — null = written by the organization; set = written by that
  branch, and only assignable there (`scopeAssignableAt`).
- `capabilities()` → `role_capabilities` (`role_id`, `capability` varchar)

Roles are **not** soft-deleted, and a role anybody holds cannot be deleted at
all (counted and refused with a message, not left to the FK).

**Who may write roles:** the owner writes organization-wide roles. A branch
writes its own, if somebody there holds `people.roles` — enforced per role in
`RoleController::mustBeWritable()`, which returns 404 (not 403) for another
branch's role so an id is never confirmed to exist.

**What ships:** exactly **one** seeded role, slug `staff`. Nothing else.

## 6. Existing permission system

`App\Support\Modules\ModuleRegistry` is the authoritative vocabulary — code,
not data. 10 modules, ~50 capabilities. Each capability declares a `scope`
(`organization` or `branch`).

Permission is decided at **three levels, always asked in this order**, by
`App\Services\Permissions\Permission`:

1. **Entitlement** — did the super admin sell this module to the organization?
   (`organization_modules`, master DB)
2. **Branch modules** — does this branch run it? (`location_modules`, tenant DB,
   opt-out only: a branch with no rows inherits everything)
3. **Role** — does this person hold the capability *here*?

The owner bypasses **level 3 only**. A module the organization was never sold is
refused to them exactly as to anybody else.

What somebody holds at a branch is the **union of two rows, never a chain**:

```
users.role_id             organization role  → applies everywhere
branch_users.role_id      branch role        → applies at that branch alone
```

A union rather than inheritance, deliberately: "why can Rahul do this" must be
answerable by reading two rows.

### The other three services — and this is the part the brief misses

`Permission` answers **what may be done**. Three more services answer the rest:

| Service | Answers | Key rules |
|---|---|---|
| `TenantBranchAccess` | **where** | owner → every branch; staff → their memberships; doctor → their *postings* (`doctor_locations`) as well |
| `StaffScope` | **to whom** | non-owner sees their own branches' people + themselves; **a non-owner may never act on an owner** (closes password-reset takeover); `canGrant()` — nobody may put somebody on a role holding capabilities they do not hold themselves |
| `AsksAboutBranch` (policy trait) | both, per record | `canUse(branch)` **and** `allows(capability, at that branch)` |

## 7. Existing user–branch relationships

**`branch_users`** — model `BranchMembership`. Columns: `user_id`,
`location_id`, `role_id` (nullable), `is_primary`, timestamps.

This is exactly the `user_branch_roles` table the brief asks for. It already
exists and is already the source of truth.

Multi-branch already works: "Receptionist at Lucknow, Branch Manager at Delhi"
is two rows, and the Lucknow role grants nothing at Delhi.

Branch switching: the client sends `X-Branch-Id`. `ResolveActingBranch`
**checks it against the caller's own memberships and refuses with 403 if they
are not a member** before any query runs, then fixes it on the `Permission`
instance for the whole request.

Doctors are the deliberate exception — they get an organization-wide role and
no membership, because they sit wherever their timings put them; their branch
reach comes from `doctor_locations`.

## 8. Existing pharmacy / clinic relationships

- `pharmacy_stores` belong to a `location`. `PharmacyStorePolicy` +
  `AsksAboutBranch` enforce branch reach per store.
- `medicine_batches`, `stock_*`, `pharmacy_sales` hang off a store → a branch.
- `appointments` carry `location_id`; `OpdBoard` and `DashboardSummary` narrow
  by `TenantBranchAccess`.
- `prescriptions` — `PrescriptionPolicy`, same trait.
- **`customers` (patients) are deliberately organization-wide.** They carry
  `registered_location_id`, but it is an optional *filter* a user can apply, not
  an enforced scope. The UI says so out loud: "One record per person, shared by
  every branch in your organization."
- **There is no lab module and no billing module.** Not stubbed — absent.
  `medicines`, `pharmacy`, `prescriptions`, `appointments`, `communication`,
  `documents` exist; lab and billing appear in the sidebar only as "Coming
  soon" rows.

## 9. Problems with the current implementation

These are real, and they are the gap between what exists and what was asked
for. Ordered by how much they matter.

**P1 — "Branch Manager" is not a thing.**
There is no `locations.manager_id`, no assignment endpoint, no "change the
manager" flow, and therefore nothing to revoke when the manager changes. Today
you approximate it: create a branch-scoped role called "Branch Manager", tick
the capabilities, and add a membership. It works, but it is assembled by hand
every time, it is not discoverable, and nothing records *who the manager of
Gurgaon is* as a fact.

**P2 — No default-permission library in the database.**
Exactly one seeded role (`staff`). Four role templates exist
(`resources/js/core/roles/templates.ts`) but they are **frontend-only**, and by
design one-shot: applying one fills in checkboxes and is immediately forgotten.
There is no Pharmacist, Lab Technician, Accountant or Branch Manager template.
So "assign role → default permissions apply automatically" is currently "open
the role screen and tick about fifteen boxes".

**P3 — No per-user permission override.**
Brief §9 wants a manager to remove `refund` from one receptionist. Today the
only answer is to clone the role. For three receptionists who each need one
thing different, that is three roles.

**P4 — Audit entries do not record the branch.**
`activity_logs` has `user_id`, `actor_name`, `actor_type`, `event`,
`entity_type`, `entity_id`, `entity_label`, `before`, `after`, `ip_address`,
`created_at`. Append-only, enforced by a database trigger. But **no
`location_id`**, so "who changed what at Gurgaon" cannot be asked. There is
also no distinct event for a manager assignment, because there are no manager
assignments.

**P5 — Patients are organization-wide, which contradicts brief §4.**
"Branch Manager cannot access another branch's patients" is not true today and
is not an oversight — it is a deliberate product decision, and probably the
right one for a clinic chain where a patient walks into whichever branch is
nearer. This needs a decision, not a fix.

**P6 — Two roles named in the brief cannot be built.**
Lab Technician and Accountant need lab and billing modules that do not exist.
Seeding those roles now would put capabilities on a role screen that no route
will ever ask about — exactly what `ModuleRegistry`'s own header says not to do.

**P7 — Capability names differ from the brief's.**
The brief proposes `queue.call`, `consultation.start`, `payment.collect`,
`branch.users.create`. The system uses `appointments.queue`, `customers.view`,
`people.create`. **Do not rename.** ~50 keys are wired into routes, roles,
seeded rows, the frontend and the tests; the existing names are also more
honest, because they are grouped by the module that grants them, which is what
makes revoking a module able to know what to take away.

**P8 — `users.location_id` is dead but still present.**
Fillable, still written by the People form, read by nothing that decides
anything. It will mislead the next person who reads the schema.

---

# PART TWO — PROPOSED SYSTEM

The headline: **do not rebuild this.** The three-level model, the branch-scoped
roles, the membership table, the escalation guards and the branch-scope
services are all sound and all tested. What is missing is a **named Branch
Manager**, a **library of default roles**, and **two small columns**.

## 10. Recommended architecture

Four changes, in order of value.

### A. Make "Branch Manager" a first-class fact

Add `locations.manager_id` → `users.id`, nullable.

That column is the answer to "who runs Gurgaon". Assigning it is an endpoint,
not a form the owner has to assemble:

```
PUT /tenant/locations/{location}/manager   { "user_id": 12 }
DELETE /tenant/locations/{location}/manager
```

which, in one transaction:

1. checks the caller holds `branches.manage_manager` (new, organization-scoped)
2. checks the target is staff, active, and not an owner
3. gives the target a `BranchMembership` at that branch on the seeded
   **Branch Manager** role (creating the membership if absent, replacing its
   `role_id` if present)
4. **revokes the outgoing manager**: removes the Branch Manager role from their
   membership at that branch, leaving the membership itself alone — somebody
   stepping down from managing Gurgaon usually still works at Gurgaon
5. writes one audit entry naming both people

`manager_id` is a **cache of a fact, not the permission**. The permission stays
where it already is — the membership row — so nothing downstream needs to learn
about a new concept, and a `manager_id` that somehow disagreed with the
memberships would grant nothing.

### B. Seed a real role library, per tenant

Move the templates out of the frontend and into a `RoleTemplates` support class
(sibling of `ModuleRegistry`), seeded into every new tenant and backfilled into
existing ones:

| Role | Scope | Buildable today? |
|---|---|---|
| Branch Manager | branch | yes |
| Doctor | branch | yes |
| Receptionist | branch | yes |
| Pharmacist | branch | yes |
| Head office | organization | yes |
| Lab Technician | — | **no** — needs a lab module |
| Accountant | — | **no** — needs a billing module |

Each carries a `template_key` so a future release can *offer* an update when a
new module ships, rather than silently changing what people hold.

Capabilities are filtered through the organization's entitlements at seed time,
so a clinic that never bought pharmacy gets no Pharmacist role rather than a
role that grants nothing.

### C. Per-user overrides — recommended shape

Add `user_permission_overrides`:

```
id, user_id, location_id (nullable), capability, effect ('deny'|'allow'), created_by, timestamps
unique (user_id, location_id, capability)
```

Resolution becomes, at one branch:

```
(organization role ∪ branch role)  −  denies  ∪  allows
```

**Recommendation: ship `deny` only, at first.** `deny` cannot escalate, so it
needs no new guard and no new way to get it wrong. `allow` needs the full
`StaffScope::canGrant` treatment (an override that grants what the granter does
not hold is the escalation the whole system is built to prevent) and, in a year
of this codebase, "remove one thing from one person" is the case that actually
comes up. Adding `allow` later is additive.

### D. Two schema tidies

- `activity_logs.location_id`, nullable — so branch-scoped administration is
  auditable per branch.
- Drop `users.location_id` in a separate, later migration, once nothing writes
  it. Not bundled with this work.

## 11. Database relationships (proposed)

```
                      MASTER DB                    │           TENANT DB (one per organization)
                                                   │
  organizations ──< organization_modules >── modules
        │                                          │
        └─ database_name ─────────────────────────►│
                                                   │
                                     ┌─────────────┴──────────────┐
                                     │                            │
                                 locations                      users
                                     │  ▲                         │
                    manager_id ──────┘  │                         │  role_id (organization-scoped)
                    (NEW, nullable)     │                         ▼
                                        │                       roles ──< role_capabilities
                                        │                         ▲
                                        └──── branch_users ───────┘
                                              (user_id,
                                               location_id,
                                               role_id,          ← the branch-scoped role
                                               is_primary)
                                     │
                          location_modules (opt-out per branch)
                                     │
                    user_permission_overrides (NEW)
                          (user_id, location_id?, capability, effect)
                                     │
                          activity_logs (+ location_id, NEW)
```

Everything except the three NEW items already exists.

## 12. Required migrations

Four, all on the `organization` connection:

1. `add_manager_to_locations_table` — `manager_id` nullable FK → `users`,
   `nullOnDelete`, indexed.
2. `create_user_permission_overrides_table` — as above, with the unique index.
3. `add_location_to_activity_logs_table` — nullable `location_id`. **Not a
   foreign key**: `activity_logs` is append-only behind a trigger, and
   `ON DELETE SET NULL` would make Postgres issue an UPDATE the trigger refuses
   — the same reasoning already written into `user_id` on that table.
4. `seed_default_roles` — idempotent, skips a slug that already exists, filters
   capabilities through the organization's entitlements.

Plus one line in `ModuleRegistry`: `branches.manage_manager`, organization-scoped.

## 13. Required model relationships

```php
// Location
public function manager(): BelongsTo   // User, via manager_id

// User
public function managedBranches(): HasMany        // Location, via manager_id
public function permissionOverrides(): HasMany    // UserPermissionOverride
public function managesBranch(?int $locationId): bool
```

No change to `Role` or `BranchMembership`.

## 14. Required middleware and policies

**No new middleware.** `EnsureTenantCan` and `EnsureTenantHasModule` already
cover it, and `ResolveActingBranch` already refuses a branch the caller is not a
member of.

Changes are confined to three existing places:

- `Permission::heldBy()` — subtract denies after the union. One method, one
  place, so nothing else has to learn about overrides.
- `StaffScope::canGrant()` — compare against the actor's **effective**
  capabilities (after their own denies), not their role's. Otherwise a manager
  denied `refund` could still grant it.
- New `LocationManagerPolicy` (or a guard on the controller) for assign/revoke.

## 15. Required frontend changes

- **Branches** — a "Manager" column and an assign/change control on the branch
  form, gated on `branches.manage_manager`.
- **People** — when a role is picked, show what it grants read-only, with the
  overrides beneath it. This is the screen that makes §10 of the brief true:
  pick Receptionist, see the defaults, untick one thing, save.
- **Roles** — a badge for a seeded role, and the template library read from the
  API instead of `templates.ts`.
- `templates.ts` is then deleted, not left as a second source of truth.

Everything else is already role-aware: `tenantNavigation()` builds the menu from
`capabilities` + `modules`, `RequireCapability` / `RequireModule` guard the
routes, and pages branch on `can()`.

## 16. How Branch Manager scope will work

Unchanged from today, which is the point — it is already correct and already
tested (`BranchAdminReachTest`, `StaffScopeTest`, `ActingBranchTest`).

Rahul, manager at Gurgaon, tries `GET /tenant/users?branch=noida`:

1. `ResolveActingBranch` — he sent `X-Branch-Id: <noida>`, he holds no
   membership there → **403 before any query runs**.
2. He sends no header → he acts at Gurgaon; `StaffScope::apply()` narrows the
   staff query to people with a Gurgaon membership, plus himself.
3. He names a Noida user id directly → `StaffScope::canManage()` fails →
   **404, not 403**, so the id is never confirmed to exist.
4. He tries to give somebody a role holding `settings.manage` → `canGrant()`
   refuses, because he does not hold it himself.
5. He tries to write a Noida role → `RoleController::mustBeWritable()` → 404.

The URL-editing attack in brief §15 is already closed, at step 1.

## 17. How role defaults and overrides will work

```
Assign role  →  capabilities come from the ROLE, by reference
                (no copying into the user)
                        ↓
Customise    →  a row in user_permission_overrides per difference
                        ↓
Resolve      →  (org role ∪ branch role) − denies
```

Nothing is copied into a user record, so editing a role still changes what its
holders can do — which is what makes a role worth having. A user with no
overrides has no rows at all, which is the normal case.

## 18. How the Organization Admin assigns a Branch Manager

```
Owner → Branches → Gurgaon → "Manager: none · Assign"
      → pick an active staff member
      → PUT /tenant/locations/{id}/manager
           ├─ capability: branches.manage_manager
           ├─ target must be staff, active, not an owner
           ├─ membership at Gurgaon created or updated → Branch Manager role
           ├─ outgoing manager's Branch Manager role removed (membership kept)
           └─ audit: "Branch manager changed: Amit → Rahul, at Gurgaon"
      → Rahul signs in, branch switcher shows Gurgaon, full branch admin menu
```

Idempotent, one transaction, and safe to run against a branch that already has
a manager.

## 19. How Branch Managers create users

Already works today, via `POST /tenant/users` + `PUT /tenant/users/{id}/branches`.
What changes is only that the role list is worth picking from:

```
Branch dashboard → Users → Add
  Name, email, password
  Role: [Doctor ▾] [Receptionist] [Pharmacist]       ← seeded, branch-scoped
        (Head office and any organization role are NOT offered:
         Rule::exists(..., scope = organization) refuses them on role_id,
         and canGrant refuses anything holding what he lacks)
  Branch: Gurgaon                                    ← only branches he works at
  Permissions: the role's defaults, shown; untick to create a `deny`
```

Guards already in place: `StoreTenantUserRequest` refuses making an owner
unless the caller is one; `ChecksRoleGrant` refuses a role above the caller;
`SaveUserBranchesRequest` refuses a branch the caller cannot use, refuses an
organization-scoped role on a membership, and re-runs `canGrant` per row.

## 20. How unauthorized actions are prevented

Seven checks, and the first six already exist:

| # | Question | Where |
|---|---|---|
| 1 | Authenticated? | `Authenticate:web,tenant-api` + tenant resolution |
| 2 | Module sold to this organization? | `ModuleAccess` (level 1) |
| 3 | Module running at this branch? | `location_modules` (level 2) |
| 4 | Capability held, here? | `EnsureTenantCan` → `Permission::allows` (level 3) |
| 5 | May they act at this branch? | `ResolveActingBranch` + `TenantBranchAccess` |
| 6 | May they act on this record/person? | `StaffScope`, `AsksAboutBranch` policies |
| 7 | Is the record in a state that allows it? | `BookingService::assertCanMoveTo`, prescription status, sale status |

Tenant isolation (brief §16) is **physical**: organization B's data is in a
different database, so a query in A's request literally cannot see it. There is
no `organization_id` to forget a `where` clause on.

An audit of every tenant write route confirms coverage — 88 write routes, all
behind `EnsureTenantCan` except 10, and all 10 are legitimate: 4 pre-auth
(`auth/login`, `auth/logout`, `auth/token` ×2), 2 patient-portal sign-in, and 4
behind `tenant.owner` (branch modules, organisation setup).

---

# What needs your decision

1. **Patients: organization-wide or branch-scoped?** (P5) Today they are shared
   by every branch, on purpose. Brief §4 says a Branch Manager must not reach
   another branch's patients. These cannot both be true. Recommendation: keep
   them shared, and add a `patients.branch_only` capability for chains that
   want the stricter reading — so it is a per-organization choice rather than a
   rewrite.

2. **Overrides: `deny` only, or `deny` + `allow`?** Recommendation: `deny` only
   for now, for the reasons in §10C.

3. **Lab Technician and Accountant.** Recommendation: leave them out until the
   lab and billing modules exist. Seeding roles whose permissions no route will
   ever check is how a permission screen starts lying.

4. **Capability naming.** Recommendation: keep the existing ~50 keys. The
   brief's names are a proposal, not a constraint, and renaming touches routes,
   roles, seeds, the frontend and the tests for no behavioural gain.

---

# What changed during implementation

Two things did not survive contact with the code. Both are corrections to the
proposal above, not to the brief.

### The role seeder is a service, not a migration

§12 said the role library would be seeded by a migration. It cannot be.

Which roles an organization should get depends on which **modules** it was
sold, and that lives in the master database. Laravel's migrator calls
`setDefaultConnection('organization')` while it runs, which mutates
`database.default` — so inside a tenant migration, any model without an
explicit connection resolves to the tenant's own database, and `organizations`
is not there. The first run failed with exactly that:

```
relation "organizations" does not exist
... Database: hms_tenant_demo_clinic
```

It is now `App\Services\Tenant\DefaultRoleSeeder`, called from the two places
that already hold the `Organization`: `tenants:migrate` (which loops over them)
and `OrganizationProvisioningService` (which has just created one). Idempotent
by role name and slug — verified on live data, where one tenant already had a
hand-written "Branch manager" and the seeder correctly left it alone.

### `patients.branch_only` was dropped, and the recommendation was wrong

Decision 1 above suggested adding a `patients.branch_only` capability for
chains that want patients scoped per branch. That is the wrong shape, and it
would have broken the model rather than extended it.

**Capabilities grant. They do not restrict.** Every one of the ~50 keys reads
"this person may do X", and `Permission::allows()` is built on that: holding
more keys can only ever mean being able to do more. A key whose presence takes
something away inverts that, and every guard that reasons about capability sets
— `StaffScope::canGrant()` most of all, which compares two sets and asks
whether one is contained in the other — would quietly get the wrong answer.
`canGrant` would treat "may not see other branches' patients" as a *privilege*
a manager must hold before they can grant it.

If patients should be branch-scoped for some organizations, that is an
**organization setting**, resolved once and applied as a query scope — the same
shape as `location_modules`, which is also a per-organization narrowing rather
than a permission. It is not in this change, and it needs its own decision
about what happens to a patient who walks into a second branch.

### What was built

| | |
|---|---|
| `locations.manager_id` | nullable FK, **not fillable** — reachable only from the manager endpoint |
| `PUT/DELETE /tenant/locations/{id}/manager` | moves the membership and its role in one transaction; revokes the outgoing manager without evicting them from the branch |
| `branches.manage_manager` | new, organization-scoped, held apart from `branches.edit` |
| `App\Support\Roles\RoleTemplates` | Branch manager, Doctor, Receptionist, Pharmacist, Head office — one library, used by both the seeder and the role screen |
| `GET /tenant/roles/templates` | serves it, narrowed to what is grantable at the caller's branch; `resources/js/core/roles/templates.ts` deleted |
| `user_permission_overrides` | deny-only, with two partial unique indexes (a plain composite unique would accept the same "everywhere" deny twice, because Postgres treats NULLs as distinct) |
| `GET/PUT /tenant/users/{id}/permissions` | replaces denies at the actor's own scope only |
| `activity_logs.location_id` | written from the resolved acting branch, not re-derived |
| `BranchManagerPanel` | a tab on the branch form, gated on `branches.manage_manager` |

Not built, as recommended and agreed: Lab Technician and Accountant roles (no
lab or billing module exists to hold their permissions), `allow` overrides, and
any capability renaming.
