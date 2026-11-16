<?php

namespace App\Models;

use App\Services\MobileMarkingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Support\Collection;

class Position extends Model
{
    protected $fillable = [
        'name',
        'department_id',
        'parent_id',
        'mobile_marking_allowed',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['mobile_marking_allowed' => 'boolean'];
    }

    protected static function booted(): void
    {
        static::updated(function (Position $position): void {
            if ($position->wasChanged('mobile_marking_allowed')) {
                MobileMarkingService::revokeDisallowed(
                    Employee::query()->whereHas('activeContract', fn ($q) => $q->where('position_id', $position->id))
                );
            }
        });
    }

    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    public function employees(): HasManyThrough
    {
        return $this->hasManyThrough(
            Employee::class,
            Contract::class,
            'position_id', // FK en contracts
            'id',          // FK en employees
            'id',          // local key en positions
            'employee_id'  // local key en contracts
        )->where('contracts.status', 'active');
    }

    /**
     * Cargo padre al que reporta este cargo.
     */
    public function parent()
    {
        return $this->belongsTo(Position::class, 'parent_id');
    }

    /**
     * Cargos hijos que reportan a este cargo.
     */
    public function children()
    {
        return $this->hasMany(Position::class, 'parent_id');
    }

    /**
     * Obtiene todos los descendientes de forma recursiva.
     */
    public function getAllDescendants(): Collection
    {
        $descendants = collect();

        foreach ($this->children as $child) {
            $descendants->push($child);
            $descendants = $descendants->merge($child->getAllDescendants());
        }

        return $descendants;
    }

    /**
     * Obtiene todos los IDs de descendientes (para prevenir ciclos).
     */
    public function getAllDescendantIds(): array
    {
        return $this->getAllDescendants()->pluck('id')->toArray();
    }

    /**
     * Verifica si este cargo es raíz (sin padre).
     */
    public function isRoot(): bool
    {
        return is_null($this->parent_id);
    }

    /**
     * Obtiene el nivel jerárquico del cargo (0 = raíz).
     */
    public function getHierarchyLevel(): int
    {
        $level = 0;
        $current = $this;

        while ($current->parent) {
            $level++;
            $current = $current->parent;
        }

        return $level;
    }

    /**
     * Obtiene las opciones de cargos con sus departamentos para selects
     */
    public static function getOptionsWithDepartment(): array
    {
        return static::with('department')
            ->get()
            ->mapWithKeys(function ($position) {
                $label = $position->name;
                if ($position->department) {
                    $label .= ' - '.$position->department->name;
                }

                return [$position->id => $label];
            })
            ->toArray();
    }
}
