<?php

namespace App\Support\Tenancy;

use Illuminate\Support\Str;

/**
 * Pulls the tenant subdomain out of a request Host header
 * (`{subdomain}.{main_domain}`) — shared by tenant login (which falls back
 * to a body field when the host isn't a real tenant subdomain) and the
 * public branding endpoint (which has no such fallback to fall back to).
 */
class SubdomainResolver
{
    /** Null when the host doesn't end in the configured main domain at all. */
    public static function fromHost(string $host): ?string
    {
        $mainDomain = config('organization.main_domain');
        $host = Str::lower($host);

        if (! $mainDomain || ! Str::endsWith($host, '.'.Str::lower($mainDomain))) {
            return null;
        }

        return Str::beforeLast($host, '.'.Str::lower($mainDomain));
    }
}
