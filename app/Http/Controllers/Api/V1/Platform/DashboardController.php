<?php

namespace App\Http\Controllers\Api\V1\Platform;

use App\Http\Controllers\Api\V1\BaseApiController;
use App\Services\Platform\PlatformDashboard;
use App\Services\Tenant\DashboardWindow;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

/**
 * The super admin's first screen — the platform at a glance.
 *
 * @group Platform > Dashboard
 */
class DashboardController extends BaseApiController
{
    /**
     * Real figures only. A panel with nothing behind it yet comes back null
     * and the screen shows it empty — see PlatformDashboard.
     */
    public function index(Request $request, PlatformDashboard $dashboard): JsonResponse
    {
        return $this->ok($dashboard->for($this->window($request)));
    }

    /**
     * The days the period figures are about — this month unless asked.
     *
     * Capped at two years, like the workspace dashboard: a ten-year window
     * is a mistyped date, not a question.
     */
    private function window(Request $request): DashboardWindow
    {
        $request->validate([
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date', 'after_or_equal:from'],
        ]);

        $from = $request->filled('from') ? Carbon::parse($request->date('from')) : null;
        $to = $request->filled('to') ? Carbon::parse($request->date('to')) : null;

        // Half a range is the month it falls in, from that end.
        if ($from && ! $to) {
            $to = $from->copy()->endOfMonth();
        } elseif ($to && ! $from) {
            $from = $to->copy()->startOfMonth();
        }

        if ($from && $to && $from->diffInDays($to) > 731) {
            throw ValidationException::withMessages(['to' => 'Choose a range of two years or less.']);
        }

        return new DashboardWindow($from, $to);
    }
}
