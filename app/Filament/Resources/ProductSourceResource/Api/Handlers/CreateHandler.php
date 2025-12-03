<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use App\Filament\Resources\ProductSourceResource\Api\Requests\CreateProductSourceRequest;
use App\Filament\Resources\ProductSourceResource\Api\Transformers\ProductSourceTransformer;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group('ProductSource')]
class CreateHandler extends Handlers
{
    public static ?string $uri = '/';

    public static ?string $resource = ProductSourceResource::class;

    public static function getMethod()
    {
        return Handlers::POST;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Create Product Source
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(CreateProductSourceRequest $request)
    {
        $values = $request->validated();
        $values['user_id'] = auth()->id();

        $model = static::getModel()::create($values);

        return response()->json([
            'data' => new ProductSourceTransformer($model),
            'message' => 'Product source created',
        ], 201);
    }
}
