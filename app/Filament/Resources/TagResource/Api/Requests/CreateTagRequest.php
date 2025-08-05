<?php

namespace App\Filament\Resources\TagResource\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CreateTagRequest extends FormRequest
{
    public const string NAME_RULE = 'required|string|max:255|unique:tags,name';

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
            'name' => self::NAME_RULE,
            'user_id' => 'nullable|exists:users,id|in:'.auth()->id(),
        ];
    }
}
