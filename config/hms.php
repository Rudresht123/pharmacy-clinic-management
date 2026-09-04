<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Dashboard demo data
    |--------------------------------------------------------------------------
    |
    | Fills the workspace dashboard with sample figures so the whole screen can
    | be seen and demonstrated before the modules behind it ship — billing,
    | departments and reports do not exist yet, and their panels would
    | otherwise be empty boxes nobody could evaluate.
    |
    | EVERY NUMBER IT PRODUCES IS INVENTED. See App\Services\Tenant\
    | DemoDashboardData. Real data always wins where it exists: an
    | organization that has entered three branches sees three, not five made
    | up ones — only panels with nothing real behind them stay sample.
    |
    | Set to false, and DashboardController falls straight through to
    | DashboardSummary, which is real, branch-scoped and capability-gated and
    | has been all along.
    |
    */

    'dashboard_demo' => (bool) env('HMS_DASHBOARD_DEMO', true),

];
