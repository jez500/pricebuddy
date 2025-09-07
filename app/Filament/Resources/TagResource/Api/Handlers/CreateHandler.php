<?php

namespace App\Filament\Resources\TagResource\Api\Handlers;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Api\Requests\CreateTagRequest;
use App\Filament\Resources\TagResource\Api\Transformers\TagTransformer;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(TagResource::API_GROUP)]
class CreateHandler extends Handlers
{
    public static ?string $uri = '/';

    public static ?string $resource = TagResource::class;

    public static function getMethod()
    {
        return Handlers::POST;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Create Tag
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(CreateTagRequest $request)
    {
        $model = new (static::getModel());

        $values = $request->all();
        $values['user_id'] = $values['user_id'] ?? auth()->id();

        $model->fill($values);

        $model->save();

        return response()->json([
            'data' => new TagTransformer($model),
            'message' => 'Tag created',
        ], 201);
    }
}
