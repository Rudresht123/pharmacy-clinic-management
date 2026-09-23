<?php

namespace App\Http\Controllers\Api\V1\Tenant\Portal;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Http\Requests\Api\V1\Tenant\Portal\SendCodeRequest;
use App\Http\Requests\Api\V1\Tenant\Portal\VerifyCodeRequest;
use App\Models\Platform\Organization;
use App\Models\Tenant\Customer;
use App\Repositories\Tenant\Contracts\CustomerRepositoryInterface;
use App\Services\Permissions\Permission;
use App\Services\Portal\PatientOtp;
use App\Services\Tenant\PatientAccountProvisioner;
use App\Services\Tenant\SessionPayload;
use App\Support\Phone;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A patient signing in with their phone.
 *
 * Two steps: ask for a code, then send it back. The clinic is named in the
 * X-Organization header, exactly as for every other mobile request, so a
 * patient of two clinics has two separate accounts in two separate databases
 * and neither clinic can see the other's.
 *
 * The answer to a successful sign-in is the SAME session payload staff get
 * (SessionPayload), token included — the app builds its screens from the
 * capabilities in it, not from knowing it is talking to a patient.
 */
class SignInController extends BaseApiController
{
    public function send(SendCodeRequest $request, PatientOtp $otp, Permission $permission): JsonResponse
    {
        $organization = $this->clinic($request, $permission);
        $phone = $request->validated('phone');

        $sent = $otp->send($phone, (string) $organization->organization_name, $request->ip());

        return $this->ok(
            $sent + ['phone' => Phone::masked($phone)],
            'We sent a code to +91 '.Phone::masked($phone).'.'
        );
    }

    public function verify(
        VerifyCodeRequest $request,
        PatientOtp $otp,
        Permission $permission,
        CustomerRepositoryInterface $customers,
        PatientAccountProvisioner $accounts,
        SessionPayload $session,
    ): JsonResponse {
        $organization = $this->clinic($request, $permission);
        $phone = $request->validated('phone');
        $name = trim((string) $request->validated('name'));

        // Checked, not spent: if we still need their name they should not
        // have to wait for a second code while they type it.
        $code = $otp->check($phone, $request->validated('code'));

        $patient = Customer::query()->active()->withPhone($phone)->oldest('id')->first();

        if (! $patient) {
            // A switched-off record on this number is the clinic's decision to
            // undo, not something a new sign-up should quietly route around.
            if (Customer::query()->withPhone($phone)->exists()) {
                return $this->fail('Your record at this clinic is inactive. Please contact the clinic.', 422);
            }

            if ($name === '') {
                $message = 'Tell us your name to finish signing up.';

                return $this->fail($message, 422, ['name' => [$message]]);
            }
        }

        $user = DB::connection('organization')->transaction(function () use (
            $patient, $customers, $name, $phone, $otp, $code, $accounts, $permission, $organization,
        ) {
            $patient ??= $customers->create([
                'name' => $name,
                'phone' => $phone,
                'is_active' => true,
            ]);

            $otp->spend($code);

            return $accounts->open($patient, $permission->modulesAt($organization, null));
        });

        if (! $user->is_active) {
            return $this->fail('Your app access has been switched off by the clinic. Please contact them.', 422);
        }

        return $this->ok(
            $session->withToken($user, $organization, $request->validated('device_name'), $request->ip()),
            'Signed in successfully.'
        );
    }

    /**
     * The clinic from the header, and whether it takes app sign-ins at all.
     *
     * A patient's login is only useful for booking, so a clinic that does not
     * run OPD is refused here rather than handing out accounts with nothing
     * behind them.
     */
    private function clinic(Request $request, Permission $permission): Organization
    {
        $organization = $request->attributes->get('tenant.organization');

        if (! $organization instanceof Organization) {
            throw ValidationException::withMessages([
                'clinic' => 'We could not find that clinic. Check the clinic code and try again.',
            ]);
        }

        if (! $permission->hasModule($organization, 'appointments')) {
            throw ValidationException::withMessages([
                'clinic' => "This clinic doesn't take bookings through the app yet.",
            ]);
        }

        return $organization;
    }
}
