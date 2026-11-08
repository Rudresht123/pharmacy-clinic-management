# Laboratory (LIS): architecture analysis and implementation plan

Approved 2026-11-07. This is the design record; what has been built so far, phase by
phase, is in [DEVELOPMENT_PROGRESS.md](../../DEVELOPMENT_PROGRESS.md) at the repo root.

## Context

The organisation wants a full Laboratory Information System inside the HMS. The code already has a **thin lab**, built in Oct 2026, that is real and tested on the backend but has no web screens. This plan grows that lab into a full LIS. It does not build a second, parallel lab next to it.

### Sold on its own (requirement added 2026-11-07)

The laboratory must be sellable as a module by itself, to a customer who wants only a lab (a diagnostic centre with no OPD and no pharmacy). Every phase is built and tested against that case:

- The `laboratory` module requires only `customers` (the patient register). Every organisation also holds Branches and Configuration, which it needs for set-up anyway.
- Nothing in a lab screen may depend on Appointments, Prescriptions, Pharmacy or the Queue. Doctor ordering at a consultation is an extra that appears only where Appointments also runs.
- Each phase carries a "lab-only organisation" test (Phase 1: `LabConfigurationTest::test_a_lab_only_organisation_sets_up_its_lab`).
- **Still open, for Phase 4:** whether a lab-only customer must also buy Billing to charge for tests, or the lab counter takes payment with the Laboratory module alone.

### Changes made while building (recorded so the design and the code agree)

- **Phase 2, age bands keep their unit.** Ranges store `age_min`, `age_max`, `age_unit` (days/months/years) instead of `age_*_days`. They are compared in completed units, so a patient turns 18 on their birthday whatever the leap days.
- **Phase 2, overlaps are per sex.** Two ranges for the same sex may not cover the same age. `any` is the fallback for a sex with no range of its own, rather than a competing range. Every patient matches one range or none, with no "narrowest wins" rule.
- **Phase 2, test permissions are settings-filed.** `catalog_view` (branch), `catalog_manage` and `ranges_manage` (organisation) carry `'settings' => true`, so a branch lab manager or pathologist can hold them, the same exception the lab settings use.
- **Phase 2, sidebar.** A real **Laboratory** group under Clinical holds the lab's screens as they arrive (Tests first). The old "Coming soon › Lab tests" placeholder shows only where the module is not sold.
- **Phase 2, test and parameters in one save.** `POST/PUT lab/tests` takes the parameters in the same body, rather than a separate `PUT tests/{id}/parameters`, so a test is never saved without what is read off it.

Decisions you made in this session (2026-11-07), which the design follows:

1. **D1:** The doctor's **Investigations** box becomes real lab orders wherever Lab runs at the branch. Tests and packages come from a searchable list, and typed tests are still allowed. At a branch without Lab, it stays a free-text note.
2. **D2:** An OPD visit **stops waiting on the lab once its samples are collected**, not when the report is final.
3. **D3:** A setting **"Collect payment before sample collection"**, off by default. STAT orders and credit billing are exempt.
4. **D4:** The **Laboratory module requires only `customers`**, so a standalone diagnostic lab can buy it. Today it requires `appointments`.

Phase 0 (this analysis) is done. Nothing is implemented until you approve. After approval, only **Phase 1** is built. Then I stop and ask before Phase 2.

---

## 1. Existing architecture (what the code actually does)

| Area | Reality in this codebase |
|---|---|
| Stack | Laravel 12, PHP 8.2, PostgreSQL. React 19 + TS + react-query + react-table (no chart lib, hand-rolled SVG charts). Flutter apps `hms_mobile` (patient) and `hms_staff` (staff), each with its own `packages/hms_shared` copy |
| Packages that matter | `dompdf/dompdf` (PDFs), `endroid/qr-code` 5.x (QR), `laravel/reverb` (realtime), `sanctum`, `knuckleswtf/scribe` (API docs). **No barcode library** |
| Tenancy | One Postgres DB per org (`hms_<org>`), master `hms_software`. Every tenant model has `protected $connection = 'organization'`. `TenantConnectionService::connect/run`. Tenant resolved by `ResolveTenantFromSession` / `ResolveTenantFromHeader` (`X-Organization`). Jobs carry `organizationId` and use `run()`. ClinicEvents dispatch after commit and re-check the DB. `tenants:migrate` loops all tenants and re-runs the idempotent seeders |
| Master DB | `modules`, `organization_modules` (grants), plans and subscriptions. **No LIS table belongs in master.** The `laboratory` row is upserted from `ModuleRegistry` by `modules:sync` |
| Permissions | **There is no PermissionService.** The one authority is `App\Services\Permissions\Permission::allows()`. It checks three levels: org grant → branch runs module (`location_modules`) → capability held (org role ∪ branch role − branch removals − user denies). Routes use `module:<key>` + `permission:a,b` (ANY-of, `EnsureTenantCan`), with shared reads via `ScreenTicks::gate()`. Record checks use Policies + `AsksAboutBranch::mayAt()`, called through `AuthorizesTenantUser` (`Gate::forUser`). Acting branch comes from `X-Branch-Id` (`ResolveActingBranch`). Lists are narrowed by hand with `TenantBranchAccess::allowed()` |
| Conventions | **bigint `id()` PKs everywhere (no UUIDs)**, no `organization_id` columns, branch column `location_id`. Status is a `string` plus one CHECK per table named `{table}_rules_check`. Money `decimal(12,2)`. Snapshots of prices and names. Per-act `*_at`/`*_by` columns. Soft delete + `deleted_by`/`deletion_reason` with `HasDeletionAudit`, `ForbidsForceDelete`, `RecordsHistory`. Validation uses `Rule::exists(Model::class)`, never the string form (that hits master). Eloquent only, no `DB::table` in app code |
| API | `BaseApiController` `ok/created/paginated/fail`, `HandlesTableQueries` (page, per_page ≤ 100, search ILIKE, sort). Errors: 422/403/404/409 (`ConflictHttpException`). `Idempotency-Key` on money POSTs. `Save*Request`, flat snake_case Resources with an `available{}` map. Big route areas live in their own file (`routes/api/v1/queue.php` is `require`d inside the tenant stack) |
| Numbering | (a) `hms_document_number('LAB', seq)` → `LAB-00001` (lab orders today). (b) Gapless **per-branch, per-financial-year** series (`number_series`, `hms_series_number()`, BEFORE INSERT trigger) → `GGN/INV/26-27/00001`. You chose (b) for invoices, receipts and pharmacy |
| Audit | `activity_logs` is append-only (trigger) and written by `RecordsHistory`. **Clinical free text is deliberately kept out of it** (`historyExcept`) |
| Documents | `DocumentTypes` registry → `DocumentService::generate()` → `DocumentPayload` → `DocumentHtml` → `PdfRenderer` (dompdf). The file is stored privately and indexed as `patient_documents`. Templates are versioned (`document_template_versions`). `document_rules` make PDFs automatically on ClinicEvents. Downloads stream through `PatientDocumentController` with a category check. **No `lab_report` type yet** |
| Notifications | `ClinicEvents` → `ClinicEventDispatcher` (after commit) fans out to DocumentAutomation, RealtimeBroadcaster, AutomationSender and DoctorNotifier. Rules + templates (seeded by `OptionalModuleTemplateSeeder`), recipients set on Communication › Recipients. Email, WhatsApp, FCM push, patient bell (`PatientNotifications`) |
| Tests | Real Postgres `hms_software_testing`. `TenantTestCase` (`provisionOrganization`, `grantModule`, `setStaffCapabilities`, `placeStaffAt`, `onTenant`, `allowPartPayments`). The full suite takes about 45 minutes (background). Known red tests are listed in memory |

