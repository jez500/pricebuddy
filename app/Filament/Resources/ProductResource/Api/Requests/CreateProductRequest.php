<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use App\Enums\Statuses;
use Illuminate\Foundation\Http\FormRequest;

class CreateProductRequest extends FormRequest
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
            'title' => 'required',
            'url' => 'required|url',
            'product_id' => 'nullable|exists:products,id',
            'image' => 'nullable|url',
            'status' => 'sometimes|in:'.implode(',', array_keys(Statuses::values())),
            'weight' => 'sometimes|numeric',
            'notify_price' => 'sometimes|numeric',
            'notify_percent' => 'sometimes|numeric',
            'favourite' => 'sometimes|boolean',
            'only_official' => 'nullable|boolean',
            'create_store' => 'sometimes|boolean',
        ];
    }
}
