# Document templates and generated PDFs — architecture analysis

Written for the person deciding whether to approve this module. Analysis only —
nothing below has been built.

The short version: **about half of what the brief asks for already exists**,
one piece of it was built two days ago, and the single biggest gap is that this
project has **no PDF library at all**. There are also three requested document
types with no data behind them, and one proposed schema column that would be
actively wrong here.

---

# 1. Current architecture summary

| | |
|---|---|
| Laravel | 12, PHP 8.2 |
| Dependencies | `laravel/framework`, `laravel/sanctum`, `laravel/tinker`. **That is the entire production dependency list.** |
| Tenancy | **Physical** — one PostgreSQL database per organization. No `organization_id` column exists anywhere in a tenant database. |
| Branch | `locations` table. `Location::CLINIC` etc. Now carries `manager_id`. |
| Patient | `customers` table (`Customer`), organization-wide, shared by every branch. |
| Visit | `appointments` + `consultations`. There is no `visits` table. |
| Authorization | Three levels — entitlement (master), branch modules, role capability — resolved by `App\Services\Permissions\Permission`. Plus `TenantBranchAccess` (where) and `StaffScope` (to whom). |
| Audit | `activity_logs`, append-only behind a database trigger, written by the `RecordsHistory` trait. Now carries `location_id`. |
| Files | `files` table (`file_name`, `file_path`, `disk`, `mime_type`, `file_size`, `extension`) + `File::purge()`. |
| Printing today | `window.print()` from the browser, with an `@media print` block in `custom.css`. **There is no server-side PDF anywhere.** |

# 2. Existing relevant tables and models

### Directly relevant — built two days ago

`patient_documents` (+ `PatientDocument`, `PatientDocumentController`,
`App\Support\Documents\DocumentCategories`).

```
id, customer_id, appointment_id?, file_id, location_id?,
category, title, notes?, uploaded_by?, softDeletes + deleted_by + deletion_reason
```

This already covers most of brief §10:

- upload (multipart, allow-listed mime types, 20 MB)
- list per patient and per visit
- authorised streaming download — **no URL on the resource, private disk**
- soft delete with a reason
- 11 categories, each declaring `clinical` or `administrative` sensitivity,
  which decides whether `documents.view` or `documents.view_clinical` opens it

Storage is already `storage/app/private` under
`patient-documents/{organization}/{patient}/`, served only by
`GET /tenant/documents/{id}/download` after the capability **and** the
sensitivity check.

### Template precedents already in the codebase

| | |
|---|---|
| `message_templates` | WhatsApp/email templates — `channel`, `name`, `category`, `subject`, `content`, `status`, `created_by` |
| `email_templates` | platform-side, with an `available_placeholders` text column |
| `resources/js/core/communication/placeholders.ts` | 8 named placeholders with sample values, used for the chips, the preview and the tooltip |
| `EmailService::send()` | `str_replace` substitution of `{{key}}` |
| `App\Services\WhatsApp\TemplateResolver` | resolves a logical template to a provider's name and variable order |

**There is no placeholder validation on save anywhere.** A template with
`{{patinet_name}}` saves happily and renders the literal text.

### Document data sources that exist

| Requested template type | Data behind it |
|---|---|
| Patient Registration | ✅ `customers` |
| Prescription | ✅ `prescriptions` + `prescription_items` (RX- numbering already has a Postgres sequence) |
| Consultation Summary | ✅ `consultations` (chief complaint, diagnoses, vitals, investigations, advice) |
| Pharmacy Invoice | ✅ `pharmacy_sales` + items + payments |
| Payment Receipt | ✅ `pharmacy_sale_payments` |
| Patient Document Cover | ✅ `patient_documents` |
| **Invoice** (clinic) | ❌ **no billing module exists** |
| **Lab Report** | ❌ **no lab module exists** |
| **Discharge Summary** | ❌ no IPD/admission concept |

`document_number_sequences` already provides `RX-`, `DS-`, `GRN-`, `ADJ-`,
`TRF-` as Postgres sequences with a formatting function — the right mechanism
for numbering generated documents, and reusable.

# 3. What can be reused

Substantially more than the brief assumes.

- **§10 uploaded documents — already built.** Needs only a `source` column to
  sit beside generated ones.
- **§12 storage — already built.** Private disk, tenant/patient namespaced,
  authorised streaming, no public URL. The path convention is
  `patient-documents/{org}/{patient}/`, which is the brief's shape minus a
  branch segment (deliberately: a patient is organization-wide here).
- **§15 permissions — the whole RBAC.** `ModuleRegistry` capabilities,
  `EnsureTenantCan`, branch-scoped roles, the seeded role library, and the
  `branches.manage_manager` capability that makes "Organization Admin vs Branch
  Manager" a real distinction.
- **§19 cross-organization isolation — free and absolute.** Different physical
  database. There is no `organization_id` to forget a `where` clause on.
- **§19 cross-branch scope — `TenantBranchAccess` + `ResolveActingBranch`.** A
  branch id from the client is checked against the caller's memberships before
  any query runs.
