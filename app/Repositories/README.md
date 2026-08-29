# Repositories

One class per table, holding every question we ask of that table.

## Why

A controller's job is to read a request and shape a response. The moment it
also knows *how* to build a query, the same query starts appearing in three
controllers with three small differences, and changing it means finding all
three.

So: **controllers ask, repositories answer.**

```php
// Not this
$organizations = Organization::where('status', 'active')
    ->whereNull('deleted_at')
    ->with('organizationType')
    ->orderBy('organization_name')
    ->get();

// This
$organizations = $this->organizations->active();
```

The second one reads like a sentence, and when "active" changes meaning it
changes in one place.

## The shape

```
Repositories/
  Contracts/RepositoryInterface.php     the eight shared methods
  BaseRepository.php                    implements them over any model
  Platform/
    Contracts/
      PlatformUserRepositoryInterface.php   shared eight + platform-specific
    PlatformUserRepository.php
```

Bindings live in `app/Providers/RepositoryServiceProvider.php` — interface on
the left, implementation on the right. That is the only file that knows which
class is actually in use.

## Adding one

1. Write `Foo/Contracts/FooRepositoryInterface.php`, extending
   `RepositoryInterface`. Declare only the methods that are specific to this
   table — the shared eight are inherited.
2. Write `Foo/FooRepository.php` extending `BaseRepository`, implementing the
   interface, with a constructor that takes the model.
3. Add the pair to `BINDINGS` in `RepositoryServiceProvider`.
4. Type-hint the **interface** in the controller's constructor. Laravel
   resolves it.

```php
public function __construct(
    private readonly FooRepositoryInterface $foos,
) {}
```

## Rules that keep this useful

- **Type-hint the interface, never the concrete class.** Otherwise the
  binding is pointless.
- **No request objects inside a repository.** It takes values, not
  `Request`. A repository that reads `$request->input()` cannot be reused or
  tested.
- **No HTTP concepts.** It returns models and collections, never a
  `JsonResponse` and never `abort()`.
- **Multi-step writes that must not half-happen belong in a transaction**
  inside the repository — see `createWithRoles()`.
- **Business rules that span several tables belong in a Service**, not a
  repository. Repositories talk to one table; services orchestrate them.
  `OrganizationProvisioningService` is the example — it coordinates the
  database, migrations and email, and would not fit in any single repository.
