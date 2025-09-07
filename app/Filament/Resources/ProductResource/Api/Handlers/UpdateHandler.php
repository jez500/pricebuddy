<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Requests\UpdateProductRequest;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class UpdateHandler extends Handlers
{
    public static ?string $uri = '/{id}';

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
     * Update Product
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(UpdateProductRequest $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $values = $request->validated();
        unset($values['user_id']);

        $model->fill($values);
        $model->save();

        return static::sendSuccessResponse($model, 'Successfully Update Resource');
    }
}