### The lab that already exists (to extend, not duplicate)

| Piece | Path | State |
|---|---|---|
| Tables | `lab_orders`, `lab_order_items` (`2026_10_08_000100`), `lab_test_catalog` + price snapshots (`2026_10_10_000000`), `invoices.lab_order_id` + `kind='lab'` + one live bill per order (`2026_10_22_000000`) | Real |
| Models | `LabOrder` (pending → in_progress → completed/cancelled), `LabOrderItem` (pending/completed/cancelled, one free-text result per test), `LabTestCatalog` (price list, branch override rows) | Real |
| Service | `app/Services/Laboratory/LabOrders.php` (create/update/start/record/complete/cancel, row locks, billing hooks, visit refresh, `LAB_REPORT_READY`) | Real |
| Policy | `LabOrderPolicy`: **only the visit's own doctor can raise an order** | Too narrow |
| API | `lab-orders` index/show/store/update/process/result/cancel/complete/bill, `appointments/{id}/lab-orders`, `lab-test-catalog` (writes under `billing.manage_services`), `portal/lab-reports` | Real |
| Billing | `BillingTriggerResolver::onLab*/billLabOrder`, `labLines()` (`source_type=lab_test`, price snapshot), `billing_settings.lab_trigger` (default `manual`) | Real |
| Visit | `appointments.status=awaiting_lab` / `next_action=laboratory`. `VisitWorkflow::pending()['laboratory']` = order outstanding | Real |
| Queue / TV | `LabQueue`, `LabQueueUpdated`, `BranchLabQueueChannel`, TV `LabBoard` (Waiting for sample / In progress / Report ready). The broadcast fires only where the Smart Queue module runs | Real |
| Permissions | module `laboratory` (requires `appointments`): `laboratory.view` (screen), `.order`, `.process`, `.complete`. Role template `lab_technician`. Doctor, receptionist and branch manager hold `laboratory.view` | Real |
| Portal / app | Patient app lab list and detail (`PortalLabReportResource`), family history lab tab, staff-app doctor lab cards | Real |
| Web UI | **None.** Sidebar "Lab tests" is `soon: true`. `appointments/workflow.ts` links to the dead route `/lab/orders`. Patient Records `LabTab` is read-only | Missing |

### Problems found in the current lab (fixed inside the phases below)

1. **Nothing creates lab orders.** No web or app screen calls `POST lab-orders`. The consultation "Investigations" box saves free text in `consultations.investigations` (jsonb), and "My investigations" reads that free text.
2. **Walk-in and referral orders are impossible.** `lab_orders.appointment_id` and `doctor_id` are NOT NULL, and the Policy allows only the visit's own doctor.
3. **Results have no safeguards.** `LabOrders::record()` can overwrite a completed result or revive a cancelled line. `cancelItem()` can cancel a completed line and overwrites `notes` with the reason. An order whose tests are all cancelled can still be "completed".
4. **No result history.** Result fields are in `historyExcept`, and nothing else records them.
5. **Patients see unchecked results.** The portal returns results for every status (its docblock says "completed only"), so the patient app shows results nobody has checked.
6. **Catalogue gaps.** `lab_test_catalog` update and destroy don't check the existing row's branch, and `code` isn't unique.
7. **Export overcounts.** `Exports/Datasets/Patients/LabOrders` sums the prices of cancelled tests too.
8. **Stale docs.** `docs/architecture/roles-and-permissions.md` and `document-templates.md` say "no lab module".

---

## 2. What to reuse (no duplicates)

| Need | Reuse |
|---|---|
| Orders, numbering, visit gating | Extend `lab_orders` / `lab_order_items` + `LabOrders` service (keep the URLs `lab-orders/*`) |
| Charges, discounts, payments, refunds, receipts | `BillingService` (draw/resync/collect/refund/cancel), `BillingTriggerResolver` lab methods, `invoices.kind='lab'`, the web `PaymentDialog`, `RefundModal`, `InvoicePage`, Finance "Laboratory" tab. **No new billing engine** |
| Branch/FY numbers | `number_series` + `hms_series_number()` + trigger (the pharmacy recipe in `2026_10_22_000100/000200`) |
| PDFs | `DocumentTypes` + `DocumentService::generate` + templates + `patient_documents` + `document_rules`. QR via `Services/Documents/QrCode` |
| Notifications | `ClinicEvents` + `AutomationSender` + `OptionalModuleTemplateSeeder` + Recipients screen + `DoctorNotifier` + `PatientNotifications` |
| Realtime and TV | `QueueBroadcaster::labChanged`, `LabQueue`, TV `LabBoard`, `BranchLabQueueChannel` |
| Audit and deletion | `RecordsHistory`, `HasDeletionAudit`, `ForbidsForceDelete`, append-only trigger pattern from `activity_logs` |
| Permissions | `ModuleRegistry` capabilities, `ScreenTicks`, `RoleTemplates`, backfill pattern `2026_11_02_100100_give_smart_queue_to_roles.php` |
| Exports and analytics | Export Center datasets (`ExportCatalog`), the `FinanceReports` "money from payment rows only" rule |
| Web UI | `PageHeader`, `Card`, `DataTable` + `useServerTable`, `createResourceApi/Hooks`, `useApiForm`, `SearchableSelect`, `StatTiles`, `AreaChart`/`BarList`/`DonutChart`/`ReportCharts`, `Tabs`, `Avatar`, `ReasonDialog`, `ActionDialog`. Pharmacy pages as layout references. Medicine search popup from `PrescriptionEditor` (`.rx-pop`) for the test picker |
| Mobile | Staff `features/pharmacy` as the template for `features/laboratory`. Patient `lab_reports_*` pages. `Navigation.all` in both `hms_shared` copies |

