# Super Admin Panel — Where We Stand

Measured against **Super Admin Panel · Build Spec v1.0** on 29 Aug 2026.
Verified against the running code and the live database, not from memory.

**Headline:** 2 of 14 acceptance tests pass. 1 of 24 central tables exists.
None of the three "must be right the first time" flows is complete.

What *is* built is real and reusable — the React shell, the design system,
the table/form/modal layer, the API conventions and a working provisioning
path. The gap is scope, not quality.

---

## Acceptance tests (§22) — the actual scoreboard

| # | Test | Status |
|---|---|---|
| 1 | Creating an organization produces a DB with the current schema | ✅ verified |
| 2 | Owner gets a set-password mail and can log into the tenant app | ⚠️ mail works; no tenant app to log into yet |
| 3 | Failed provisioning is visible and retry completes it | ❌ rollback only, no retry |
| 4 | Disabling a module blocks tenant routes within one cache cycle | ❌ no modules |
| 5 | Re-enabling restores access without a redeploy | ❌ |
| 6 | Suspended org cannot log in, sees the reason | ❌ no suspend |
| 7 | No admin API response contains a DB password | ✅ trivially — no credentials are stored at all |
| 8 | Impersonation without a reason is rejected | ❌ no impersonation |
| 9 | Impersonation shows banner, writes session row + email | ❌ |
| 10 | Every create/update/delete appears in `platform_audit_logs` | ❌ no audit log |
| 11 | A tenant user cannot authenticate on the platform guard | ✅ **passing** — locked by `AuthenticationTest` |
| 12 | Hard delete requires slug confirmation and an export | ❌ |
| 13 | `tenants:migrate` reports per-tenant success/failure without stopping | ⚠️ `system:migrate` does this; no `--org`, no run log |
| 14 | Reaching `max_locations` blocks creation with a clear message | ❌ no limits |

---

## Part-by-part

### §5 Central database — 1 of 24 tables

**Exists:** `organizations`, plus `organization_type` (our own, not in the spec).

**Missing entirely:**

| Group | Tables |
|---|---|
| Admin identity | `platform_users` `platform_roles` `platform_role_user` `platform_password_resets` |
| Tenants | `organization_contacts` `tenant_databases` `tenant_migration_runs` `organization_status_history` |
| Commercial | `modules` `plans` `plan_modules` `plan_limits` `subscriptions` `subscription_events` |
| Overrides | `organization_modules` `organization_limits` `usage_snapshots` |
| Catalog | `global_manufacturers` `global_products` `catalog_imports` |
| Ops | `platform_audit_logs` `impersonation_sessions` `announcements` `platform_settings` |

**`organizations` column gaps:**

Missing: `uuid` (ULID) · `slug` · `legal_name` · `gstin` · `drug_license_no` ·
`plan_id` · `trial_ends_at` · `activated_at` · `suspended_at` ·
`suspension_reason` · `timezone` · `currency` · `country` · `notes`

Status enum differs — ours is `pending_setup · active · suspended · expired`,
the spec wants `pending · provisioning · active · suspended · cancelled · failed`.

### §7–9 Provisioning — works, but not to spec

| Spec step | Us |
|---|---|
| Runs as `ProvisionTenantJob` (queued) | ❌ synchronous inside the request |
| 1. `CREATE DATABASE` | ✅ |
| 2. `CREATE USER` + GRANT on that DB only | ❌ **every tenant uses the master credentials** |
| 3. Encrypted credentials in `tenant_databases` | ❌ no table |
| 4. Run tenant migrations | ✅ |
| 5. Seed roles, permissions, settings | ❌ |
| 6. Create owner user + first location | ⚠️ owner is created later, from the emailed link; no locations table |
| 7. Create subscription (trialing) | ❌ |
| 8. Welcome email with set-password link | ✅ |
| Idempotent + resumable retry | ❌ rolls the whole thing back instead |
| Failure step stored + Retry button | ❌ |

