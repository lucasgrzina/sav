<?php

namespace App\Http\Requests\EstablishmentHealthPlans;

use Illuminate\Foundation\Http\FormRequest;

class ConfirmEstablishmentHealthPlanActivityRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [];
    }
}
