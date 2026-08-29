# Codebase Structure

Layout follows the architecture brief — **Section 32** (Core vs Modules),
**Section 38** (API design) and **Section 39** (Frontend architecture).

The rule the brief cares about most:

> Module folders are what let the clinic module arrive as a folder rather
> than a refactor.

So nothing goes in a giant shared `components/` or `Controllers/` pile.
Platform concerns live in `core`/`Platform`; business capabilities live in
`modules`/their own namespace.

---

## Frontend — `resources/js/`

```
app/                      composition root — wiring only, no features
  main.tsx                mounts React
  providers.tsx           Router → Query → Auth → Lock → Confirm
  router.tsx              every route, lazy-loaded
  guards.tsx              ProtectedRoute / GuestRoute
  navigation.ts           sidebar menu, declared as data
  BootGate.tsx            hands over from the server-rendered loader
  NavigationLoader.tsx    branded loader on route change

core/                     platform features — every organization has these
  auth/                   login, forgot/reset, AuthProvider, LockProvider
  dashboard/
  onboarding/             public token-based organization setup
  organizations/
  organization-types/
  profile/

modules/                  business capabilities — subscribed per organization
  (empty — pharmacy, inventory, sales, purchases… land here)

shared/                   used by everything, owned by nothing
  api/                    http.ts · resource.ts · queryClient.ts
  components/
    ui/                   Button Card DataTable Modal FormModal PageHeader
                          Feedback Loader RowActions tones
    form/                 Fields.tsx · useApiForm.ts
    layout/               AppShell Header Sidebar
  hooks/                  useConfirm useModal useServerTable useResource
                          useDebounce useIdleTimer
  types/                  api.ts
  utils/                  cn.ts · format.ts · notify.tsx
```

`@/` maps to `resources/js/`, so imports read
`@/shared/components/ui/DataTable`, `@/core/organizations/api`.

### A feature folder always looks like this

```
<feature>/
  api.ts        endpoints + query hooks   (usually 5 lines)
  types.ts      the shapes the API returns
  pages/        one file per route
  components/   only what this feature uses
```

---

## Backend — `app/`

```
Http/
  Concerns/HandlesTableQueries.php    search + sort + paginate, any endpoint
  Controllers/Api/V1/
    BaseApiController.php             ok() created() fail() paginated()
    Auth/                             login, register, password, verification
    Onboarding/                       public setup flow (no session)
    Platform/                         organizations, organization types
    (Pharmacy/ … modules land here)
  Requests/Api/V1/Platform/           Store*/Update* form requests
  Resources/Platform/                 API resources

Models/
  Platform/                           master-database models
  Tenant/                             models that live inside a tenant database
  (User, File, EmailTemplate… stay at the root — framework-level)

Services/
  Tenancy/       DatabaseService · TenantConnectionService
  Platform/      OrganizationProvisioningService
  Notifications/ EmailService
```

Controllers stay thin (brief §38): authorize, validate, delegate, respond.
Anything with real logic goes into a service.

---

## Adding a new feature — the recipe

Say the next one is **Medicines**, a pharmacy module.

### 1. Backend

```
app/Models/Pharmacy/Medicine.php
app/Http/Requests/Api/V1/Pharmacy/{Store,Update}MedicineRequest.php
app/Http/Resources/Pharmacy/MedicineResource.php
app/Http/Controllers/Api/V1/Pharmacy/MedicineController.php
```

The controller gets pagination, search and sorting for free:

```php
class MedicineController extends BaseApiController
{
    use HandlesTableQueries;

    public function index(Request $request): JsonResponse
    {
        return $this->paginated(
            $this->tableQuery(
                Medicine::query(),
                $request,
                searchable: ['name', 'generic_name', 'hsn_code'],
                sortable:   ['name', 'created_at'],
            ),
            MedicineResource::class,
        );
    }
}
```

Register it in `routes/api.php` under the `auth:sanctum` group.

### 2. Frontend

```
resources/js/modules/pharmacy/medicines/
  api.ts
  types.ts
  pages/MedicineListPage.tsx
  pages/MedicineFormPage.tsx
```