Lifecycle (§9): only soft delete exists. No suspend, resume, cancel, or
hard delete with friction.

### §10–12 Subscription & module control — not started

Nothing exists: no `FeatureService`, no plans, no module toggles, no limits,
no trials, no usage snapshots. The spec calls this the one thing never to
drop, because every tenant feature check reads from it.

### §13–14 Global catalog — not started

### §15–17 Operations — not started

No impersonation, no audit log. The dashboard is a placeholder card. The
organization detail screen (7 tabs) does not exist — we have list + form only.

### §18 Security — the largest divergence

| Rule | Us |
|---|---|
| Separate `platform` guard and `platform_users` table | ❌ single `users` table, `web` guard |
| Separate subdomain / deployment | ❌ panel and API share one domain |
| Mandatory 2FA (TOTP) | ❌ |
| IP allowlist | ❌ |
| 30-minute idle session | ⚠️ 15-min UI lock screen; `SESSION_LIFETIME=120` |
| Login rate limit 5 / 15 min | ⚠️ 5 attempts, 60-second decay (Breeze default) |
| **No self-registration** | ❌ **`POST /api/v1/auth/register` is open** |
| Panel roles (Super Admin/Ops/Support/Billing/Catalog) | ❌ no roles at all |

No Policies or Gates exist anywhere in the app yet.

### §19–20 API & frontend

Endpoints live at `/api/v1/...`, the spec wants `/api/v1/admin/...` behind the
`platform` guard, every route behind a policy. We have ~17 of roughly 40
endpoints and zero policies.

Frontend folders map cleanly (`core/organizations` → `admin/organizations`),
so the structural move is small.

---

## Three decisions to settle before writing more schema

1. **ULID vs integer IDs.** The spec exposes `uuid` in every URL. We expose
   integer ids today (`/organizations/3/edit`). Changing this later rewrites
   every route, link and API path. Appendix A of the platform brief calls it
   decision #1 for the same reason.

2. **Per-tenant database users.** Today every tenant DB is reached with the
   master PostgreSQL credentials. One leaked connection string reaches every
   customer's data. The spec's step 2 (`CREATE USER` + GRANT on one database)
   is what contains that blast radius — and it needs `tenant_databases` with
   encrypted credentials to be worth doing.

3. **Separate guard and subdomain.** Right now a tenant user and a platform
   admin would authenticate against the same table. The spec treats this as
   non-negotiable, and it is far cheaper to split now — before there are
   tenant users — than after.

Note: the spec's risk table says *"MySQL user lacks CREATE DATABASE"*. We run
**PostgreSQL** (recorded in ARCHITECTURE.md); the equivalent check is that the
role has `CREATEDB` and `CREATEROLE` — the second one is needed for step 2.

---

## Build order, step by step

Ordered by dependency, not by the spec's day numbers. Each step is shippable
on its own and leaves the app working.

**One deliberate deviation from the spec:** the audit log moves from Day 6 to
step 3. Written early, every mutation after it gets logged as it is built;
written late, it means going back through forty endpoints to retrofit.

---

### Step 0 — Prerequisites ✅ done 29 Aug

- [x] PostgreSQL role has `CREATEDB` and `CREATEROLE` — both confirmed
- [ ] Create a dedicated non-superuser app role. We connect as `postgres`
      today, which is exactly the blast radius step 4 is meant to close.
- [ ] Settle the three decisions above (ULID · per-tenant users · split guard)

### Step 1 — Platform identity ✅ done 29 Aug

- [x] `platform_users`, `platform_roles`, `platform_role_user`,
      `platform_password_resets`
- [x] `platform` guard, provider and password broker in `config/auth.php`;
      `sanctum.guard` narrowed to `['platform']`
