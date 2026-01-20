<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date' => 'nullable|date_format:Y-m-d|before_or_equal:today',
        ];
    }

    public function messages(): array
    {
        return [
            'date.date_format' => 'Date must be in Y-m-d format',
            'date.before_or_equal' => 'Date cannot be in the future',
        ];
    }
}
