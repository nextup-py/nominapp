<?php

namespace App\Http\Requests;

use App\Services\OrgChartService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Filtros del Organigrama (sucursal, departamento, búsqueda y vacantes), compartidos por la vista y el PDF. */
class OrgChartRequest extends FormRequest
{
    /** El acceso lo resuelve el grupo de rutas autenticadas. */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Reglas de validación: sucursal y departamento deben pertenecer a la empresa de la ruta.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $companyId = $this->route('company')?->id;

        return [
            'branch' => ['nullable', 'integer', Rule::exists('branches', 'id')->where('company_id', $companyId)],
            'department' => ['nullable', 'integer', Rule::exists('departments', 'id')->where('company_id', $companyId)],
            'q' => ['nullable', 'string', 'max:100'],
            'vacancies' => ['nullable', 'boolean'],
        ];
    }

    /**
     * Filtros normalizados para {@see OrgChartService}. Las vacantes se muestran salvo que se pida lo contrario.
     *
     * @return array{branch: ?int, department: ?int, search: ?string, vacancies: bool}
     */
    public function filters(): array
    {
        return [
            'branch' => $this->filled('branch') ? (int) $this->input('branch') : null,
            'department' => $this->filled('department') ? (int) $this->input('department') : null,
            'search' => filled($this->input('q')) ? trim((string) $this->input('q')) : null,
            'vacancies' => $this->has('vacancies') ? $this->boolean('vacancies') : true,
        ];
    }

    /**
     * Parámetros de la URL que reproducen los filtros activos (para el enlace al PDF).
     *
     * @return array<string, mixed>
     */
    public function queryParams(): array
    {
        $filters = $this->filters();

        return array_filter([
            'branch' => $filters['branch'],
            'department' => $filters['department'],
            'q' => $filters['search'],
            'vacancies' => $filters['vacancies'] ? null : 0,
        ], fn ($value) => $value !== null);
    }
}
