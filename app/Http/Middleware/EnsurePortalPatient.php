<?php

namespace App\Http\Middleware;

use App\Models\Tenant\Customer;
use App\Models\Tenant\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The portal is about one patient: the one this login belongs to.
 *
 * Capabilities alone cannot say that. `portal.appointments.view` means "may
 * see their own appointments", and "their own" needs a record to be about — so
 * an owner who hands a portal capability to a staff role by mistake gives that
 * member of staff nothing, rather than a screen with nobody's data on it.
 *
 * The patient record is resolved once here and handed on as a request
 * attribute, so no portal controller looks it up again or reads an id from the
 * client.
 */
class EnsurePortalPatient
{
    public const ATTRIBUTE = 'portal.patient';

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        $patient = $user instanceof User ? $user->patientRecord() : null;

        if (! $patient instanceof Customer || ! $patient->is_active) {
            abort(403, 'This is a patient\'s own screen, and you are not signed in as one.');
        }

        $request->attributes->set(self::ATTRIBUTE, $patient);

        return $next($request);
    }
}
