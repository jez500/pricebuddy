<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetaExtractionRequest;
use App\Services\MetaExtractionService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;

#[Group('MetaExtraction')]
class MetaExtractionController extends Controller
{
    public function __construct(
        protected MetaExtractionService $metaExtractionService,
    ) {}

    /**
     * Extract meta data from a URL.
     */
    public function __invoke(MetaExtractionRequest $request): JsonResponse
    {
        $validated = $request->validated();

        return response()->json([
            'data' => $this->metaExtractionService->extract(
                $validated['url'],
                $validated['store'] ?? [],
            ),
        ]);
    }
}
