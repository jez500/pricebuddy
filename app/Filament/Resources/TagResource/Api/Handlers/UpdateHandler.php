<?php

namespace App\Filament\Resources\TagResource\Api\Handlers;

use App\Filament\Resources\TagResource;
use App\Filament\Resources\TagResource\Api\Requests\UpdateTagRequest;
use Dedoc\Scramble\Attributes\Group;
use Rupadana\ApiService\Http\Handlers;

#[Group(TagResource::API_GROUP)]
class UpdateHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = TagResource::class;

    public static function getMethod()
    {
        return Handlers::PUT;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Update Tag
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(UpdateTagRequest $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        $model->fill($request->all());

        $model->save();

        return static::sendSuccessResponse($model, 'Successfully Update Resource');
    }
}
