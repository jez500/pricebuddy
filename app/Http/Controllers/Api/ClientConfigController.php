<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Capability discovery for API clients such as the browser extension, which needs to
 * know whether an instance supports the URL matching endpoints before choosing between
 * one request and paging thousands of products.
 */
#[Group('Client config')]
class ClientConfigController extends Controller
{
    /**
     * Client configuration
     *
     * Returns the capabilities this instance supports and its application version.
     * Cached for a day; send If-None-Match to get a 304.
     *
     * The payload is a hand-picked, literal allowlist of fields rather than a serialised
     * settings model or config dump. Endpoints like this accrete fields over time, and an
     * automatic serialisation is a classic route for leaking scraper keys or webhook URLs.
     * Add new fields here explicitly, never by looping over config or model attributes.
     */
    public function __invoke(Request $request): JsonResponse
    {
        $data = [
            'capabilities' => [
                'products_filter_url' => true,
                'products_current_url' => true,
                'products_sparse_fieldsets' => true,
                'stores_filter_domain' => true,
            ],
            'app_version' => (string) config('app.version'),
        ];

        $response = response()->json(['data' => $data]);

        $response->setEtag(md5((string) json_encode($data)));
        $response->setPrivate();
        $response->setMaxAge(86400);
        $response->isNotModified($request);

        return $response;
    }
}