- [x] `PlatformUser` / `PlatformRole` models, ULID generated on create
- [x] Repository layer introduced — see `app/Repositories/README.md`
- [x] `PlatformRoleSeeder` (the five §18 roles) + `PlatformUserSeeder`
- [x] Admin endpoints moved to `/api/v1/admin`, behind `auth:platform`
- [x] `POST /auth/register` removed
- [x] Login rate limit 5 / 15 min; deactivated admins refused
- [x] 11 feature tests, including acceptance test 11
- [x] `SESSION_LIFETIME` 120 → 30 minutes

Fixed along the way, each found by the work rather than looked for:

- `organizations.created_by` / `updated_by` referenced `users`. Re-pointed at
  `platform_users`; the few existing values were nulled since they named rows
  in a different table.
- `Record::actorId()` — `Auth::id()` reads the *default* guard, so every audit
  stamp would have silently become null the moment admins moved to `platform`.
- `AdminUserSeeder` seeded a `users` row with the admin's own address and
  password, so one credential pair opened two accounts on two guards. Deleted.
- `database/migrations/masterdb` was never registered, so plain `migrate`
  built a database with no `organizations` table. Only `system:migrate`
  passing `--path` worked. Now registered in `AppServiceProvider`.
- Tests ran on sqlite while the app is Postgres-only (`ILIKE`, `CREATE
  DATABASE`, dropping FKs by name). Suite moved to `hms_software_testing`.
- `tests/TestCase.php` was missing entirely — the whole suite was unrunnable.
- Password reset links pointed at the deleted Blade route rather than the
  SPA's `/reset-password/{token}`.
- **Restructure debris.** The one-off scripts that moved files into
  `Platform/` had left the codebase half-migrated: four FormRequests declared
  `...Requests\Api\V1\Platform\Platform` (one `Platform` too many),
  `OrganizationTypeController` imported both the doubled name *and* the
  correct one and used the doubled alias in its signatures, and roughly
  fifteen pre-move originals were still sitting alongside their replacements
  — old `Services/`, `Resources/`, `Models/hms/`, `Api/V1/SuperAdmin/`. All
  of it was orphaned: every reference pointed at another orphan. Removed, and
  a PSR-4 audit now reports every namespace matching its folder.
- `OrganizationTypeController` moved onto a repository and its collapsed
  one-line statements re-expanded — it had been minified by the same script.

### Step 2 — Organizations to spec ✅ done 29 Aug

- [x] All 14 spec columns added; `uuid` and `slug` backfilled from the
      subdomain for the rows already there, then made unique and non-null
- [x] `status` widened to the six §5 states, with the old values mapped
      across (`pending_setup` → `pending`, `expired` → `pending`)
- [x] `organization_status_history`, `organization_contacts`
- [x] `OrganizationRepository` — `changeStatus()` writes the column and the
      history row in one transaction, and stamps `activated_at` /
      `suspended_at` itself so no caller can forget
- [x] Route model binding on `uuid`; the row id no longer appears in any URL
      or API response
- [x] Provisioning walks `pending → provisioning → active`, leaving history
- [x] Detail screen with the seven §17 tabs; Overview is filled in, the rest
      name the step that fills them
- [x] Lifecycle status badge on the list, replacing the active/inactive flag
- [x] 13 feature tests

Notes for later steps:

- `plan_id` is deliberately a bare column with no foreign key — `plans`
  arrives in step 6 and the constraint goes on then.
- `provisioning` and `failed` are defined but barely used: provisioning still
  rolls everything back on failure rather than stopping and recording where.
  Step 4 makes it resumable, and `failed` starts appearing then.
- Organization setup no longer writes `status`. Provisioning already moved the
  record to active, and writing it again would either add a meaningless
  history row or revive an organization an admin had since suspended.

### Step 3 — Audit log

- `platform_audit_logs`, append-only — no update or delete route exists
- One trait or observer so a controller cannot forget to write to it
- Backfill it across the endpoints written in steps 1–2
- Audit tab on the detail screen

**Done when:** creating, editing and deleting an org each leave a row with
before/after JSON, actor, IP.

### Step 4 — Provisioning to spec

