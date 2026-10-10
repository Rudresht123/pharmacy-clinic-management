# Introduction

The HTTP API behind the HMS platform — the landlord panel, each organization's own staff workspace, and the patient portal.

<aside>
    <strong>Base URL</strong>: <code>http://localhost:8000</code>
</aside>

    This is the current contract for `/api/v1`. See `routes/api/v1.php` for how it is wired together.

    There are three separate audiences behind one base path, told apart by guard rather than by prefix alone:

    - **Platform** (`/api/v1/admin/*`) — the landlord panel, `platform` guard. A browser session only; no mobile client.
    - **Tenant** (`/api/v1/tenant/*`) — an organization's own staff, `web` guard (browser session) or `tenant-api` guard (bearer token). A phone identifies its organization with an `X-Organization` header on every request; a browser is already on the organization's subdomain.
    - **Patient portal** (`/api/v1/tenant/portal/*`) — a patient's own data, mobile only, `tenant-api` guard plus `X-Organization`.

    <aside>As you scroll, you'll see code examples for working with the API in different programming languages in the dark area to the right (or as part of the content on mobile).
    You can switch the language used with the tabs at the top right (or from the nav menu at the top left on mobile).</aside>

