<?php

namespace App\Filament\Resources\ProductSourceResource\Api\Requests;

use App\Enums\ProductSourceStatus;
use App\Enums\ProductSourceType;
use App\Rules\ContainsSearchTermPlaceholder;
use Illuminate\Foundation\Http\FormRequest;

class CreateProductSourceRequest extends FormRequest
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
            'name' => 'required|string|max:255',
            'search_url' => ['required', 'url', new ContainsSearchTermPlaceholder],
            'type' => 'required|in:'.implode(',', array_column(ProductSourceType::cases(), 'value')),
            'store_id' => 'nullable|exists:stores,id',
            'extraction_strategy' => 'required|array',
            'extraction_strategy.list_container' => 'required|array',
            'extraction_strategy.list_container.type' => 'required|string',
            'extraction_strategy.list_container.value' => 'required|string',
            'extraction_strategy.product_title' => 'required|array',
            'extraction_strategy.product_title.type' => 'required|string',
            'extraction_strategy.product_title.value' => 'required|string',
            'extraction_strategy.product_url' => 'required|array',
            'extraction_strategy.product_url.type' => 'required|string',
            'extraction_strategy.product_url.value' => 'required|string',
            'settings' => 'nullable|array',
            'status' => 'sometimes|in:'.implode(',', array_column(ProductSourceStatus::cases(), 'value')),
            'notes' => 'nullable|string',
        ];
    }
}
