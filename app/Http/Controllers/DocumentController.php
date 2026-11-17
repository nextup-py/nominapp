<?php

namespace App\Http\Controllers;

use App\Models\Document;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/** Descarga de documentos del legajo: los privados no tienen URL pública, se sirven solo con permiso. */
class DocumentController extends Controller
{
    /** Descarga el archivo del documento (el middleware `can:view,document` de la ruta ya validó el permiso). */
    public function download(Document $document): StreamedResponse
    {
        $disk = Storage::disk($document->disk ?: 'public');

        abort_unless($disk->exists($document->file_path), 404, 'El archivo ya no está disponible.');

        $response = $disk->download($document->file_path, $document->name.'.'.strtolower($document->file_extension));
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');

        return $response;
    }
}
