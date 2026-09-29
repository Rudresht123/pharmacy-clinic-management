<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\Location;
use App\Models\Tenant\NumberSeries;
use App\Services\Billing\BranchNumbering;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Each branch's invoice, receipt and refund prefixes.
 *
 * Reading is `billing.view`, beside the rest of the billing settings; changing
 * a prefix is `billing.manage_settings`. Nothing here issues a number — see
 * BranchNumbering.
 */
class BillingNumberingController extends BaseApiController
{
    public function __construct(
        private readonly BranchNumbering $numbering,
    ) {}

    public function show(): JsonResponse
    {
        return $this->ok($this->numbering->overview());
    }

    public function update(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'series' => ['required', 'array', 'min:1'],
            'series.*.location_id' => [
                'required',
                'integer',
                Rule::exists(Location::class, 'id')->whereNull('deleted_at'),
            ],
            'series.*.document_type' => ['required', 'string', Rule::in(NumberSeries::TYPES)],
            /*
             * Printed on every bill and quoted back over the phone, so kept to
             * what reads cleanly: capitals, digits, / and -. It cannot end in
             * a separator — the year follows after a slash of its own.
             */
            'series.*.prefix' => ['required', 'string', 'max:20', 'regex:/^[A-Z0-9](?:[A-Z0-9\/-]*[A-Z0-9])?$/'],
        ], [
            'series.*.prefix.regex' => 'Use capital letters, digits, / and -, starting and ending with a letter or digit.',
        ]);

        $this->numbering->update($validated['series']);

        return $this->ok($this->numbering->overview(), 'Numbering saved');
    }
}
