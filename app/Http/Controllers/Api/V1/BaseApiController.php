<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * Shared response envelope for every v1 endpoint.
 *
 * Responses always look like: { data, message, meta } — keys with no
 * value are omitted so clients can rely on a single shape.
 */
abstract class BaseApiController extends Controller
{
    protected function respond(
        mixed $data = null,
        ?string $message = null,
        array $meta = [],
        int $status = 200
    ): JsonResponse {
        $payload = [];

        if ($data !== null) {
            $payload['data'] = $data;
        }

        if ($message !== null) {
            $payload['message'] = $message;
        }

        if ($meta !== []) {
            $payload['meta'] = $meta;
        }

        return response()->json($payload, $status);
    }

    protected function ok(mixed $data = null, ?string $message = null, array $meta = []): JsonResponse
    {
        return $this->respond($data, $message, $meta, 200);
    }

    protected function created(mixed $data = null, ?string $message = null): JsonResponse
    {
        return $this->respond($data, $message, [], 201);
    }

    /**
     * A page of results, wrapped in the standard envelope.
     *
     * Laravel's own paginated resource response nests data/links/meta at the
     * top level; this flattens it to { data, meta } so every endpoint —
     * paginated or not — answers with the same shape.
     *
     * @param  class-string<JsonResource>  $resource
     */
    protected function paginated(LengthAwarePaginator $page, string $resource): JsonResponse
    {
        return response()->json([
            'data' => $resource::collection($page->items()),
            'meta' => [
                'current_page' => $page->currentPage(),
                'last_page' => $page->lastPage(),
                'per_page' => $page->perPage(),
                'total' => $page->total(),
                'from' => $page->firstItem(),
                'to' => $page->lastItem(),
            ],
        ]);
    }

    protected function noContent(?string $message = null): JsonResponse
    {
        return $this->respond(null, $message, [], 200);
    }

    protected function fail(string $message, int $status = 400, array $errors = []): JsonResponse
    {
        $payload = ['message' => $message];

        if ($errors !== []) {
            $payload['errors'] = $errors;
        }

        return response()->json($payload, $status);
    }

    /**
     * Surface the real reason while debugging, stay generic in production.
     */
    protected function failFromThrowable(\Throwable $e, string $fallback): JsonResponse
    {
        report($e);

        return $this->fail(
            config('app.debug') ? $e->getMessage() : $fallback,
            500
        );
    }
}