The largest single step. Everything here exists in some form already; it is
being rebuilt to be idempotent rather than all-or-nothing.

- `tenant_databases` with encrypted credentials, never returned by any API
  (add the test that asserts it)
- Provisioning step 2: `CREATE USER` + `GRANT` on that one database
- Move the service into a queued `ProvisionTenantJob`
- Make each of the 8 steps check whether it has already run, so a retry
  resumes instead of duplicating; store the failing step + exception
- `POST /{uuid}/retry-provisioning` and a Retry button on the failure state
- `tenant_migration_runs`; rename `system:migrate` → `tenants:migrate` with
  `--org` / `--pretend` / `--force`; schema drift badge
- Database & Health tab

**Done when:** you can kill the queue worker mid-provision, restart it, hit
Retry, and end with exactly one working tenant — no duplicate rows.

### Step 5 — Lifecycle

Needs the audit log (step 3) and status history (step 2).

- Suspend (with reason shown to the tenant) · Resume · Cancel
- Hard delete behind slug confirmation, written reason and a mandatory export
- Danger Zone tab

**Done when:** a suspended org cannot log in and is told why.

### Step 6 — Modules, plans, `FeatureService`

The feature gate. The spec says never drop this one.

- `modules`, `plans`, `plan_modules`, `plan_limits`, `subscriptions`,
  `subscription_events`, `organization_modules`, `organization_limits`
- Seed the module and plan list from §6
- `FeatureService::for($org)->can() / modules() / limit()`, cached with an
  explicit bust on every write path
- Subscription & Modules tab; per-module toggles with a reason

**Done when:** turning off a module changes the tenant's answer within one
cache cycle, and turning it back on needs no redeploy.

### Step 7 — Platform dashboard + Usage

- `usage_snapshots` and the nightly job that fills it
- Replace the placeholder dashboard with the §16 metrics
- Usage tab, measured against plan limits

### Step 8 — Impersonation

- `impersonation_sessions`, reason required (min 10 chars), 60-minute token
- Undismissable banner in the tenant app, owner email, read-only by default

### Step 9 — Global catalog

`global_manufacturers`, `global_products`, CSV import with an error file.
This is the spec's own first cut line if time runs short.

### Step 10 — Hardening

2FA (TOTP), IP allowlist, panel roles, a policy on every route, the separate
subdomain, and the feature tests for all 14 acceptance criteria.

---

### Blade cleanup ✅ done 29 Aug

The last of the SPA conversion, cleared before step 2 so there is less dead
code to read past — and because the three namespace bugs that surfaced during
step 1 all came from this half-finished migration.

- [x] All 31 Blade views deleted. `resources/views` now holds `app.blade.php`
      and `emails/` only.
- [x] SPA moved from `/app` to the root catch-all; `ROUTER_BASENAME` removed
- [x] `routes/auth.php` and `routes/superadmin-routes.php` deleted
- [x] Breeze web-auth controllers, the `hms/` controllers, `ProfileController`,
      their form requests and `app/View/Components` all removed
- [x] The seven obsolete Breeze view tests deleted — **the suite is green**
- [x] `SpaRoutingTest` added to pin the catch-all's exclusion list

Routes went from 58 to 27.

Two live bugs fell out of it:

- **Every invitation link was a 404.** Provisioning mails
  `/organization/setup/{token}`, but the SPA answered on `/app/...` and the
  Blade route was `/global-settings/organization/setup/{token}` — so the URL
  in the email matched nothing. Serving the SPA from the root fixes it, and a
  test now covers that exact path.
- **Unauthenticated API calls raised a 500.** Laravel's auth middleware
  redirects anything that does not explicitly ask for JSON to
  `route('login')`, which stopped existing with the Blade screens. Any caller
  without an `Accept: application/json` header — curl, a webhook — got
  "Route [login] not defined" instead of a 401. `redirectGuestsTo` now returns
  null for `api/*` so the exception reaches the JSON handler.
