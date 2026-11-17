<?php

namespace App\Models;

use App\Settings\GeneralSettings;
use Database\Factories\DocumentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class Document extends Model
{
    /** @use HasFactory<DocumentFactory> */
    use HasFactory;

    /** Disco privado de los documentos nuevos (no se sirven por URL pública: se descargan con permiso). */
    public const PRIVATE_DISK = 'local';

    protected $fillable = [
        'employee_id',
        'document_type_id',
        'name',
        'file_path',
        'disk',
        'expires_at',
        'uploaded_by_id',
        'expiring_notified_at',
        'expired_notified_at',
    ];

    protected function casts(): array
    {
        return [
            'expires_at' => 'date',
            'expiring_notified_at' => 'datetime',
            'expired_notified_at' => 'datetime',
        ];
    }

    /** Al cambiar el vencimiento los avisos vuelven a empezar para ese documento. */
    protected static function booted(): void
    {
        static::updating(function (self $document): void {
            if ($document->isDirty('expires_at')) {
                $document->expiring_notified_at = null;
                $document->expired_notified_at = null;
            }
        });
    }

    public function employee()
    {
        return $this->belongsTo(Employee::class);
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_id');
    }

    /**
     * Estado del vencimiento: `none` (sin fecha), `expired`, `expiring` (vence dentro de `$alertDays`) o `valid`.
     * Es la única definición en PHP; las condiciones en SQL son `scopeExpired()` y `scopeExpiringWithin()`.
     */
    public function expiryStatus(?int $alertDays = null): string
    {
        if ($this->expires_at === null) {
            return 'none';
        }

        if ($this->expires_at->lt(today())) {
            return 'expired';
        }

        $alertDays ??= (int) app(GeneralSettings::class)->document_alert_days;

        return $alertDays > 0 && $this->expires_at->lte(today()->addDays($alertDays)) ? 'expiring' : 'valid';
    }

    /** @return array<string, string> */
    public static function getExpiryLabels(): array
    {
        return ['expired' => 'Vencido', 'expiring' => 'Por vencer', 'valid' => 'Vigente', 'none' => 'Sin vencimiento'];
    }

    /** @return array<string, string> */
    public static function getExpiryColors(): array
    {
        return ['expired' => 'danger', 'expiring' => 'warning', 'valid' => 'success', 'none' => 'gray'];
    }

    /** Documentos con vencimiento anterior a hoy. */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', today()->toDateString());
    }

    /** Documentos que vencen entre hoy y dentro de `$days` días (inclusive). */
    public function scopeExpiringWithin(Builder $query, int $days): Builder
    {
        return $query->whereNotNull('expires_at')
            ->where('expires_at', '>=', today()->toDateString())
            ->where('expires_at', '<=', today()->addDays($days)->toDateString());
    }

    /** Si el archivo está en el disco privado (se descarga con permiso, nunca por URL pública). */
    public function isPrivate(): bool
    {
        return $this->disk !== 'public';
    }

    /**
     * Obtiene la URL pública del archivo
     */
    public function getFileUrlAttribute(): string
    {
        return asset('storage/'.$this->file_path);
    }

    /**
     * Obtiene la extensión del archivo
     */
    public function getFileExtensionAttribute(): string
    {
        return strtoupper(pathinfo($this->file_path, PATHINFO_EXTENSION));
    }

    /**
     * Obtiene el color del badge según el tipo de archivo
     */
    public function getFileTypeColorAttribute(): string
    {
        return match (strtolower($this->file_extension)) {
            'pdf' => 'danger',
            'jpg', 'jpeg', 'png', 'gif' => 'success',
            'docx', 'doc' => 'info',
            'xlsx', 'xls' => 'warning',
            'pptx', 'ppt' => 'primary',
            default => 'gray',
        };
    }

    /**
     * Obtiene el ícono según el tipo de archivo
     */
    public function getFileTypeIconAttribute(): string
    {
        return match (strtolower($this->file_extension)) {
            'pdf' => 'heroicon-o-document-text',
            'jpg', 'jpeg', 'png', 'gif' => 'heroicon-o-photo',
            'docx', 'doc' => 'heroicon-o-document',
            'xlsx', 'xls' => 'heroicon-o-table-cells',
            'pptx', 'ppt' => 'heroicon-o-presentation-chart-bar',
            default => 'heroicon-o-document',
        };
    }

    /**
     * Obtiene el tamaño del archivo formateado
     */
    public function getFileSizeFormattedAttribute(): string
    {
        $disk = Storage::disk($this->disk ?: 'public');

        if (! $disk->exists($this->file_path)) {
            return 'N/A';
        }

        $bytes = $disk->size($this->file_path);

        if ($bytes >= 1048576) {
            return number_format($bytes / 1048576, 2).' MB';
        } elseif ($bytes >= 1024) {
            return number_format($bytes / 1024, 2).' KB';
        }

        return $bytes.' B';
    }

    /**
     * Obtiene la fecha de creación formateada
     */
    public function getCreatedAtDescriptionAttribute(): string
    {
        return $this->created_at->format('d/m/Y H:i');
    }

    /**
     * Obtiene la fecha de creación en formato "hace X tiempo"
     */
    public function getCreatedAtSinceAttribute(): string
    {
        return $this->created_at->diffForHumans();
    }

    /**
     * Obtiene la fecha de actualización formateada
     */
    public function getUpdatedAtDescriptionAttribute(): string
    {
        return $this->updated_at->format('d/m/Y H:i');
    }

    /**
     * Obtiene la fecha de actualización en formato "hace X tiempo"
     */
    public function getUpdatedAtSinceAttribute(): string
    {
        return $this->updated_at->diffForHumans();
    }

    /**
     * Obtiene los tipos de archivo aceptados
     */
    public static function getAcceptedFileTypes(): array
    {
        return [
            'application/pdf', // PDF
            'image/jpeg', // Imágenes JPEG
            'image/jpg', // Imágenes JPG
            'image/png', // Imágenes PNG
            'image/gif', // Imágenes GIF
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',  // Word (.docx)
            'application/msword', // Word (.doc)
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // Excel (.xlsx)
            'application/vnd.ms-excel', // Excel (.xls)
            'application/vnd.openxmlformats-officedocument.presentationml.presentation', // PowerPoint (.pptx)
            'application/vnd.ms-powerpoint', // PowerPoint (.ppt)
        ];
    }

    /**
     * Obtiene las opciones de filtro por tipo de archivo
     */
    public static function getFileTypeFilterOptions(): array
    {
        return [
            'pdf' => 'PDF',
            'jpg,jpeg,png,gif' => 'Imágenes',
            'docx,doc' => 'Word',
            'xlsx,xls' => 'Excel',
            'pptx,ppt' => 'PowerPoint',
        ];
    }
}
