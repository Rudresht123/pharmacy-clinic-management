<?php

namespace App\Http\Controllers\Api\V1\Website;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Platform\WebsiteEnquiry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Where the marketing website's demo and trial forms send what people type.
 *
 * Public by necessity — the person has no account — so it only ever writes
 * one row and answers nothing about what is already stored. Throttled on the
 * route, and the `website` field is a honeypot: people never see it, bots
 * fill every field they find.
 *
 * @group Website
 */
class EnquiryController extends BaseApiController
{
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(WebsiteEnquiry::TYPES)],
            'name' => ['required', 'string', 'max:150'],
            'organization_name' => ['nullable', 'string', 'max:191'],
            'email' => ['required', 'email', 'max:191'],
            'phone' => ['nullable', 'string', 'max:30'],
            'interest' => ['nullable', 'string', 'max:191'],
            'message' => ['nullable', 'string', 'max:2000'],
            'website' => ['prohibited'],
        ]);

        WebsiteEnquiry::create([
            ...$data,
            'status' => WebsiteEnquiry::NEW,
            'ip_address' => $request->ip(),
        ]);

        return $this->created(null, 'Thank you — we will be in touch shortly.');
    }
}