---

## 3. Proposed laboratory architecture

```
                              MASTER DB (hms_software)
   modules['laboratory'] (requires: customers) ── organization_modules (grant)
                                   │  Permission::allows (grant → branch → capability)
───────────────────────────────────┼──────────────────────────────────────────────────
                              TENANT DB (one per organization)
 Configuration (org-wide)        Catalogue (org-wide, branch prices)
  lab_settings, departments,      lab_tests ─< lab_test_parameters ─< lab_reference_ranges
  categories, sample types,       lab_packages ─< lab_package_tests
  containers, methods, units,     lab_branch_prices
  rejection reasons, equipment,
  partner labs, signatories
                 │                         │ (snapshots at order time)
 Workflow (branch-owned, location_id)      ▼
  lab_orders ─< lab_order_items ─< lab_results ─< lab_result_revisions (append-only)
      │   └─< lab_order_packages      │  └─ lab_critical_alerts
      │                               ├─< lab_result_reviews (append-only)
      ├─< lab_samples ─< lab_sample_items >─┘   └─ lab_amendments
      │      └─< lab_sample_events (append-only)
      ├─< lab_reports ─< lab_report_items        (PDF → patient_documents)
      └── invoices (kind=lab) ─< invoice_items (lab_test | lab_package)
```

**Backend layout:**
- Models: `app/Models/Tenant/Lab*.php`.
- Services: `app/Services/Laboratory/`.

  | Service | Owns |
  |---|---|
  | `LabMasters` | registry for the config CRUD |
  | `LabCatalog` | tests, parameters, packages |
  | `ReferenceRanges` | range resolution |
  | `LabPricing` | quote + dedupe rule |
  | `LabOrders` | the existing service, extended |
  | `LabSamples` | collection, receiving, rejection |
  | `LabResults` | entry, flags, critical detection |
  | `LabReview` | verification, approval, amendments |
  | `LabReports` | report issue + PDF snapshot |
  | `LabAnalytics` | dashboard and reports |
  | `LabBarcodes` | barcodes and labels |

- Controllers: `app/Http/Controllers/Api/V1/Tenant/Lab*Controller.php`.
- Requests: `Save*Request`.
- Resources: `app/Http/Resources/Tenant/Lab*Resource.php`.
- Policies: `LabOrderPolicy` (widened), `LabSamplePolicy`.
- Routes: new `routes/api/v1/laboratory.php`, `require`d inside the tenant stack like `queue.php`.

**Frontend:**
- Code in `resources/js/core/laboratory/{api.ts,types.ts,pages,components}`. This follows practice: every module lives under `core/`, even though `ARCHITECTURE.md` says `modules/`.
- Styles in a new `public/vendor/css/laboratory.css` with prefix **`.lab-`** (checked free), added to `app.blade.php`.

**Status model** (the order keeps its 4 statuses so VisitWorkflow, the portal, the TV board and existing tests keep working):

| Level | States |
|---|---|
| Order | `pending` (nothing collected) → `in_progress` (≥1 sample collected) → `completed` (every live test final) / `cancelled` |
| Sample | `pending` → `collected` → `in_transit`? → `received` (accession no.) · `rejected` (terminal; a recollection is a new sample row) |
| Test item | `pending` (draft results allowed) → `entered` → `verified` (only when 2 levels) → `completed` (= approved/final, locked) · `cancelled` · completed → *amend* → `entered` … → `completed` (new report version) |

---

## 4. Database design (all TENANT tables; master DB unchanged except the `modules` row via `modules:sync`)

Shared conventions for every table below:
- `id` bigint PK.
- `timestamps`.
- Masters and catalogue: `softDeletes` + `deleted_by` (FK users nullOnDelete) + `deletion_reason` varchar(500) + traits `RecordsHistory, HasDeletionAudit, ForbidsForceDelete`. Clinical rows are never hard-deleted.
- User FKs on `*_by` columns are `nullOnDelete`.
- Record FKs are `restrictOnDelete`.
- Uniques are **partial** (`WHERE deleted_at IS NULL`) and case-insensitive where they are names or codes.

### 4.1 Configuration (Phase 1)

```
lab_settings  (singleton: UNIQUE INDEX lab_settings_singleton ON ((true)))
  approval_levels            smallint  NOT NULL default 1   CHECK IN (1,2)   1=enter→approve, 2=enter→verify→approve
  allow_self_review          bool      NOT NULL default false  same person may enter AND verify/approve
  collect_requires_payment   bool      NOT NULL default false  (D3) STAT + credit billing exempt
  release_partial_reports    bool      NOT NULL default true   issue a report as soon as some tests are final
  critical_call_required     bool      NOT NULL default true   approval refused until a critical value's call is logged
  urgent_tat_hours           smallint  NULL;  stat_tat_minutes smallint NULL   (override per-test TAT for those priorities)
  label_size                 varchar(12) NOT NULL default '50x25'  CHECK IN ('50x25','38x25','a4_sheet')
  updated_by FK users, timestamps

lab_departments        name varchar(120), code varchar(24) NULL, description text NULL, is_active bool, position int
                       UNIQUE lower(name), UNIQUE upper(code) WHERE code NOT NULL  (partial on deleted_at)
lab_test_categories    department_id FK lab_departments NULL, name, code NULL, is_active, position   UNIQUE (department_id, lower(name))
lab_methods            name varchar(120), description text NULL, is_active, position          UNIQUE lower(name)
lab_units              symbol varchar(32), name varchar(80) NULL, is_active, position          UNIQUE symbol (case-sensitive: mg/dL ≠ MG/DL)
lab_containers         name varchar(120), cap_colour varchar(20) NULL, additive varchar(60) NULL, volume_ml decimal(6,2) NULL, is_active, position
lab_sample_types       name varchar(120), code varchar(24) NULL, default_container_id FK lab_containers NULL,
                       handling_notes text NULL, is_active, position
lab_rejection_reasons  reason varchar(191), requires_recollection bool default true, is_active, position
lab_equipment          name, code NULL, manufacturer NULL, model NULL, serial_no NULL, department_id FK NULL,
                       location_id FK locations NULL (where it sits), is_active
lab_outsource_labs     name, code NULL, contact_person NULL, phone NULL, email NULL, address text NULL, notes text NULL, is_active
```

Masters are soft-deleted only. Deleting one that an active test or open order still uses is refused with 409. The service checks this, because FKs never fire on soft deletes.

**Starter lists are opt-in:** a "Load starter list" button per master (curated PHP list, idempotent). Nothing is auto-seeded, and **no clinical reference ranges are ever seeded**.

### 4.2 Test master (Phase 2)

