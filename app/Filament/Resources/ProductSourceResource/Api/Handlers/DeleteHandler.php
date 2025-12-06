<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Handlers;

use App\Filament\Resources\ProductSourceResource;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Rupadana\ApiService\Http\Handlers;

#[Group('ProductSource')]
class DeleteHandler extends Handlers
{
    public static ?string $uri = '/{id}';

    public static ?string $resource = ProductSourceResource::class;

    public static function getMethod()
    {
        return Handlers::DELETE;
    }

    public static function getModel()
    {
        return static::$resource::getModel();
    }

    /**
     * Delete Product Source
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function handler(Request $request)
    {
        $id = $request->route('id');

        $model = static::getModel()::where('id', $id)->where('user_id', auth()->id())->first();

        if (! $model) {
            return static::sendNotFoundResponse();
        }

        Gate::authorize('delete', $model);

        $model->delete();

        return response()->json([], 204);
    }
}
