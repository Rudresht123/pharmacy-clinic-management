<?php

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Validation\ValidationException;
use App\Http\Middleware\ResolveTenantFromSession;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api/v1',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->api(prepend: [
            EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->alias([
            'resolve.tenant' => ResolveTenantFromSession::class,
        ]);

        /*
        |----------------------------------------------------------------------
        | Where an unauthenticated request goes
        |----------------------------------------------------------------------
        |
        | Returning null for the API means no redirect, so the
        | AuthenticationException reaches the handler below and answers 401
        | JSON. Without this, Laravel's auth middleware tried to redirect any
        | request that did not explicitly ask for JSON to route('login') —
        | which stopped existing when the Blade auth screens were deleted, so
        | an unauthenticated API call raised "Route [login] not defined"
        | instead of saying it was unauthenticated.
        |
        | Everything else goes to /login, which the SPA resolves.
        |
        */
        $middleware->redirectGuestsTo(
            fn (Request $request) => $request->is('api/*') ? null : '/login'
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        /*
        |----------------------------------------------------------------------
        | JSON error contract
        |----------------------------------------------------------------------
        |
        | The SPA is the only consumer, so every API failure answers with the
        | same shape — { message, errors? } — instead of an HTML error page.
        | Handled centrally so no controller has to repeat it.
        |
        */
        $exceptions->render(function (\Throwable $e, Request $request) {
            if (! $request->is('api/*') && ! $request->expectsJson()) {
                return null;
            }

            [$status, $message] = match (true) {
                $e instanceof ValidationException => [422, 'The given data was invalid.'],
                $e instanceof AuthenticationException => [401, 'Unauthenticated.'],
                $e instanceof AuthorizationException => [403, 'This action is unauthorized.'],
                $e instanceof TokenMismatchException => [419, 'Your session has expired. Please refresh and try again.'],
                $e instanceof ModelNotFoundException,
                $e instanceof NotFoundHttpException => [404, 'Resource not found.'],
                $e instanceof HttpExceptionInterface => [$e->getStatusCode(), $e->getMessage() ?: 'Request failed.'],
                default => [500, 'Something went wrong.'],
            };

            if ($e instanceof ValidationException) {
                return response()->json([
                    'message' => $e->getMessage(),
                    'errors' => $e->errors(),
                ], $status);
            }

            // Real messages only while debugging; production stays generic.
            if ($status === 500 && config('app.debug')) {
                $message = $e->getMessage();
            }

            return response()->json(['message' => $message], $status);
        });
    })->create();
