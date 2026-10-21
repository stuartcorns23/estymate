<?php

namespace App\Support;

use App\Models\Document;
use Barryvdh\DomPDF\Facade\Pdf;

class DocumentPdf
{
    /**
     * Render the document as a PDF and return its bytes.
     */
    public static function render(Document $document): string
    {
        $document->loadMissing(['business', 'client', 'items']);

        return Pdf::loadView('pdf.document', ['document' => $document])
            ->setPaper('a4')
            ->output();
    }

    public static function filename(Document $document): string
    {
        return "{$document->type->label()} {$document->number}.pdf";
    }
}
