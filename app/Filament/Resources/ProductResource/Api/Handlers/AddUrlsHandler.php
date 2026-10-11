<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Actions\AddUrlsToProductAction;
use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Requests\AddProductUrlsRequest;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class AddUrlsHandler extends Handlers
{
    public static ?string $uri = '/{id}/urls';

    public static ?string $resource = ProductResource::class;

    public static function getMethod()
    {
        return Handlers::POST;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Adding URLs to a product is an update to it, so reuse that ability rather than add a new one.
     */
    public static function getAbility(): array
    {
        return ['product:update'];
    }

    /**
     * Add URLs to Product
     *
     * Queues each URL to be scraped and added to the product in the background. URLs that
     * are not valid, repeat an earlier URL in the list, or are already on the product are
     * returned in `skipped` with a reason (`invalid`, `duplicate` or `existing`). A URL
     * that is queued can still fail later, for example when the page has no price. The
     * user then gets a "URL not added" notification.
     *
     * @return JsonResponse
     */
    public function handler(AddProductUrlsRequest $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $result = (new AddUrlsToProductAction)(
            $model,
            $request->validated('urls'),
            (int) auth()->id(),
            (float) $request->validated('price_factor', 1),
        );

        return response()->json([
            'data' => $result,
            'message' => count($result['queued']).' URLs queued',
        ], 202);
    }
}
