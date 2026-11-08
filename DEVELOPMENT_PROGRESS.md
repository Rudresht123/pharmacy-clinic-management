# Laboratory (LIS) — development progress

The design is in [docs/architecture/laboratory.md](docs/architecture/laboratory.md) (approved 2026-11-07).
Each phase is built only after the previous one is reviewed and approved.

## Status

| Phase | Scope | State |
|---|---|---|
| 0 | Architecture analysis and plan | Done, approved 2026-11-07 |
| 1 | Foundation: lab settings and the nine configuration lists | Built 2026-11-08 (committed by the user as "Working on Laboratory Module") |
| **2** | **Test master, parameters, reference ranges, branch prices** | **Built 2026-11-08 — waiting for review** |
| 3 | Packages and profiles, pricing and the duplicate-test rule | Pending |
| 4 | Orders: lab desk (walk-in, referral), doctor ordering, numbering, core billing | Pending |
| 5 | Samples: collection, barcode labels, receiving, rejection, tracking | Pending |
| 6 | Result entry, flags, critical values, previous results | Pending |
| 7 | Verification, approval, amendments | Pending |
| 8 | Report PDF, signatories, versions | Pending |
| 9 | Billing completion: refunds, outsourced costs | Pending |
| 10 | Notifications | Pending |
| 11 | Dashboard and analytics | Pending |
| 12 | Flutter apps | Pending |
| 13 | Integration, security and regression | Pending |

Decisions on record: D1 the doctor's Investigations become lab orders; D2 an OPD visit stops waiting once samples are collected; D3 "take payment before collection" is a setting, off by default; D4 Lab requires only Patients and can be sold on its own.

## Phase 1 — Foundation (built 2026-11-08)

### What it does

- **Settings › Laboratory** (`/settings/laboratory`): a side menu with *General* and the nine lists.
- **General:** approval in one or two steps, same-person review, critical-call rule, partial reports, payment before collection, urgent/STAT turnaround, label stock. Stored now; each setting is enforced by the phase that adds its screen (approval in 7, payment gate in 5, labels in 5, partial reports in 8).
- **The lists:** departments, test categories, methods, units, containers (with cap colour), sample types (with usual container), rejection reasons, equipment (with branch), partner labs. Add, edit, switch off, remove with a reason; a row still in use cannot be removed.
- **Starter lists** (opt-in button) for departments, methods, units, containers, sample types and rejection reasons. Names only — no clinical values anywhere.
- **Sold on its own:** the `laboratory` module now requires only `customers`.

### Database changes (tenant databases only; the master DB is unchanged apart from `modules:sync`)

| Migration | Change |
|---|---|
| `2026_11_07_000000_create_lab_configuration_tables` | `lab_settings` (one row) and `lab_departments`, `lab_test_categories`, `lab_methods`, `lab_units`, `lab_containers`, `lab_sample_types`, `lab_rejection_reasons`, `lab_equipment`, `lab_outsource_labs`. Bigint keys, soft delete with `deleted_by`/`deletion_reason`, one `*_rules_check` per table, unique names ignoring case among live rows (units keep case). |
| `2026_11_07_000100_give_lab_settings_to_roles` | `laboratory.settings_manage` to roles holding `billing.manage_services`; `laboratory.settings_view` to those, to roles holding `laboratory.complete`, and to roles holding `laboratory.view` + `people.roles`. |

Both have a working `down()` (checked on the dev tenant: rolled back and re-applied).

### Permissions

| Tick | Scope | Opens |
|---|---|---|
| `laboratory.settings_view` | branch, settings | Settings › Laboratory (read) |
| `laboratory.settings_manage` | organisation, settings | Saving settings and every list |

Role templates: Branch manager gains `settings_view`; Head office gains both.

### API (all `/api/v1/tenant`, staff stack, `module:laboratory`)

