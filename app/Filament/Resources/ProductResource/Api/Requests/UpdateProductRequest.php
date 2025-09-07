<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use App\Enums\Statuses;
use Illuminate\Foundation\Http\FormRequest;

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
     * @return array<string, \Illuminate\Contracts\Validation\ValidationRule|array<mixed>|string>
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
        ];
    }
}
