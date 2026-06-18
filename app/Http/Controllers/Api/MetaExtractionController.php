<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\MetaExtractionRequest;
use App\Http\Resources\MetaExtractionResource;
use App\Services\MetaExtractionService;
use Dedoc\Scramble\Attributes\Group;

#[Group('MetaExtraction')]
class MetaExtractionController extends Controller
{
    public function __construct(
        protected MetaExtractionService $metaExtractionService,
    ) {}

    /**
     * Extract meta data from a URL
     */
    public function __invoke(MetaExtractionRequest $request): MetaExtractionResource
    {
        $validated = $request->validated();

        return new MetaExtractionResource($this->metaExtractionService->extract(
            $validated['url'],
            $validated['store'] ?? [],
        ));
    }
}
