# Authenticating requests

To authenticate requests, include an **`Authorization`** header with the value **`"Bearer {YOUR_SANCTUM_TOKEN}"`**.

All authenticated endpoints are marked with a `requires authentication` badge in the documentation below.

Endpoints shown here as authenticated expect a Sanctum bearer token, **except**:

- **Platform** (`/api/v1/admin/*`) and the browser side of **Tenant** (`/api/v1/tenant/*`, `web` guard) actually
  authenticate with a signed-in session cookie, not a bearer token — login there with
  `POST .../auth/login`, which starts the session. The bearer flows shown in this
  page are for the mobile/API clients, which use `.../auth/token` (tenant) instead.
- Every **Tenant** and **Patient portal** request from a client that is not already on the
  organization's own subdomain must also send an `X-Organization` header naming the
  organization (by uuid or code) — see `App\Http\Middleware\ResolveTenantFromHeader`.

Endpoints tagged `@unauthenticated` need neither.
