<?php

namespace App\Filament\Resources\TagResource\Api\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UpdateTagRequest extends FormRequest
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
            'name' => 'sometimes|string|max:255|unique:tags,name,'.$this->route('id').',id,user_id,'.auth()->id(),
            'user_id' => 'sometimes|exists:users,id|in:'.auth()->id(),
        ];
    }
}
