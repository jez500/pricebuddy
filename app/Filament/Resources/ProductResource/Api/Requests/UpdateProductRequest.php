<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use App\Enums\Statuses;
use App\Filament\Resources\ProductResource;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateProductRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'image' => 'required|url',
            'status' => 'sometimes|in:'.implode(',', Statuses::values()),
            'weight' => 'sometimes|numeric',
            'notify_price' => 'sometimes|numeric',
            'notify_percent' => 'sometimes|numeric',
            'favourite' => 'sometimes|boolean',
            'only_official' => 'nullable|boolean',
            'notify_in_stock' => 'sometimes|boolean',
            'paused' => 'sometimes|boolean',
            'refresh_interval' => ['sometimes', 'nullable', 'integer', Rule::in(array_keys(ProductResource::REFRESH_INTERVALS))],
        ];
    }
}
