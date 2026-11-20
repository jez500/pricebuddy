<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use App\Actions\AddUrlsToProductAction;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class AddProductUrlsRequest extends FormRequest
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
            'urls' => 'required|array|min:1|max:'.AddUrlsToProductAction::MAX_URLS,
            // Invalid URLs are reported in the "skipped" list, not rejected, so one bad
            // line does not block the rest of the list.
            'urls.*' => 'string|max:2048',
            'price_factor' => 'sometimes|numeric|min:0.01',
            // The same variant is tracked on every URL, eg size "Small" across stores.
            ...CreateProductRequest::variantRules(),
        ];
    }
}