| Method | URL | Tick |
|---|---|---|
| GET | `lab/settings` (allowed values in `meta`) | settings_view or settings_manage |
| PUT | `lab/settings` | settings_manage |
| GET | `lab/masters` (what each list is: fields, choices) | settings_view or settings_manage |
| GET | `lab/masters/{list}?search=&active_only=1` | settings_view or settings_manage |
| POST | `lab/masters/{list}` | settings_manage |
| PUT | `lab/masters/{list}/{id}` | settings_manage |
| DELETE | `lab/masters/{list}/{id}` body `{reason}` — 409 while in use | settings_manage |
| POST | `lab/masters/{list}/starter` — `meta.added` | settings_manage |

`{list}` is one of `departments, test-categories, methods, units, containers, sample-types, rejection-reasons, equipment, outsource-labs`; anything else is 404.

Example:

```http
POST /api/v1/tenant/lab/masters/departments
{"name": "Haematology", "code": "haem"}

201 {"data": {"id": 1, "label": "Haematology", "name": "Haematology", "code": "HAEM",
               "description": null, "is_active": true, "position": 1, ...},
     "message": "Department Haematology added"}

POST /api/v1/tenant/lab/masters/departments   {"name": "haematology"}
422 {"message": "...", "errors": {"name": ["Another department is already called haematology."]}}

DELETE /api/v1/tenant/lab/masters/departments/1   {"reason": "Merged"}
409 {"message": "Haematology is still used by 1 test category. Switch it off instead, or move it first."}
```

### Files

**New:**
- Migrations: `database/migrations/organization/2026_11_07_000000_create_lab_configuration_tables.php`, `2026_11_07_000100_give_lab_settings_to_roles.php`.
- Models: `app/Models/Tenant/Lab{Setting,Department,TestCategory,Method,Unit,Container,SampleType,RejectionReason,Equipment,OutsourceLab}.php`, `app/Models/Tenant/Concerns/IsLabList.php`.
- Registry and service: `app/Support/Laboratory/LabMasters.php`, `StarterLists.php`, `app/Services/Laboratory/LabMasterService.php`.
- HTTP: `app/Http/Controllers/Api/V1/Tenant/Lab{Master,Setting}Controller.php`, `app/Http/Requests/Api/V1/Tenant/{SaveLabMaster,UpdateLabSetting}Request.php`, `app/Http/Resources/Tenant/Lab{Master,Setting}Resource.php`, `routes/api/v1/laboratory.php`.
- Web: `resources/js/core/laboratory/settings/` (`api.ts`, `types.ts`, `pages/LabSettingsPage.tsx`, `components/GeneralPanel.tsx`, `components/MasterPanel.tsx`), `public/vendor/css/laboratory.css` (`.lab-`).
- Tests and docs: `tests/Feature/Api/V1/Tenant/LabConfigurationTest.php`, `docs/architecture/laboratory.md`, this file.

**Changed:**
- `app/Support/Modules/ModuleRegistry.php`: Lab requires `customers`, plus 2 ticks.
- `app/Support/Modules/ScreenTicks.php`: `LAB_SETTINGS`, `LAB_LISTS`.
- `app/Support/Roles/RoleTemplates.php`
- `routes/api/v1.php`: one `require`.
- `resources/js/app/tenant-router.tsx`, `tenant-navigation.ts`
- `resources/views/app.blade.php`: stylesheet.
- `tests/Feature/Api/V1/Platform/OrganizationModuleTest.php`: Lab granted with Patients alone.

### Testing

- `LabConfigurationTest`: 17 tests, 278 assertions, all pass. They cover:
  - settings defaults and validation
  - every list's add, edit and read
  - duplicates ignoring case (units keep case)
  - category unique within its department
  - in-use rows refused for removal
  - starter list idempotence
  - nothing clinical seeded
  - unknown list returns 404
  - read and save as separate ticks
  - module switched off at a branch
  - organisation without Lab
  - equipment placed only at a reachable branch
  - cross-organisation isolation
  - the backfill migration
  - a lab-only organisation
- `OrganizationModuleTest` (21 incl. the new Lab-alone test), `RoleTemplatesTest`, `ModuleRegistryRequirementsTest`, `NothingIncludedByDefaultTest`, `BranchManagerTest`, `EveryMenuRowHasItsOwnTickTest`, `ModuleBillingTest`: pass.
- Browser: Playwright walkthrough on the dev Apollo tenant (visible Chrome) as Priya. Covered settings save, starter lists, add, duplicate refused, remove refused while in use, edit with a picker, remove with a reason, and settings reset. Screens checked at 1536 px and 820 px.

