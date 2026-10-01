<?php

namespace App\Http\Controllers\Api\V1\Tenant;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Models\Tenant\User;
use App\Support\Guides\GuideLibrary;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * The Guide section — how the software works, for the people using it.
 *
 * Open to every member of staff signed in to the workspace, whatever they may
 * otherwise open. A guide says how a receipt is numbered and where the Print
 * button is; it holds nothing about any patient or any clinic, so there is no
 * capability to ask for.
 *
 * NOT to a patient signed in to the portal. The staff routes keep patients out
 * by the capabilities patients do not hold; these ask for none, so they say it
 * themselves. A guide to the counter's screens is not the patient's to read.
 */
class GuideController extends BaseApiController
{
    public function __construct(
        private readonly GuideLibrary $guides,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->staffOnly($request);

        return $this->ok($this->guides->all());
    }

    public function show(Request $request, string $slug): JsonResponse
    {
        $this->staffOnly($request);

        $guide = $this->guides->find($slug);

        if ($guide === null) {
            abort(404, 'Resource not found.');
        }

        return $this->ok($guide);
    }

    public function pdf(Request $request, string $slug): BinaryFileResponse
    {
        $this->staffOnly($request);

        $path = $this->guides->pdf($slug);

        if ($path === null) {
            abort(404, 'Resource not found.');
        }

        return response()->download($path, $slug.'.pdf', ['Content-Type' => 'application/pdf']);
    }

    private function staffOnly(Request $request): void
    {
        $user = $request->user();

        if ($user instanceof User && $user->isPatient()) {
            abort(403, 'This action is not available to you.');
        }
    }
}