```
lab_tests
  code varchar(24) NOT NULL (UNIQUE upper(code) partial) · name varchar(191) · short_name varchar(60) NULL
  department_id FK lab_departments NOT NULL · category_id FK NULL · sample_type_id FK NULL · container_id FK NULL · method_id FK NULL
  sample_volume varchar(40) NULL · description / collection_instructions / processing_instructions text NULL
  result_layout varchar(12) NOT NULL default 'parameters' CHECK IN ('parameters','narrative')   narrative = free-text report (e.g. histopathology)
  report_template text NULL   (narrative starting text)
  tat_hours smallint NULL · price decimal(12,2) default 0 · tax_percent decimal(5,2) default 0 · hsn_code varchar(16) NULL
  is_outsourced bool default false · outsource_lab_id FK lab_outsource_labs NULL · outsource_cost decimal(12,2) NULL
  is_active bool · position int · legacy_catalog_id bigint NULL (map from lab_test_catalog)
  CHECK price>=0, tax 0..100, outsource_cost>=0

lab_test_parameters                       every test has ≥1 parameter (a single-value test = one parameter)
  lab_test_id FK NOT NULL · name varchar(120) · code varchar(24) NULL (UNIQUE per test, partial) · section varchar(60) NULL
  result_type varchar(20) CHECK IN ('numeric','text','choice','positive_negative')
  unit_id FK lab_units NULL · decimals smallint NULL CHECK 0..6 · choices jsonb NULL ([{value, abnormal}])
  normal_value varchar(60) NULL (expected value for text/choice/pos-neg) · method_id FK NULL · is_required bool default true
  is_printed bool default true · position int
  CHECK (result_type <> 'choice' OR choices IS NOT NULL)

lab_reference_ranges
  lab_test_parameter_id FK NOT NULL · sex varchar(8) CHECK IN ('any','male','female')
  age_min_days int NULL · age_max_days int NULL  ([min, max) in days, entered in the UI as years/months/days)
  low / high / critical_low / critical_high  decimal(18,6) NULL · normal_text varchar(120) NULL · display_text varchar(120) NULL
  notes varchar(255) NULL · position
  CHECK low<=high, critical_low<=low, high<=critical_high, age_min<age_max (each when both set)
  overlap of (sex, age band) per parameter refused by ReferenceRanges service (sex 'any' overlaps both)

lab_branch_prices                         branch overrides only (prices are the one branch-level setting you allowed)
  location_id FK NOT NULL · lab_test_id FK NULL · lab_package_id FK NULL (Phase 3) · price decimal(12,2) · tax_percent decimal(5,2) NULL (NULL = inherit)
  CHECK exactly one of test/package · UNIQUE (location_id, lab_test_id) / (location_id, lab_package_id) partial
```

**Range resolution** (`ReferenceRanges::for($parameter, $sex, $ageDays)`):
- Matching: an exact sex match beats `any`, and the narrowest matching age band wins. If the age is unknown, only bands with no age limit match.
- If no range matches, there is **no flag**. The report prints the range only where one is configured.
- The sex and age used are snapshots taken on the order.

**Migrating `lab_test_catalog`** (one data migration, reversible):
- Org rows (`location_id` null) become `lab_tests`. Each gets one `text` parameter, a "General" department created if none exists, and `legacy_catalog_id` set.
- Branch rows whose code matches an org row become `lab_branch_prices`. A branch-only row becomes an org test plus its branch price.
- `lab_order_items.lab_test_id` is backfilled from `lab_test_catalog_id`.
- `lab_test_catalog` is frozen (no writes) and kept for rollback. The `lab-test-catalog` routes are replaced by `lab/tests`. No client calls them; `ModuleBillingTest` fixtures move to `LabTest`.

### 4.3 Packages (Phase 3)

```
lab_packages        code (UNIQUE upper, partial) · name · description text NULL · kind varchar(10) CHECK IN ('profile','package')
                    price decimal(12,2) · tax_percent decimal(5,2) · hsn_code NULL · is_active · position
lab_package_tests   lab_package_id FK · lab_test_id FK · position · UNIQUE (lab_package_id, lab_test_id)
```

**Billing rule for duplicates:**
- **R1:** A test is performed and reported **once per order**.
- **R2:** An individually selected test that is also in a selected package is **not charged**. It is listed under the package.
- **R3:** If two selected packages share tests, both packages are charged. The **later-added** package carries an automatic line discount equal to the shared tests' own prices, capped at that package's price, with the label "Includes CBC already in Full Body Checkup".
- **R4:** Prices are those of the acting branch, snapshotted at order time.

`LabPricing::quote()` applies these rules for the order screen and the doctor's picker, and the invoice uses the same rules.

### 4.4 Orders (Phase 4): changes to existing tables

```
lab_orders  (ALTER)
  appointment_id → NULL · doctor_id → NULL
  order_number varchar(20)→(64), new rows numbered by branch/FY series type 'lab_order' → GGN/LAB/26-27/00001 (old LAB-00001 kept)
  source varchar(12) NOT NULL default 'opd' CHECK IN ('opd','walk_in','referral')      (no IPD module exists; value not reserved)
  priority varchar(8) NOT NULL default 'routine' CHECK IN ('routine','urgent','stat')
  referred_by_name varchar(120) NULL · referred_by_facility varchar(160) NULL
  ordered_by FK users NULL · ordered_by_name varchar(120) NULL
  patient_sex varchar(8) NULL · patient_age_days int NULL   (snapshots for ranges and the report; the desk can type an age when DOB is missing)
  due_at timestamp NULL · collection_scheduled_at timestamp NULL
  cancelled_by → nullOnDelete unchanged; rules_check extended:
     source='opd' ⇒ appointment_id AND doctor_id NOT NULL;  source='referral' ⇒ referred_by_name NOT NULL
  index (location_id, priority, status), (location_id, order_date)

lab_order_packages  (NEW)  lab_order_id FK · lab_package_id FK · name_snapshot · code_snapshot · price_snapshot · tax_percent_snapshot
                           overlap_discount decimal(12,2) default 0 · status CHECK IN ('active','cancelled') · cancelled_at/by · sort_order
                           UNIQUE (lab_order_id, lab_package_id) WHERE status='active'

lab_order_items  (ALTER)
  lab_test_id FK lab_tests NULL (NULL = typed/freehand) · lab_order_package_id FK NULL (covered → not billed)
  department_id FK NULL (snapshot for worklists) · due_at timestamp NULL
  status CHECK widened: ('pending','entered','verified','completed','cancelled'); completed ⇒ completed_at (result_value no longer required)
  cancelled_at · cancelled_by · cancellation_reason varchar(500)   (stop overwriting notes)
  index (status, department_id)

invoice_items  (ALTER CHECK)  source_type adds 'lab_package'
number_series  (ALTER CHECK + hms_series_default_prefix + trigger)  types 'lab_order' (LAB), 'lab_accession' (ACC), 'lab_report' (LR)
```