### Known issues

- Pre-existing failures, not caused by this phase:
  - `ModuleRegistryGrantableTest`: 2 tests. They expect `settings.*` to be refused at branch scope, but `'settings' => true` now lets branch roles hold them.
  - `NewClinicEventsTest::test_a_new_organization_starts_with_email_defaults_switched_off_and_no_lab_default`: expects 7 patient email templates, and the 2026-11-04 visit templates made it 10.
- Dev data: on Apollo, Priya's "Branch manager" role (role 10) was given `laboratory.settings_manage` for the walkthrough. Departments, containers and sample types now hold their starter lists (Serum → Gel separator tube). Remove either if not wanted.
- The full suite was not run for this phase (about 45 minutes). Focused suites only.

## Phase 2 — Test master (built 2026-11-08)

### What it does

- **Laboratory › Tests** (`/lab/tests`): every test the lab offers, filtered by department and status, searchable by name or code.
- **A test's own page** (`/lab/tests/new`, `/lab/tests/{id}`) has these sections:
  - details: name, code, short name, department, category, method, description, offered on/off
  - sample: type, container (filled from the type's usual one), volume, collection and processing instructions
  - what is read off it: values or a written report, plus the parameters editor
  - turnaround and price: hours, price, tax, HSN/SAC, sent out (partner lab, their charge)
  - after saving: reference ranges and branch prices
  - a sticky summary card with Save
- **Parameters:** every test has at least one (a written report may have none). Each is answered as a number (unit and decimals), text, a dropdown (answers as pills, each marked normal or abnormal) or positive/negative (with its normal answer). Rows can be reordered or removed; removed ones are soft-deleted with a reason.
- **Reference ranges** (own permission, `laboratory.ranges_manage`):
  - **Who a range covers:** sex (any/male/female) and an age band that keeps its unit (`18+ years`, `0–30 days`), with low, high, critical low and critical high. Text answers have a normal answer instead.
  - **Overlaps:** within one sex, no two ranges may cover the same age, so a patient always matches exactly one. "Anyone" is the fallback for a sex with no range of its own.
  - **No guessing:** a patient with no birth date matches only a range without an age band; no match means no range and no flag.
  - **Nothing seeded:** no ranges are pre-filled anywhere.
- **Branch prices:** a branch's own price (and tax) for a test. Someone who works at some branches may change only those branches' prices.
- **Order picker** (`GET lab/orderables`): active tests priced for the acting branch, ready for Phase 4's ordering screens.
- **Old price list moved:** `lab_test_catalog` rows became tests (one text parameter each, department "General"); branch rows became branch prices; order lines are linked to the test they became. The `lab-test-catalog` routes and controller were retired and the table frozen. Lab orders now price from the test master (`lab_test_id`), with the branch price where there is one.
- **Sidebar fix (you reported it):** the "Lab tests" row under *Coming soon* was a hard-coded placeholder that never opened anything, whatever the permissions. It now shows only for organisations without the Laboratory module. A real **Laboratory** group sits under Clinical, starting with **Tests**; each later phase adds its own row (Orders, Collection, Worklist…). Priya's `laboratory.view/order/process/complete` permissions have no web screen until Phases 4–6 build them.

### Database changes

| Migration | Change |
|---|---|
| `2026_11_08_000000_create_lab_test_master_tables` | `lab_tests`, `lab_test_parameters`, `lab_reference_ranges` (`age_min`/`age_max`/`age_unit`), `lab_branch_prices`; `lab_order_items.lab_test_id`. CHECKs on price/tax/turnaround/decimals/answer types/range ordering; codes unique ignoring case among live rows. |
| `2026_11_08_000100_move_lab_catalogue_into_the_test_master` | Moves `lab_test_catalog` as described above (dev Apollo had no rows). Reversible. |
| `2026_11_08_000200_give_the_test_master_to_roles` | `catalog_manage` to holders of `billing.manage_services`; `catalog_view` to those and to holders of `laboratory.settings_view`. `ranges_manage` goes to nobody automatically. |

### Permissions

| Tick | Scope | Opens |
|---|---|---|
| `laboratory.catalog_view` | branch, settings-filed | Laboratory › Tests (read) |
| `laboratory.catalog_manage` | organisation, settings-filed | Adding/editing tests, parameters and branch prices |
| `laboratory.ranges_manage` | organisation, settings-filed | Reference ranges and critical limits |

These are filed as Settings permissions so a branch role (a lab manager, a pathologist) can hold them, as with the medicine-master rule.

Role templates:
- Head office gains `catalog_view` and `catalog_manage`.
- Branch manager and Lab technician gain `catalog_view`.

### API (all `/api/v1/tenant/lab`, `module:laboratory`)

| Method | URL | Tick |
|---|---|---|
| GET | `tests?search=&department_id=&category_id=&active=&outsourced=&sort=` (paginated) | catalog_view / catalog_manage / ranges_manage |
| GET | `tests/{id}` (with parameters, ranges, branch prices) | same |
| POST, PUT | `tests`, `tests/{id}` (test and parameters in one body) | catalog_manage |
| DELETE | `tests/{id}` body `{reason}` | catalog_manage |
| PUT | `tests/{id}/prices` body `{prices:[{location_id, price, tax_percent}]}` | catalog_manage |
| GET, PUT | `parameters/{id}/ranges` body `{ranges:[{sex, age_min, age_max, age_unit, low, high, critical_low, critical_high, normal_text, display_text}]}` | read: catalog ticks; write: ranges_manage |
| GET | `orderables?search=&location_id=` | laboratory.order / catalog_view / catalog_manage |

Example of the overlap refusal:

```http
PUT /api/v1/tenant/lab/parameters/4/ranges
{"ranges": [{"sex":"female","age_min":18,"age_unit":"years","low":"12"},
            {"sex":"female","age_min":15,"age_unit":"years","low":"11"}]}

422 {"errors": {"ranges.1.age_min": ["Ranges 1 and 2 both cover the same ages (Female, 18+ years / Female, 15+ years). Within one sex, each age must have one range."]}}
```

### Files

**New:**
- Migrations: the three above.
- Models: `app/Models/Tenant/Lab{Test,TestParameter,ReferenceRange,BranchPrice}.php`.
- Services: `app/Services/Laboratory/{LabCatalog,ReferenceRanges}.php`.
- HTTP:
  - `app/Http/Controllers/Api/V1/Tenant/Lab{Test,ReferenceRange,Orderable}Controller.php`
  - `app/Http/Requests/Api/V1/Tenant/Save{LabTest,LabReferenceRanges,LabTestPrices}Request.php`
  - `app/Http/Resources/Tenant/Lab{Test,TestParameter,ReferenceRange}Resource.php`
- Web: `resources/js/core/laboratory/tests/` (`api.ts`, `types.ts`, `money.ts`, `pages/LabTest{List,Form}Page.tsx`, `components/{FormSection,ParametersEditor,RangesCard,BranchPricesCard}.tsx`).
- Tests: `tests/Feature/Api/V1/Tenant/LabTestMasterTest.php`.

**Changed:**
- `LabOrders` (prices from the test master), `LabOrderItem`, `SaveLabOrderRequest`, `LabOrderItemResource`, `LabTestCatalog` (marked frozen).
- `LabMasters` and `LabMasterService`: lists in use by tests are not removable.
- `ModuleRegistry`, `ScreenTicks`, `RoleTemplates`.
- Routes: `routes/api/v1.php` (the catalogue routes removed) and `routes/api/v1/laboratory.php`.
- `tenant-router.tsx`, `tenant-navigation.ts`, `laboratory.css`.
- `ModuleBillingTest`: fixtures moved to `LabTest`.

**Removed:** `LabTestCatalogController.php`.

### Testing

- `LabTestMasterTest`: 15 tests, all pass. They cover:
  - saving a test with its parameters
  - department, code uniqueness and parameter validation
  - keeping, renaming and removing parameters on edit
  - removal leaves the menu
  - list filters and the order picker
  - ranges by sex and age band
  - overlaps and contradictions refused
  - the right range found for a patient, including the 18th birthday and a missing birth date
  - ranges as their own permission
  - nothing seeded
  - branch prices and branch reach
  - lists in use by a test cannot be removed
  - the old price list moving across
  - a lab-only organisation
  - cross-organisation isolation
- Also pass:
  - `ModuleBillingTest` (lab billing via `lab_test_id`)
  - `LabConfigurationTest` (17)
  - visit-workflow and patient-portal lab tests
  - `OrganizationModuleTest`
  - registry and role-template unit tests (only the 2 known stale `ModuleRegistryGrantableTest` failures)
- Browser: Playwright headless, then visible Chrome as Priya. Covered:
  - the sidebar Laboratory › Tests
  - adding a demo CBC with three kinds of parameter
  - the overlap refusal (nothing saved)
  - a branch price
  - the list, then removing the demo test with a reason

### Known issues

- Dev data, Apollo, for the walkthrough:
  - Priya's role 10 was also given `catalog_manage` and `ranges_manage`.
  - The units starter list was loaded.
  - The demo test was created and removed (soft-deleted with a reason).
- The shared dropdown and row menu close on any page scroll. That is fine for people, but scripts must scroll before clicking.

## Lab work screens from the user's reference design (built 2026-11-08)

You sent two reference images (a lab dashboard, a test list, add test, test details, reports and a report print). You chose to build three of them now, on the real orders already in the system, with white cards and colour only on icons, chart lines and chips. "Add test" follows in Phase 4, and reports and print in Phase 8, in the same design.

### What it does

- **Laboratory › Dashboard** (`/lab/dashboard`):
  - **KPI cards:** four white cards (Tests requested, Completed, Pending reports, Cancelled), each with its change on the period before and a trend line.
  - **Test Overview:** requested against completed, over 7 days, 30 days, 3 months or 12 months.
  - **Split chart:** tests by department. Where no department is known it shows most requested tests instead (Apollo's existing tests were typed by hand, so they have none).
  - **Recent Test Requests** table.
  - **Quick Actions:** only to screens that exist and that the person may open.
  - **Scope and money:** branch-scoped. No money on it (lab bills stay on the billing screens).
- **Laboratory › Tests** (`/lab/orders`, which also makes the OPD's old `/lab/orders` link real):
  - **Layout:** every test request, with status tabs and live counts (All / Pending / Processing / Completed / Cancelled).
  - **Filters:** department and date filters, and search by patient name, ID, phone, test or request number.
  - **Columns:** patient with photo and ID, tests ("+n more"), department, status chip, sample type, ordered on, and a view button.
- **Test Details** (`/lab/orders/{id}`):
  - **Header:** the patient (photo, ID, age, sex) with the status and a link to the patient record.
  - **Test Information:** the request's facts, with a Result Summary (all within range, N outside range, or not in yet).
  - **Results:** a table with the flags.
  - **History:** requested, taken up, signed off and cancelled, each with who and when.
  - **Read only:** result entry, verification and printing arrive in their phases.
- **Tabs, your change:** on both screens the tabs sit outside the card, drawn exactly like Billing's (`.inv-tabs`, shared through `LabTabs`).
- **Sidebar:** Laboratory now holds Dashboard, Tests and Test Catalogue (the test master was renamed from "Tests", so the two aren't confused).

### Backend

| Change | Detail |
|---|---|
| `GET lab/dashboard?range=7d\|30d\|3m\|1y` | `LabDashboardController` + `LabDashboard` service; tick `laboratory.dashboard` (new, branch, screen) |
| `GET lab-orders` | new filters `search` (patient, ID, phone, test, number), `department_id`, `date_from`, `date_to`; loads tests' departments and samples |
| `GET lab-orders/counts` | per-status counts with the same filters (route sits before `{order}`) |
| `LabOrderResource` | `patient_age`, `patient_gender`, `patient_photo_url`, `departments`, `sample_types`, `abnormal_count`, `cancelled_by_name` |
| `LabOrderItemResource` | `department_name`, `sample_type_name` |
| Migration `2026_11_08_000300_give_the_lab_dashboard_to_roles` | dashboard to holders of `laboratory.process`, and of `laboratory.view` + `people.roles` |
| Role templates | Branch manager and Lab technician gain `laboratory.dashboard` |

### Files

**New:**
- `app/Services/Laboratory/LabDashboard.php` and `app/Http/Controllers/Api/V1/Tenant/LabDashboardController.php`.
- The migration above.
- Web:
  - `resources/js/core/laboratory/dashboard/{api.ts,pages/LabDashboardPage.tsx}`
  - `resources/js/core/laboratory/orders/{api.ts,types.ts,status.tsx,pages/LabOrderListPage.tsx,pages/LabOrderPage.tsx}`
  - `resources/js/core/laboratory/shared/LabTabs.tsx`
- `tests/Feature/Api/V1/Tenant/LabWorkScreensTest.php`.

**Changed:**
- `LabOrderController`, `LabOrderResource`, `LabOrderItemResource`.
- Routes: `routes/api/v1.php` (counts route) and `routes/api/v1/laboratory.php`.
- `ModuleRegistry`, `RoleTemplates`.
- `tenant-router.tsx`, `tenant-navigation.ts`, `laboratory.css`.
- The Test Catalogue page titles.

### Testing

- `LabWorkScreensTest`: 7 tests, all pass. They check:
  - exact dashboard figures, branch-scoped (4 requested, 300 % up on the period before, 1 pending, 2 completed, 1 cancelled, departments and top tests, recent order)
  - the owner sees both branches
  - the range is validated
  - the dashboard needs its own tick and the module
  - list search by patient and test, department, dates and status
  - tab counts with filters
  - details with age, sex, department, sample and flags
  - another branch's request refused
- Browser (headless, as Priya, Apollo's real data):
  - the dashboard: 779 requested in 30 days, 726 completed, 56 pending in 38 requests
  - the list tabs: 8,348 / 38 / 0 / 8,310 / 0
  - the details and results of LAB-03983
  - card rows checked level
- **Not finished:** the cross-check of the other suites that read lab orders (`ModuleBillingTest`, visit workflow, patient portal and family, patient records) was stopped part-way at your request, and still needs running.

### Live updates (your question)

- **OPD board:** re-reads every 30 seconds.
- **Smart Queue and the pharmacy counter's "Patients from OPD" panel:** instant pushes over Reverb, with a 30-second fallback.
- **Pharmacy and organisation dashboards:** read once when opened.
- **The lab dashboard and test list:** currently read once when opened, like the pharmacy dashboard.

## Doctor's Investigations as lab orders (D1, built 2026-11-08 at your ask)

You asked how a doctor assigns a test. Until now the consultation's Investigations box only saved a typed note; nothing reached the lab.

### What it does now

- **When it applies:** where the Laboratory module runs and the doctor holds "Order tests at a consultation" (`laboratory.order` + `laboratory.view`).
- **Adding tests:** the box becomes an order editor. Each test is searched in the **Test Catalogue** (name, code, department, sample) or kept as typed. Each line has a note ("Fasting").
- **No separate send button, at your request:** **Save notes** (and **Complete consultation**) save the new tests onto the visit's lab order. More tests are added to that same order while the lab has not taken it up, and go on a new order after that.
- **What the doctor sees:**
  - The tests stay listed **by name** in the Investigations line ("Blood Test, Lipid Profile · 1 not saved yet").
  - Each test shows its lab status.
  - A test can be taken off while the lab has not started, unless it is the order's only test.
- **The lab's side:** each test appears on **Laboratory › Tests**, priced from the catalogue (a typed test is not charged). The doctor's note shows on Test Details.
- **Old typed notes:** tests typed into the old note box wait in the editor and go with the next save.
- **Other screens keep reading the consultation's list:** the server keeps the consultation's own investigations list in step with the orders. That keeps the patient file, "My investigations", the doctor's day and the visit-completion rule working without changes.
- **Where it stays a note:** branches without Lab, and doctors without the permission, keep the old typed box.

### Backend

| Change | Detail |
|---|---|
| Migration `2026_11_08_000400_add_instructions_to_lab_order_items` | `lab_order_items.instructions` varchar(191): the doctor's note per test, apart from the bench's `notes` |
| `SaveLabOrderRequest` | `items.*.instructions`, `items.*.replaces` (the typed note a test now is) |
| `LabOrders` | stores the note; `mirrorIntoConsultation()` after create, change, cancel line and cancel order; a typed line is dropped once sent (matched by what the screen said it replaced, or the test's name, code or short name) |
| `LabOrderItemResource` | `instructions` |

### Web

- New: `resources/js/core/laboratory/ordering/{api.ts,LabOrderEditor.tsx}`.
- Changed:
  - `opd/components/ConsultationPanel.tsx` (lab mode, Save sends tests, Complete sends tests first)
  - `opd/types.ts`
  - `orders/pages/LabOrderPage.tsx` (doctor's note)
  - `laboratory.css`

### Editor redesigned (your feedback: "design is not looking good")

The first editor was a bare table: a 44px note box in a 34px row, the ✕ floating away from the name, a faded bin, amber text twice. It is now laid out like the prescription above it:
- a quiet note on top;
- one bordered row per test, all on the same grid (test, note for the lab, status, remove), the header lined up with it;
- 34px inputs, the ✕ beside the picked name, the catalogue facts under it ("BGRP · Haematology · Whole blood");
- a test the doctor can't take off simply has no bin;
- a quiet line at the bottom on what Save notes does.

On a phone the test takes its own line, with the note, status and remove under it. Checked in light and dark.

### Test Catalogue lives in Settings › Laboratory (your ask)

The test master is set-up, so it sits with the lab's other set-up, as a **tab of Settings › Laboratory** (`/settings/laboratory?section=tests`, under a "Catalogue" heading between General and the lists), not as a Settings row of its own.
- **Sidebar:** Settings has one **Laboratory** row. It opens for the settings ticks *or* the catalogue ticks.
- **Who sees what:** someone with only the catalogue ticks sees just the catalogue tab; General and the lists need the settings ticks.
- **Unchanged:** a test still opens its own page (`/settings/lab-tests/:id`, crumbs Settings › Laboratory › Test Catalogue). The old list address `/settings/lab-tests` forwards to the tab. The Laboratory group keeps the daily screens (Dashboard, Tests).

### Dev data added at your ask

Apollo's Test Catalogue now has **17 common tests** to try on patients:
- CBC, ESR, Blood Group & Rh, FBS, PPBS, HbA1c
- Lipid Profile, LFT, KFT, Thyroid Profile
- Urine Routine & Microscopy, Dengue NS1, Malaria Antigen, Widal
- CRP, Vitamin D, Vitamin B12

Each has its parameters, units, sample, tube and turnaround.
- **Prices are demo figures:** change them in Settings › Laboratory › Test Catalogue.
- **No reference ranges were added:** normal ranges are for your pathologist to enter, under the ranges permission. Until then, results carry no high/low flag.

### Testing

- `LabDoctorOrderingTest`: 3 tests, all pass. They check:
  - catalogue and typed tests sent with notes, priced from the catalogue
  - typed consultation lines replaced, by code and by what the screen said
  - more tests on the same order, a test taken off, and the consultation kept in step
  - "Save notes" without the list leaves the lab's lines alone
  - the order locked once the lab takes it up
  - only the visit's own doctor can order
- Browser (as Dr. Anjali): the editor, the catalogue search and the waiting state. Checked without sending anything, so your own test visit was not touched. You had already ordered LAB-08352 "Blood Test" on Rekha Chaurasia's visit with it.
- **Still to do:** the hms_staff (phone) consultation screen still has the typed box. It needs the same change in the Flutter app.

## Next

Phase 3 — Packages and profiles: `lab_packages` + package tests + package branch prices, the duplicate-test billing rule (R1–R4) in `LabPricing::quote`, and the Packages screen. Waiting for approval. Phase 4 also needs one answer: whether a lab-only customer must buy Billing to charge for tests (see "Sold on its own" in the design doc).
