# Modules

Business capabilities that an organization subscribes to, one folder each:

    pharmacy/  inventory/  sales/  purchases/  customers/
    prescriptions/  wholesale/  mr/  doctors/  appointments/
    reports/  subscriptions/

Platform features (auth, organizations, locations, users, permissions)
belong in `../core`, not here.

Each module follows the same shape:

    <module>/
      <feature>/
        api.ts        endpoints + query hooks
        types.ts      API response shapes
        pages/        one file per route
        components/   only what this feature uses

See ARCHITECTURE.md at the repo root for the full recipe.