- **§18 audit — `RecordsHistory`.** Put the trait on the new models and every
  create/update/delete is recorded with before/after values, the actor, the IP
  and now the branch.
- **§6 placeholders — the pattern exists** (chips, samples, preview, tooltip),
  and can be generalised rather than reinvented.
- **§8 numbering — Postgres sequences.**

# 4. What needs to be added

1. **A PDF library.** Nothing in `composer.json` can render a PDF. This is the
   one hard blocker and it needs your decision (see §9).
2. `document_templates` + `document_template_versions`.
3. A locked-fields mechanism.
4. **A branch logo.** `locations` has no logo column; only the organization has
   one (`organizations.profile_image`, master DB). The brief's header config
   wants both.
5. `source` / `document_template_id` / `template_version_id` on
   `patient_documents`.
6. Placeholder **validation** — the thing that does not exist today.
7. A document-type registry (`App\Support\Documents\DocumentTypes`), sibling of
   `DocumentCategories` and `ModuleRegistry`.

# 5. Proposed database changes

Three migrations, all on the `organization` connection.

### `document_templates`

```
id
location_id      nullable  ← NULL = the organization's default; set = that branch's override
document_type    string(40)
name
description      nullable
status           draft | active | archived
is_default       boolean
created_by / updated_by
timestamps + softDeletes

unique (location_id, document_type) where deleted_at is null
```

**No `organization_id`.** See §9 — in this system that column cannot mean
anything.

Inheritance falls out of `location_id` being nullable: one row with a null
location is the organization default, and a branch that has customised has a
second row. That is brief §3 exactly, with no extra table.

### `document_template_versions`

```
id
document_template_id
version           integer           ← 1, 2, 3 per template
config            jsonb             ← header / body / footer / layout, whole
locked_fields     jsonb             ← ["header.legal_name", "header.registration_no"]
published_at      nullable
published_by      nullable
timestamps

unique (document_template_id, version)
```

The version carries the **whole** config, not a diff. A generated document
points at a version id, so the config it was rendered from is still readable
byte for byte years later — which is brief §21, and the reason not to store
diffs.

`locked_fields` lives on the **organization default's** active version. A
branch override is validated against it on save.

### `patient_documents` — three columns added

```
source                       'uploaded' | 'generated'   default 'uploaded'
document_template_id         nullable
document_template_version_id nullable
```

Existing rows are all uploads, so the default backfills correctly and nothing
needs rewriting.

# 6. Proposed backend architecture

```
DocumentService::generate($type, $subject, $branch)
        │
        ├─ TemplateResolver::for($type, $branch)
        │     1. active branch template for this branch
        │     2. else active organization default
        │     3. else refuse — no silent fallback to "latest"
        │
        ├─ DocumentPayload::for($type, $subject)      ← placeholders → values
        │
        ├─ PdfRenderer::render($version->config, $payload)   ← the only class that knows about PDFs
        │
        └─ transaction:
              File::create(...)  →  private disk
              PatientDocument::create(source: 'generated',
                                      document_template_version_id: …)
```

Four new support classes, each mirroring one that already exists:

| New | Mirrors | Holds |
|---|---|---|
| `Support\Documents\DocumentTypes` | `DocumentCategories` | the 10 types, which module each needs, which category a generated one files under |
| `Support\Documents\Placeholders` | `communication/placeholders.ts` | tokens per document type, with sample values |
| `Services\Documents\TemplateResolver` | `WhatsApp\TemplateResolver` | branch → organization → refuse |
| `Services\Documents\PdfRenderer` | — | the only place a PDF library is named |

`PdfRenderer` is deliberately the only class that imports the PDF library, so
changing it later is one file.

# 7. Proposed permission structure

