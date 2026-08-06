<?php

namespace App\Http\Requests\Animals;

use Illuminate\Foundation\Http\FormRequest;

class IndexAnimalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:100'],
        ];
    }
}