### 4.5 Samples (Phase 5)

```
lab_samples
  sample_number varchar(24) NOT NULL UNIQUE  compact barcode id from hms_lab_sample_number(location): GGN26000123
                (branch code + FY + 6 digits, same gapless counter table; short enough for a 38mm Code128 label)
  accession_number varchar(64) NULL UNIQUE   given at receipt (series 'lab_accession')
  lab_order_id FK · location_id FK · customer_id FK (restated)
  sample_type_id FK NULL · container_id FK NULL · sample_type_name varchar(120) (snapshot / freehand)
  status varchar(12) CHECK IN ('pending','collected','in_transit','received','rejected')
  scheduled_for timestamp NULL · assigned_collector_id FK users NULL
  collected_at/by · collection_point varchar(120) NULL · volume varchar(40) NULL
  dispatched_at/by · transport_notes varchar(255) NULL · received_at/by
  rejected_at/by · rejection_reason_id FK NULL · rejection_notes varchar(500) NULL · recollection_of_id FK lab_samples NULL
  payment_override varchar(255) NULL (why collected unpaid: STAT) · labels_printed smallint default 0
  CHECK status↔timestamps (collected ⇒ collected_at …); no soft deletes, never deleted (ForbidsForceDelete + delete trigger)
  index (location_id, status), (lab_order_id)

lab_sample_items   lab_sample_id FK · lab_order_item_id FK · UNIQUE pair   (one live sample per item enforced in LabSamples under lock;
                   a rejected sample keeps its links, so history survives recollection)
lab_sample_events  APPEND-ONLY (trigger): lab_sample_id FK · event CHECK IN ('planned','label_printed','collected','dispatched',
                   'received','rejected','recollection_requested','sent_out','returned') · location_id · actor_id · actor_name · notes · occurred_at
```

Samples are planned automatically when an order is saved: live items are grouped by (sample type, container). Several tests share one tube; freehand tests get their own sample.

### 4.6 Results (Phase 6)

```
lab_results
  lab_order_item_id FK · lab_test_parameter_id FK NULL (NULL = freehand/legacy single value)
  parameter_name · parameter_code · section · result_type · unit varchar(32) · position     (all snapshots)
  value_text varchar(255) NULL · value_numeric decimal(18,6) NULL  (qualifiers like "<0.5" parsed: comparator kept in value_text)
  reference_range_id FK NULL · ref_low/ref_high/critical_low/critical_high decimal(18,6) NULL · reference_text varchar(120) NULL
  flag varchar(14) NULL CHECK IN ('normal','low','high','critical_low','critical_high','abnormal') · is_abnormal bool · is_critical bool
  remarks varchar(500) NULL · entered_by FK · entered_at
  UNIQUE (lab_order_item_id, lab_test_parameter_id) WHERE parameter NOT NULL; UNIQUE (lab_order_item_id) WHERE parameter IS NULL
  DB trigger: UPDATE/DELETE refused while the item is 'completed' unless SET LOCAL hms.lab_amend='on'
              (same guard style as hms.invoice_rewrite)

lab_result_revisions   APPEND-ONLY: lab_result_id · lab_order_item_id · stage CHECK IN ('submitted','correction','amendment')
                       value_before · value_after · flag_before · flag_after · reason varchar(500) NULL · changed_by · changed_by_name · changed_at
                       (draft edits before first submission are not revisions)
lab_critical_alerts    lab_result_id · lab_order_item_id · lab_order_id · customer_id · location_id · flag · value_text · detected_at
                       status CHECK IN ('open','communicated') · communicated_to varchar(120) · via varchar(20) · read_back bool
                       communicated_at/by · notes varchar(500)
lab_order_items (ALTER) assigned_to FK users · equipment_id FK · entered_at/by · technician_remarks text · interpretation text
                        is_critical bool · outsource_lab_id FK · outsourced_at · outsource_reference varchar(60)
                        outsource_cost_snapshot decimal(12,2) · outsource_result_at
```

The legacy result columns on items (`result_value`, `result_unit`, `reference_range`) are backfilled into `lab_results` rows. After that they are no longer written, but they are kept for rollback.

> **A deliberate exception:** clinical values *are* kept in append-only tables here. Elsewhere, free text is kept out of `activity_logs` so it can be corrected. For laboratory results, an unalterable correction trail is the clinical and regulatory requirement (ISO 15189 / NABL style). This history lives in lab tables, never in `activity_logs`.

### 4.7 Verification, approval, amendments (Phase 7)

```
lab_result_reviews  APPEND-ONLY: lab_order_item_id · level CHECK IN ('verification','approval') · action CHECK IN ('approved','sent_back')
                    remarks varchar(1000) NULL · actor_id · actor_name · created_at
lab_amendments      lab_order_item_id · lab_report_id NULL (the final report being corrected) · reason varchar(1000) NOT NULL
                    status CHECK IN ('open','closed') · opened_at/by · closed_at · new_report_id NULL
lab_order_items (ALTER) verified_at/by · approved_by (approved_at = completed_at) · amendment_count smallint default 0
```

### 4.8 Reports (Phase 8)

```
lab_signatories   user_id FK UNIQUE · display_name · qualification varchar(160) · registration_no varchar(60) · designation NULL
                  signature_file_id FK files NULL · is_active   (approving requires an active signatory profile)
lab_reports       report_number varchar(64) UNIQUE (series 'lab_report') · lab_order_id · location_id · customer_id
                  version smallint default 1 · supersedes_id FK lab_reports NULL · scope CHECK IN ('partial','complete') · is_amended bool
                  status CHECK IN ('issued','superseded') · payload jsonb (frozen render data) · signatories jsonb (snapshot)
                  patient_document_id FK patient_documents NULL · issued_at · issued_by       never updated except status→superseded; no deletes
lab_report_items  lab_report_id · lab_order_item_id · UNIQUE pair
```

Reprints render from the frozen `payload` and are marked "Duplicate copy". They are logged, not stored. An amended report is a new version with an "Amended" banner and the reason, and the previous version is marked `superseded`.

### 4.9 Indexes for queues and analytics

