<?php

namespace App\Http\Requests\Clients;

use App\Http\Requests\Concerns\ValidatesContactsArray;
use App\Models\DocumentType;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
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
                'name'               => ['sometimes', 'string', 'max:150'],
                'country_guid'       => ['sometimes', 'string', 'exists:countries,guid'],
                'document_type_guid' => ['sometimes', 'string', 'exists:document_types,guid'],
                'tax_id'             => ['sometimes', 'string', 'max:50', $this->taxIdRule()],
                'address'            => ['nullable', 'string', 'max:200'],
                'city'               => ['nullable', 'string', 'max:100'],
                'state'              => ['nullable', 'string', 'max:100'],
                'zip_code'           => ['nullable', 'string', 'max:20'],
            ],
            $this->contactsRules(),
        );
    }

    private function taxIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            $docTypeGuid = $this->input('document_type_guid');

            if (!$docTypeGuid) {
                return;
            }

            $docType = DocumentType::where('guid', $docTypeGuid)->first();

            if (!$docType || !$docType->validation_regex) {
                return;
            }

            $pattern = '/' . $docType->validation_regex . '/';

            if (!preg_match($pattern, $value)) {
                $fail("El formato del {$docType->name} es inválido.");
            }
        };
    }

    public function messages(): array
    {
        return array_merge(
            [
                'name.max'                  => 'El nombre no puede superar 150 caracteres.',
                'country_guid.exists'       => 'El país seleccionado no existe.',
                'document_type_guid.exists' => 'El tipo de documento seleccionado no existe.',
                'tax_id.max'                => 'El identificador fiscal no puede superar 50 caracteres.',
            ],
            $this->contactsMessages(),
        );
    }
}