Extends the **existing `documents` module** rather than adding one — its
capabilities are already the vocabulary for patient files, and the prefix rule
(`ModuleRegistry`: keys are prefixed with their module's key) requires it.

| Capability | Scope | Who |
|---|---|---|
| `documents.view` | branch | existing — administrative files |
| `documents.view_clinical` | branch | existing — medical files |
| `documents.upload` | branch | existing |
| `documents.delete` | branch | existing |
| `documents.generate` | branch | **new** — produce a PDF from a template |
| `documents.template_view` | branch | **new** — read this branch's templates |
| `documents.template_edit` | branch | **new** — change this branch's templates |
| `documents.template_publish` | branch | **new** — activate a version |
| `documents.template_org` | **organization** | **new** — edit ANY branch's template, set the organization default, and lock fields |

That last one is the whole authority model in one key. A Branch Manager holds
the four branch-scoped ones; head office and the owner hold
`documents.template_org` as well. Enforcement is the same as everywhere:

```php
// Branch Manager editing their own branch's template
$permission->allows($org, $user, 'documents.template_edit', $template->location_id)
&& $branches->canUse($user, $template->location_id)

// Editing the organization default, or another branch's
$permission->allows($org, $user, 'documents.template_org')
```

Locked fields are checked server-side in the form request, against the
organization default's active version — so a Branch Manager sending a payload
that touches a locked path is refused with the path named.

# 8. Proposed UI structure

Reuses the branch-form tab pattern that now carries **Details / Manager /
Modules**:

**Organization Admin** — `Settings → Document templates`
```
Branch: [ All branches (organization default) ▼ ]
Prescription        [Active]  V2    [Edit] [Preview] [Versions]
Pharmacy invoice    [Active]  V3    [Edit] [Preview] [Versions]
Consultation summary [Draft]  V1    [Edit] [Preview]
```

**Branch Manager** — the same screen with **no branch selector**, because there
is exactly one branch they can reach. Not a disabled dropdown — absent, the way
every other unavailable action in this codebase is handled.

Editor: header / body / footer / layout as four panels, placeholder chips from
`GET /tenant/document-types/{type}/placeholders`, a live preview, and `Save
draft` / `Publish` as two distinct actions. Locked fields render read-only with
an `[Organization locked]` badge.

# 9. Potential conflicts with existing code

**C1 — `organization_id` would be wrong, not just redundant.**
The brief puts `organization_id` on every proposed table. This system has no
such column in any tenant table, because the database *is* the organization.
Adding one gives a value nothing derives, nothing enforces and nothing can
validate — and the first person who writes `where('organization_id', …)` will
believe they have scoped a query that was already scoped. Recommendation: omit
it, as every existing tenant table does.

**C2 — `patient_documents` already exists, with different names.**
The brief's proposal and the built table differ on almost every column name.
The existing conventions should win: `customer_id` not `patient_id`,
`location_id` not `branch_id`, `appointment_id` not `visit_id`, and
file metadata in `files` rather than repeated. Otherwise two naming schemes
live in one table.

**C3 — `document_type` vs the existing `category`.**
`patient_documents.category` already exists and already decides who may read a
document. A generated prescription should file under the existing
`prescription` category rather than gaining a parallel type column that also
means "what kind of document is this". Recommendation: `document_type` names
the **template**, `category` keeps deciding **visibility**, and
`DocumentTypes` maps one to the other.

**C4 — no PDF library, and adding one is your call.**
`composer.json` has three production packages. The realistic options:

| | |
|---|---|
| `barryvdh/laravel-dompdf` | pure PHP, no system binaries, good enough for A4/A5 documents with tables and a logo. Weak on complex CSS, no flex/grid. |
| `spatie/browsershot` | renders through headless Chrome — pixel-accurate, can reuse the existing print stylesheet. Needs Node + Chromium installed on the server. |
| `mpdf/mpdf` | between the two; better CSS than dompdf, heavier. |

Recommendation: **dompdf**. It is the only one that needs nothing installed
beyond composer, which matters for a system deployed per clinic. Its CSS limits
are real, so templates should be built as tables from the start rather than
being ported from the existing print stylesheet.

**C5 — three requested types have no data.**
Invoice, Lab Report and Discharge Summary need a billing module, a lab module
and an admissions concept, none of which exist. Building template types for
them now means a template editor listing document types that can never be
generated. Recommendation: ship the seven that have data, and let
`DocumentTypes` declare `requires` so the other three appear the day their
module does.

**C6 — no branch logo.**
`locations` has no logo column; the organization's is `profile_image` in the
**master** database, served from the public disk. The brief's header wants
both. Adding `locations.logo_file_id` is small, but note the asymmetry: the
organization logo is public (it is on the login screen) and a branch logo would
be too. That is fine for a letterhead and worth stating out loud.

**C7 — a generated PDF is a medical record the moment it exists.**
Regeneration must not be the default anything. The brief says so (§21) and the
existing `patient_documents` soft-delete-with-reason already encodes that
instinct. Recommendation: no regenerate endpoint in the first cut. Generate,
and if it was wrong, remove with a reason and generate again — which leaves
both in the audit trail rather than silently replacing a document somebody may
already have handed to a patient.

---

# Decisions needed before implementation

1. **PDF library** — dompdf (recommended, zero system dependencies), or
   browsershot (better rendering, needs Chromium on every deployment).
2. **Scope of the first cut** — the seven document types that have data, or
   wait and build billing/lab first.
3. **`organization_id`** — confirm it is omitted, per C1.
4. **Regeneration** — confirm "no regenerate endpoint yet", per C7.
5. **Branch logo** — add `locations.logo_file_id`? It is the only new column
   outside the two template tables.

# Implementation order, once approved

Follows the brief's own order, with the two swaps the analysis argues for:

1. `DocumentTypes` + `Placeholders` registries (code, no schema)
2. Migrations: `document_templates`, `document_template_versions`, three
   columns on `patient_documents`
3. Models + `RecordsHistory`
4. Capabilities in `ModuleRegistry`, plus the role-template updates
5. `TemplateResolver` + placeholder validation
6. `PdfRenderer` + `DocumentService`
7. Generation endpoint, writing through the existing `PatientDocument` path
8. Organization Admin UI, then Branch Manager UI (same screen, no selector)
9. Feature tests — the brief's 20 cases, of which 14, 15 and 16 are already
   covered by `PatientDocumentTest`
