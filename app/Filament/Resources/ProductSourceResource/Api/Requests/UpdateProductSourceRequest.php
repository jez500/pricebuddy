<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Requests;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Rules\ContainsSearchTermPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class UpdateProductSourceRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255',
            'search_url' => ['sometimes', 'url', new ContainsSearchTermPlaceholder],
            'type' => 'sometimes|in:'.implode(',', array_column(ProductSourceType::cases(), 'value')),
            'store_id' => 'nullable|exists:stores,id',
            'extraction_strategy' => 'sometimes|array',
            'extraction_strategy.list_container' => 'sometimes|array',
            'extraction_strategy.list_container.type' => 'sometimes|string',
            'extraction_strategy.list_container.value' => 'sometimes|string',
            'extraction_strategy.product_title' => 'sometimes|array',
            'extraction_strategy.product_title.type' => 'sometimes|string',
            'extraction_strategy.product_title.value' => 'sometimes|string',
            'extraction_strategy.product_url' => 'sometimes|array',
            'extraction_strategy.product_url.type' => 'sometimes|string',
            'extraction_strategy.product_url.value' => 'sometimes|string',
            'settings' => 'nullable|array',
            'status' => 'sometimes|in:'.implode(',', array_column(ProductSourceStatus::cases(), 'value')),
            'notes' => 'nullable|string',
        ];
    }
}