- `lab_order_items (department_id, status, due_at)`
- `lab_samples (location_id, status, collected_at)`
- `lab_results (lab_test_parameter_id, lab_order_item_id)` (for previous-result lookups via the order's `customer_id`)
- `lab_critical_alerts (location_id, status)`
- `lab_reports (lab_order_id, version)`

---

## 5. Entity relationship diagram

```
locations ─┬─< lab_orders >── customers            doctors ──< lab_orders (NULL for walk-in/referral)
           │      │  └── appointments / consultations (NULL unless source=opd)
           │      ├─< lab_order_packages >── lab_packages ─< lab_package_tests >── lab_tests
           │      ├─< lab_order_items >── lab_tests ─< lab_test_parameters ─< lab_reference_ranges
           │      │        │   ├── lab_departments / categories / methods / units / sample types / containers
           │      │        │   ├─< lab_results ─< lab_result_revisions        lab_results ── lab_critical_alerts
           │      │        │   ├─< lab_result_reviews     ├─< lab_amendments
           │      │        │   └── lab_outsource_labs, lab_equipment, users (assigned/entered/verified/approved)
           │      ├─< lab_samples ─< lab_sample_items >── lab_order_items ;  lab_samples ─< lab_sample_events
           │      │        └── lab_rejection_reasons, recollection_of → lab_samples
           │      ├─< lab_reports ─< lab_report_items >── lab_order_items ; lab_reports ── patient_documents
           │      └─< invoices (kind=lab, one live) ─< invoice_items (source lab_test → item, lab_package → order package)
           └─< lab_branch_prices >── lab_tests | lab_packages            lab_signatories ── users, files
```

---

## 6. Workflows

```
A. OPD (doctor) ── consultation: Investigations picker (tests/packages, live quote) ──► lab order (source=opd, pending)
   │ billing per billing_settings.lab_trigger (on order / on sign-off / manual)
   ├─► Collection queue: [pay first? D3 gate] ─► collect + print Code128 labels ─► order in_progress
   │        └─► VisitWorkflow: no sample left to collect ⇒ visit leaves awaiting_lab (D2) (unpaid bill still ⇒ awaiting_payment)
   ├─► Receiving: scan barcode ─► accession no. ─► accept │ reject
   ├─► Worklist (by department/priority/TAT): enter results (draft → submit) — flags + critical detection + previous results
   ├─► [approval_levels=2] Technical verification: verify │ send back (remarks)
   ├─► Pathologist approval (signatory): approve ─► item completed (locked) ─► report issued (partial or complete)
   └─► LAB_REPORT_READY ─► patient app / push / email / WhatsApp; doctor push; PDF in patient record & portal

B. Walk-in / referral ── Lab desk "New lab order": find/register patient · doctor or "Referred by" · tests/packages
   · priority · discount (billing.edit) ─► invoice drawn at once ─► PaymentDialog ─► collection … same as A

C. Rejected sample ── reject (reason from master, notes) ─► sample=rejected ─► new sample row (recollection_of) back in the
   collection queue · LAB_SAMPLE_REJECTED (patient: please return; doctor/desk) · for an OPD visit the visit waits again

D. Critical result ── value beyond critical limits ─► item.is_critical + lab_critical_alerts(open) ─► LAB_CRITICAL_RESULT push/email
   to doctor + lab manager ─► staff logs the call (to whom, read-back) ─► approval allowed only once communicated (setting)

E. Amendment ── final result wrong ─► laboratory.amend + reason ─► lab_amendments(open), item back to entered (hms.lab_amend)
   ─► re-verify/approve ─► report v2 (Amended banner, reason) · v1 superseded · patient/doctor told the report changed

F. Outsourced test ── test.is_outsourced ─► sample "sent out" (event) with partner lab + reference ─► external result typed into
   parameters and/or external PDF attached ─► normal verify/approve ─► outsource cost recorded for margin reports

G. Cancellation ── cancel a test or a whole order (laboratory.cancel + reason) ─► unpaid bill re-synced/cancelled; paid ⇒ refund
   through billing.refund (existing refund slip) · items already final cannot be cancelled (only amended)

H. Urgent/STAT ── priority sorts every queue first, due_at from STAT/urgent TAT, STAT exempt from the payment gate (logged)
```

---

## 7. Module dependencies and roadmap

```
P1 Config ─► P2 Tests/params/ranges ─► P3 Packages+pricing ─► P4 Orders(+core billing, doctor ordering)
                                                                  │
                    P5 Samples ◄──────────────────────────────────┘
                       │
                    P6 Results ─► P7 Verify/approve/amend ─► P8 Report PDF
                       │               │                        │
                    P9 Billing completion (refunds, outsourcing costs) ◄── P4
                    P10 Notifications ◄── P5, P6, P7, P8
                    P11 Dashboard + analytics ◄── P4…P9
                    P12 Flutter ◄── P4 (doctor ordering on the phone), P7/P8 (final-only results, PDF)
                    P13 Integration, security, regression ◄── all
```

| Phase | Scope (backend + web + tests in every phase) | Size |
|---|---|---|
| **P1 Foundation** | `lab_settings` + 9 masters; generic `LabMasters` registry + one `LabMasterController`; `LabSettingController`; ModuleRegistry `requires: ['customers']` (D4) + `laboratory.settings_view` (screen) / `laboratory.settings_manage` (org); Settings › **Laboratory** page with tabs; opt-in starter lists; `DEVELOPMENT_PROGRESS.md`, `docs/architecture/laboratory.md` | M |
| **P2 Test master** | `lab_tests`, parameters, ranges, branch prices, catalogue migration; `/lab/tests` list + big Test page (parameters editor; ranges editor gated by `laboratory.ranges_manage`); `GET lab/orderables`; ticks `laboratory.catalog_view/catalog_manage/ranges_manage` (+ backfill from `billing.manage_services`) | L |
| **P3 Packages** | packages + package tests + package branch prices; `LabPricing::quote` (R1–R4); `/lab/packages` pages | M |
| **P4 Orders** | lab_orders/items changes, order packages, branch/FY numbering, `laboratory.register` desk orders (walk-in/referral, patient quick-register, age snapshot), `LabOrderPolicy` widened, **doctor Investigations → lab orders on web + hms_staff (D1)**, "My investigations" reads orders, invoice lines for packages/discounts, `/lab/orders` list + detail + New order page (fixes the dead link), cancel tick `laboratory.cancel` (backfilled from `.complete`), Laboratory sidebar group | XL |
| **P5 Samples** | samples, sample items, events, auto-planning, Code128 labels (PDF), collection queue, receiving (scan → accession), reject/recollect, dispatch tracking, D3 payment gate, **D2 VisitWorkflow change**, LabQueue/TV board on sample states, realtime not limited to Smart Queue; ticks `laboratory.collect/receive` | L |
| **P6 Results** | `lab_results`, revisions, critical alerts, worklist (department/priority/TAT/assignee), parameter grid (numeric validation, decimals, choice lists, units), range lookup + H/L/critical flags, previous-result comparison, draft/submit, legacy backfill, outsourced result entry; fixes problem 3 | L |
| **P7 Verify and approve** | reviews, amendments, `hms.lab_amend` trigger, verification + approval queues, four-eyes rule (`allow_self_review`), signatory requirement, critical-call gate, **portal and patient record show only final results** (fixes problem 5); ticks `laboratory.verify/amend` | L |
| **P8 Reports** | `lab_report` document type + payload builder + default template (letterhead, patient/order/sample block, results grouped by department, flags, remarks, technician, signatories with signature image, footer/disclaimer from template, QR of the report number), partial/complete/amended/duplicate, `lab_reports` snapshots, `document_rules` on report ready, secure download, signatories screen; tick `laboratory.print` | L |
| **P9 Billing completion** | refunds for cancelled paid tests, outsourced cost capture, the lab section of the Finance tab, lab revenue in billing exports, export dataset fix (problem 7) | M |
| **P10 Notifications** | ClinicEvents `lab_order_placed`, `lab_sample_rejected`, `lab_critical_result` (staff, on by default), `lab_report_pending_approval`, extend `lab_report_ready` (patient push/WhatsApp/email, doctor push); templates seeded per module grant; patient bell rows; Recipients screen picks them up automatically | M |
| **P11 Dashboard + analytics** | `/lab/dashboard` (all 13 tiles; revenue only for money-handling ticks, from payment rows), `/lab/analytics` (daily/monthly orders, test-wise/department-wise revenue, pending/completed/cancelled, rejection stats, TAT, technician performance, outsourced, critical, collections; filters: dates, branch, department, test, technician, doctor, status), Export Center datasets; tick `laboratory.dashboard/analytics` | L |
| **P12 Flutter** | hms_mobile: final-only reports, status chips, PDF download, push deep-link. hms_staff: doctor final-only lab cards + critical badge, lab staff `features/laboratory` (collection queue with camera barcode scan for home collection; read-only worklist). `Navigation.all` in **both** `hms_shared` copies; `docs/api/lab-reports.md` | L |
| **P13 Integration** | cross-tenant + cross-branch isolation suite, full regression (background), Scribe regenerate, guide PDF in `docs/guides`, stale docs fixed, browser walkthrough of every screen | M |

**Role templates** (`RoleTemplates`, added in the phase that creates their ticks):

| Template | Gets |
|---|---|
| Lab manager | all lab ticks + billing collect/refund |
| Lab receptionist | register, view, collect, `billing.create`/`collect_payment` |
| Phlebotomist (sample collector) | view, collect |
| Lab technician | updated: view, receive, process |
| Pathologist | view, verify, complete, amend, print, ranges_manage |
| Accountant | gains `laboratory.analytics` |
| Doctor | gains `laboratory.print` |

The brief's "Technical verifier" and "Report approver" are covered by the technician + `verify` and pathologist + `complete` ticks. **No separate authorization system**, and each new tick is added only with the screen or route that checks it, per your one-tick-per-screen rule.

**API surface** (all `/api/v1/tenant`, staff stack, `module:laboratory`, `permission:` per row, envelope `{data, meta, message}`, list endpoints paginated with `page, per_page, search, sort, direction` + filters):

| Phase | Endpoints → tick |
|---|---|
| P1 | `GET/PUT lab/settings` (settings_view / settings_manage) · `GET/POST/PUT/DELETE lab/masters/{master}[/{id}]` for departments, test-categories, methods, units, containers, sample-types, rejection-reasons, equipment, outsource-labs (read: ScreenTicks LAB_MASTERS_READ; write: settings_manage) · `POST lab/masters/{master}/starter` |
| P2 | `GET lab/tests` (filters department, category, active, outsourced) · `GET lab/tests/{id}` · `POST/PUT/DELETE lab/tests` · `PUT lab/tests/{id}/parameters` · `PUT lab/parameters/{id}/ranges` (ranges_manage) · `GET/PUT lab/tests/{id}/prices` · `GET lab/orderables?search=` (order, register) |
| P3 | `GET/POST/PUT/DELETE lab/packages` · `POST lab/quote` |
| P4 | existing `lab-orders` family extended (packages, priority) · `POST lab-orders/desk` (register) · `POST lab-orders/{o}/items/{i}/cancel`, `POST lab-orders/{o}/cancel` (cancel) · `GET lab-orders/{o}/timeline` |
| P5 | `GET lab/collection-queue` · `POST lab/samples/{s}/collect` · `GET lab/samples/{s}/label`, `GET lab-orders/{o}/labels` (PDF) · `POST lab/samples/{s}/dispatch` · `GET lab/receiving-queue` · `POST lab/samples/receive` (by barcode) · `POST lab/samples/{s}/reject` · `GET lab/samples/{s}` (tracking) |
| P6 | `GET lab/worklist` · `GET/PUT lab-orders/{o}/items/{i}/results` (draft flag) · `POST …/submit` · `POST …/assign` · `POST lab/critical-alerts/{a}/communicate` |
| P7 | `GET lab/verification-queue`, `POST …/items/{i}/verify|send-back` (verify) · `GET lab/approval-queue`, `POST …/items/{i}/approve`, `POST lab-orders/{o}/approve` (complete) · `POST …/items/{i}/amend` (amend) |
| P8 | `GET lab-orders/{o}/reports` · `GET lab/reports/{r}/pdf[?copy=duplicate]` (print) · `GET/POST/PUT lab/signatories` · portal `GET portal/lab-reports/{id}/pdf` |
| P11 | `GET lab/dashboard` · `GET lab/analytics/{report}` |

Errors:
- 422 validation (Form Requests, `Rule::exists(Model::class)`).
- 403 for a missing tick, or a module off at the branch.
- 404 for another branch's record (Policy).
- 409 for a state conflict.
- Money POSTs require an `Idempotency-Key` header.

**Web screens** (all in the existing design system: borderless shadow cards on `#e9f1fb`, equal-height rows, tables in bordered frames, big forms as pages, compact modals; checked against rendered screenshots):

| Group | Screens |
|---|---|
| Laboratory | Dashboard · Orders · New order · Collection · Receiving · Worklist + Result entry · Verification · Approval (+ report preview) · Tests · Packages · Analytics |
| Settings | Laboratory (tabs: General, Departments, Categories, Sample types, Containers, Methods, Units, Rejection reasons, Equipment, Partner labs, Signatories) |

---

## 8. Phase 1 in detail (the only thing built after approval)

**Files to create:**
- **Migration:** `database/migrations/organization/2026_11_xx_000000_create_lab_configuration_tables.php`. Creates 10 tables with CHECKs, partial uniques and `down()`.
- **Models:** `app/Models/Tenant/` → `LabSetting`, `LabDepartment`, `LabTestCategory`, `LabMethod`, `LabUnit`, `LabContainer`, `LabSampleType`, `LabRejectionReason`, `LabEquipment`, `LabOutsourceLab`.
- **Services:**
  - `app/Services/Laboratory/LabMasters.php`: registry of each master's model, rules, searchable columns, resource and in-use check.
  - `app/Support/Laboratory/StarterLists.php`: curated, editable, no clinical values.
- **Controllers:** `LabMasterController` (generic CRUD, refuses unknown `{master}`) and `LabSettingController`.
- **Requests:** `UpdateLabSettingRequest`.
- **Resources:** `LabMasterResource`, `LabSettingResource`.
- **Routes:** `routes/api/v1/laboratory.php`, `require`d inside the tenant group.

**Files to modify:**
- `app/Support/Modules/ModuleRegistry.php`: `requires: ['customers']` and 2 capabilities with hints.
- `app/Support/Modules/ScreenTicks.php`: `LAB_SETTINGS`, `LAB_MASTERS_READ`.
- `routes/api/v1.php`: one `require` line.
- `config/scribe.php`: group order.

**Frontend:**
- `resources/js/core/laboratory/settings/`: `api.ts`, `types.ts`, `pages/LabSettingsPage.tsx`, `components/MasterTab.tsx`, `components/MasterFormModal.tsx`.
- `tenant-router.tsx`: `/settings/laboratory` behind `RequireModule laboratory` + `RequireCapability laboratory.settings_view`.
- `tenant-navigation.ts`: a Settings child + `match`.
- `public/vendor/css/laboratory.css` (`.lab-`).
- `resources/views/app.blade.php`: one stylesheet line.

**Tests:** `tests/Feature/Api/V1/Tenant/LabConfigurationTest.php` covers:
- CRUD for every master.
- Case-insensitive duplicate refusal.
- Soft delete with a reason.
- Settings round trip and allowed values.
- 403 without the tick, 403 with the module off at the branch, a branch-only role refused on org writes.
- Starter list is idempotent.
- Physical tenant isolation: org B never sees org A's masters.
- Lab grantable with only `customers` (D4).
- `ModuleRegistry` hint test still green.

**Docs:**
- `DEVELOPMENT_PROGRESS.md` at the repo root, listing completed and pending phases, DB changes, endpoints, files, known issues, tests and next steps.
- `docs/architecture/laboratory.md`, a condensed version of this plan.

**Not in Phase 1:** any change to orders, billing, the visit workflow or mobile. The existing "Coming soon" lab row stays until P2/P4 brings the first lab screen.

---

## 9. Security, clinical safety and tenant isolation

| Area | How it's handled |
|---|---|
| Tenant isolation | Physical. Every lab model is on `organization`. No master lookups by tenant ids. Jobs and events re-check the connected DB. A cross-org test in each phase plus a full suite in P13 |
| Branch isolation | `TenantBranchAccess::allowed()` on every list. Policies with `AsksAboutBranch` on every record (another branch's record gives 404). Masters and catalogue are org-wide and written only by org-scoped ticks |
| Authorization | Only `Permission` + `module:`/`permission:` + Policies. Server-side checks on every state change. Four-eyes (entry ≠ verify ≠ approve) unless `allow_self_review` is on. Approving requires a signatory profile |
| Immutability | A final item's results are locked by a DB trigger (`hms.lab_amend`). Revisions, reviews and sample events are append-only by trigger. Reports are never deleted. Amendments only through `laboratory.amend` with a reason, producing a new report version |
| Clinical ranges | None seeded. Flags only where a qualified user configured a range. Ranges are edited only with `laboratory.ranges_manage`. Every change is in history. Results snapshot the range used |
| Patient privacy | Portal and app show only final results. PDFs stream through the policy-checked controller (no public URLs). `patient_documents` clinical category. Clinical text kept out of `activity_logs` |
| Input | Form Requests, numeric parsing with comparators (`<0.5`), length limits, HTML escaped in PDFs (`DocumentHtml`), idempotency on money and on scan-receive |
| Concurrency | Row locks (`lockForUpdate`) on the order for every transition (existing pattern). Numbers taken by the DB inside the INSERT (gapless, race-safe). Unique partial indexes |

---

## 10. Technical challenges and risks

1. **Keeping existing behaviour while widening it.** Tests that will need deliberate updates:
   - `VisitWorkflowTest` (D2: the visit completes at collection)
   - `ModuleBillingTest` (fixtures move to `LabTest`)
   - `PatientPortalTest` and `PatientFamilyTest` (final-only results)

   Each change will be named in the phase summary, never silently edited.
2. **Barcodes.** This needs a new Composer dependency. I'd add **`picqer/php-barcode-generator`** (Code128 PNG for dompdf labels). Label printers print the label PDF from the browser at `50x25`, `38x25` or A4 sheet size.
3. **Age and sex for ranges.** `customers.date_of_birth` is nullable and gender includes `other`. Ranges resolve on the order's snapshot. `other`/unknown match only `any` ranges, and an unknown age matches only ranges with no age limit, shown as such on the report.
4. **Calculated parameters** (indirect bilirubin, LDL by Friedewald, MCHC) need a safe formula evaluator. Proposed as an optional P6 add-on, not in the first cut.
5. **Report versioning.** `DocumentService` cannot regenerate, so a report renders from a frozen `payload` snapshot, and amendments create versions.
6. **Numbering.** Three new series types touch shared SQL functions and the numbering screen, following the pharmacy recipe exactly.
7. **Permission backfills.** A new tick never reaches existing roles by itself. Phone apps read only old keys. Both `hms_shared` copies must stay identical.
8. **Suite time.** The full suite takes about 45 minutes in the background, `PasswordResetTest` hangs and must be excluded, and the known-red list is in memory.
9. **Out of scope** (noted as future): analyzer interfacing (HL7/ASTM), hub-and-spoke processing across branches, IPD (no IPD module exists), WhatsApp/email PDF attachments (document delivery not built).

**Information I could not inspect:** production data volumes (only the dev tenants Apollo/Demo are local), real label printer models, and whether any external client calls `lab-test-catalog` (none in either app or the web).

---

## Verification (each phase)

1. Focused tests in the foreground: `php artisan test --filter=Lab` plus the touched existing suites (one artisan process at a time).
2. `php artisan tenants:migrate` + `php artisan modules:sync` on the dev DB, including the migration's `down()` for rollback.
3. `npx tsc --noEmit` + `npm run build`.
4. Pint on touched PHP files only.
5. A Playwright walkthrough on `clinic.hms.local:8000` (Apollo logins), with screenshots checked region by region.
6. API examples (request and response) in the phase summary.
7. `DEVELOPMENT_PROGRESS.md` updated.
8. At P13, the full suite in the background (about 45 minutes), compared against the known-failing list.
