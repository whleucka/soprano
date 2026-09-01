<?php

namespace App\Http\Controllers\Api;

use Echo\Framework\Http\Controller;
use Echo\Framework\Routing\Group;

/**
 * API Base Controller
 *
 * All API endpoints should extend this controller.
 * Provides:
 * - /api/v1 path prefix
 * - api.* name prefix
 * - api middleware (JSON responses, versioning)
 * - Rate limiting: 60 requests/minute
 *
 * Served from the main host under /api/v1 rather than an api subdomain. Keep
 * the '/api/' segment: ApiVersion::parseVersion sniffs the version out of the
 * path with /\/api\/(v\d+)\//, which never matched under the old scheme.
 */
#[Group(
    pathPrefix: '/api/v1',
    namePrefix: 'api',
    middleware: ['api', 'max_requests' => 60]
)]
abstract class ApiController extends Controller
{
    /**
     * Return a successful API response
     */
    protected function success(mixed $data = null, int $status = 200): array
    {
        http_response_code($status);
        return $data ?? [];
    }

    /**
     * Return an error API response
     */
    protected function error(string $message, int $status = 400, ?string $code = null): array
    {
        http_response_code($status);
        return [
            'error' => [
                'code' => $code ?? 'ERROR',
                'message' => $message,
            ],
        ];
    }
}
