<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/** Validación del pedido de horas extra que el empleado hace desde su celular. */
class StoreMobileOvertimeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string, array<int, string>> */
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'max_hours' => ['required', 'numeric'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
            'reason' => ['required', 'string', 'max:60'],
            'notes' => ['nullable', 'string', 'max:500'],
            'regularization' => ['nullable', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'date_from.required' => 'Indique la fecha.',
            'max_hours.required' => 'Indique cuántas horas extra estima.',
            'reason.required' => 'Elija un motivo.',
        ];
    }
}
