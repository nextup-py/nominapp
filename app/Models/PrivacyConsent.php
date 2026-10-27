<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Http\Request;

/**
 * Aceptación del aviso de privacidad de la empresa por parte de un empleado. Guarda la versión aceptada y una
 * copia del texto y del enlace de ese momento: la empresa solo conserva el aviso vigente.
 */
class PrivacyConsent extends Model
{
    /** Autoenrolamiento facial (enlace de registro facial). */
    public const CONTEXT_FACE_ENROLLMENT = 'face_enrollment';

    /** Vinculación del celular personal (`/vincular-dispositivo`). */
    public const CONTEXT_MOBILE_LINK = 'mobile_link';

    protected $fillable = [
        'employee_id',
        'company_id',
        'context',
        'notice_version',
        'notice_text',
        'notice_url',
        'ip_address',
        'user_agent',
        'accepted_at',
    ];

    protected $casts = [
        'notice_version' => 'integer',
        'accepted_at' => 'datetime',
    ];

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /** @return array<string, string> */
    public static function getContextLabels(): array
    {
        return [
            self::CONTEXT_FACE_ENROLLMENT => 'Registro facial',
            self::CONTEXT_MOBILE_LINK => 'Vinculación del celular',
        ];
    }

    /**
     * Guarda la aceptación de la versión vigente del aviso de la empresa.
     *
     * @param  array{text: string|null, url: string|null, version: int, qr: string|null}  $notice  `Company::privacyNoticePayload()`
     */
    public static function record(Employee $employee, Company $company, string $context, array $notice, Request $request): self
    {
        return self::create([
            'employee_id' => $employee->id,
            'company_id' => $company->id,
            'context' => $context,
            'notice_version' => $notice['version'],
            'notice_text' => $notice['text'],
            'notice_url' => $notice['url'],
            'ip_address' => $request->ip(),
            'user_agent' => substr($request->userAgent() ?? '', 0, 255),
            'accepted_at' => now(),
        ]);
    }
}
