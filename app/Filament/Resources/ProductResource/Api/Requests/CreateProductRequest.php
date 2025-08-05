<?php

namespace App\Filament\Resources\ProductResource\Api\Requests;

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
            'image' => 'required',
            'status' => 'required',
            'weight' => 'required',
            'current_price' => 'required|numeric',
            'notify_price' => 'required|numeric',
            'notify_percent' => 'required|numeric',
            'favourite' => 'required',
            'only_official' => 'required',
            'price_cache' => 'required',
            'ignored_urls' => 'required',
            'user_id' => 'required',
        ];
    }
}
