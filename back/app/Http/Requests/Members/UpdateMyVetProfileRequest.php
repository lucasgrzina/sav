<?php

namespace App\Http\Requests\Members;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use Illuminate\Foundation\Http\FormRequest;

class UpdateMyVetProfileRequest extends FormRequest
{
    use ValidatesContactsArray;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return array_merge(
            [
                'first_name' => ['required', 'string', 'max:100'],
                'last_name'  => ['required', 'string', 'max:100'],
            ],
            $this->contactsRules(),
        );
    }

    public function messages(): array
    {
        return array_merge(
            [
                'first_name.required' => 'El nombre es obligatorio.',
                'last_name.required'  => 'El apellido es obligatorio.',
            ],
            $this->contactsMessages(),
        );
    }
}
