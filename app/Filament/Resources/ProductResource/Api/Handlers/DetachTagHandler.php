<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class DetachTagHandler extends Handlers
{
    public static ?string $uri = '/{id}/tags/{tagId}';

    public static ?string $resource = ProductResource::class;

    public static function getMethod()
    {
        return Handlers::DELETE;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Removing a tag from a product is an update to it, so reuse that ability rather than add a new one.
     */
    public static function getAbility(): array
    {
        return ['product:update'];
    }

    /**
     * Remove Product Tag
     *
     * Removes one tag from the product. The tag record itself is not deleted.
     *
     * @return JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $model->tags()->detach((int) $request->route('tagId'));

        return response()->json([], 204);
    }
}
