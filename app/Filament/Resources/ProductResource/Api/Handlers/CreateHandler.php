<?php

namespace App\Filament\Resources\ProductResource\Api\Handlers;

use App\Filament\Resources\ProductResource;
use App\Filament\Resources\ProductResource\Api\Requests\CreateProductRequest;
use App\Filament\Resources\ProductResource\Api\Transformers\ProductTransformer;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(ProductResource::API_GROUP)]
class CreateHandler extends Handlers
{
    public static ?string $uri = '/';

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
     * Create Product
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(CreateProductRequest $request)
    {
        $model = new (static::getModel());

        $model->fill($request->all());

        $model->save();

        return response()->json([
            'data' => new ProductTransformer($model),
            'message' => 'Successfully Create Resource',
        ], 201);
    }
}
