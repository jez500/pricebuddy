<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SyncProductTagsRequest extends FormRequest
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
            'tags' => 'present|array',
            // Only the current user's tags, so a product cannot be put in someone else's tag.
            'tags.*' => ['integer', 'distinct', Rule::exists('tags', 'id')->where('user_id', auth()->id())],
        ];
    }
}
