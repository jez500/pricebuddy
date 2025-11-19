<?php

namespace App\Filament\Resources\StoreResource\Api\Handlers;

use App\Actions\CreateStoreAction;
use App\Filament\Resources\StoreResource;
use App\Filament\Resources\StoreResource\Api\Requests\CreateStoreRequest;
use App\Filament\Resources\StoreResource\Api\Transformers\StoreTransformer;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(StoreResource::API_GROUP)]
class CreateHandler extends Handlers
{
    public static ?string $uri = '/';

    public static ?string $resource = StoreResource::class;

    public static function getMethod()
    {
        return Handlers::POST;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Create Store
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(CreateStoreRequest $request)
    {
        $values = $request->all();
        $values['user_id'] = $values['user_id'] ?? auth()->id();

        $action = new CreateStoreAction;
        $model = $action($values);

        return response()->json([
            'data' => new StoreTransformer($model),
            'message' => 'Store created',
        ], 201);
    }
}