`api.ts` is the whole data layer:

```ts
export const medicinesApi = createResourceApi<Medicine>('medicines');

export const medicinesHooks = createResourceHooks(medicinesApi, {
    singular: 'Medicine',
    plural: 'Medicines',
});
```

A list page then needs only columns:

```tsx
const table = useServerTable({ pageSize: 25, sort: 'created_at' });
const { data: page, isLoading, isFetching } = medicinesHooks.useTable(table.params);

<DataTable
    data={page?.data ?? []}
    columns={columns}
    loading={isLoading}
    fetching={isFetching}
    server={{ ...table, total: page?.meta.total ?? 0, pageCount: page?.meta.last_page ?? 1 }}
/>
```

### 3. Route + menu

Add the lazy import and `<Route>` in `app/router.tsx`, then one entry in
`app/navigation.ts`. The sidebar renders itself from that file.

---

## What you get for free

Build these once, never again:

| Need | Use |
|---|---|
| Table with search / sort / paging | `<DataTable server={…} />` + `useServerTable` |
| Mobile table | automatic — rows become cards below 768px |
| Create / edit dialog | `useModal()` + `<FormModal>` |
| Form fields with server errors | `useApiForm` + `TextField`/`SelectField`/… |
| Delete confirmation | `useConfirm()` |
| Click an image to enlarge it | `<PreviewableImage>`, or `<Avatar preview />` |
| Enlarge an image from code | `useImagePreview()` |
| Toasts | `notify.success/error/warning/info` |
| Empty / no-result / error states | built into `<DataTable>` |
| Page title + breadcrumb + colour | `<PageHeader icon tone crumbs />` |
| CRUD endpoints + cache + toasts | `createResourceApi` + `createResourceHooks` |

Colour tones: `indigo violet teal sky emerald amber rose`
(see `shared/components/ui/tones.ts`).

---

## Decisions on record

### Database: PostgreSQL — decided

The brief (§3) says MySQL. **This project runs PostgreSQL**, and that
supersedes the brief. Both the master connection and the per-tenant
`organization` connection are `pgsql`.

What this means in practice:

- Search uses `ILIKE` (`Http/Concerns/HandlesTableQueries.php`) — Postgres
  only. On MySQL this would need `LIKE` with a case-insensitive collation.
- `CREATE DATABASE` / `DROP DATABASE` (`Services/Tenancy/DatabaseService`)
  use Postgres syntax and **cannot run inside a transaction** — keep those
  calls outside any `DB::transaction()`.
- Migrations use Laravel's schema builder throughout, so they stay portable.

### Tenancy: database per organization — current model, keep as is

Each organization gets its **own PostgreSQL database**, provisioned at
creation time:

```
hms_software                 master — organizations, organization_types,
                             users (super admin), files, email templates
hms_<organization_name>      one per organization — that tenant's own tables
```

Owned by `Services/Tenancy`:

- `DatabaseService` — `CREATE DATABASE` / `DROP DATABASE`, with a name guard
  (identifiers cannot be bound as parameters).
- `TenantConnectionService` — points the shared `organization` connection at
  a specific tenant database, then resets the pooled PDO instance.
  `run($db, $callback)` scopes a piece of work to one tenant and disconnects
  afterwards so the next caller cannot inherit it.

Migrations for tenants live in `database/migrations/organization/` and are
applied by `php artisan system:migrate` (and automatically at provisioning
time by `OrganizationProvisioningService`).

This differs from the brief (§33–35), which assumes one shared database with
`organization_id` on every table. **That difference is known and accepted —
the current model stays.** Nothing here should be refactored toward shared-DB
tenancy without an explicit decision.

Two places where it will need thought later, not now:

- §15 cross-store medicine search and §40 owner dashboard read across
  locations. Inside one organization that is a single tenant database, so it
  is a normal query — it only becomes hard if a view ever has to span
  *organizations*.
- Every new tenant table needs its migration run across all tenant
  databases; `system:migrate` already loops them.
