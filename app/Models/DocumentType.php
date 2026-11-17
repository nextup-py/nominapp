<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Tipo de documento del legajo definido por cada empresa (cédula, carné de salud…), con o sin vencimiento. */
class DocumentType extends Model
{
    protected $fillable = ['company_id', 'name', 'requires_expiry', 'is_active'];

    protected function casts(): array
    {
        return ['requires_expiry' => 'boolean', 'is_active' => 'boolean'];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function documents(): HasMany
    {
        return $this->hasMany(Document::class);
    }

    /** Solo los tipos activos (los inactivos no se ofrecen al subir, pero los documentos ya cargados los conservan). */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
