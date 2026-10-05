<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Requests\SyncProductTagsRequest;
use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class SyncTagsHandler extends Handlers
{
    public static ?string $uri = '/{id}/tags';

    public static ?string $resource = ProductResource::class;

    public static function getMethod()
    {
        return Handlers::PUT;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Tagging a product is an update to it, so reuse that ability rather than add a new one.
     */
    public static function getAbility(): array
    {
        return ['product:update'];
    }

    /**
     * Set Product Tags
     *
     * Replaces the product's tags with the given tag ids. Send an empty list to remove all tags.
     *
     * @return JsonResponse
     */
    public function handler(SyncProductTagsRequest $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $model->tags()->sync($request->validated('tags'));

        return static::sendSuccessResponse(
            TagTransformer::collection($model->tags()->get()->makeHidden('pivot')),
            'Successfully Update Resource'
        );
    }
}
