<?php

namespace App\Http\Controllers\Settings;

use App\Enums\DocumentFont;
use App\Enums\DocumentLayout;
use App\Enums\DocumentType;
use App\Http\Controllers\Controller;
use App\Http\Requests\DocumentDesignRequest;
use App\Models\Business;
use App\Support\DocumentDesign;
use App\Support\DocumentPdf;
use App\Support\SampleDocument;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * How the business's estimates and invoices look: layout, logo, colours, font and footer.
 */
class DocumentDesignController extends Controller
{
    public function edit(Request $request): Response
    {
        $business = $request->user()->currentBusiness;
        $design = DocumentDesign::forBusiness($business);

        return Inertia::render('settings/invoice-design', [
            'design' => [
                'document_layout' => $design->layout->value,
                'document_colour' => $design->colour,
                'document_accent_colour' => $design->accent,
                'document_font' => $design->font->value,
                'document_footer' => $design->footer ?? '',
            ],
            'logoUrl' => $business->document_logo_path ? route('invoice-design.logo', ['v' => md5($business->document_logo_path)]) : null,
            'layouts' => DocumentLayout::options(),
            'fonts' => DocumentFont::options(),
            'canUpdate' => $request->user()->can('update', $business),
        ]);
    }

    public function update(DocumentDesignRequest $request): RedirectResponse
    {
        $business = $request->user()->currentBusiness;

        Gate::authorize('update', $business);

        $validated = $request->validated();
        $oldLogo = $business->document_logo_path;

        if ($request->hasFile('logo')) {
            // Write the contents rather than using store(): store() opens the upload by its real
            // path, which PHP can't resolve for the temp folder on some Windows setups (Laragon),
            // failing with "Path must not be empty".
            $logo = $request->file('logo');
            $path = "logos/{$business->id}/".Str::random(40).'.'.($logo->guessExtension() ?: 'png');
            Storage::disk(DocumentDesign::DISK)->put($path, $logo->get());
            $business->document_logo_path = $path;
        } elseif ($request->boolean('remove_logo')) {
            $business->document_logo_path = null;
        }

        $business->fill([
            'document_layout' => $validated['document_layout'],
            'document_colour' => strtoupper($validated['document_colour']),
            'document_accent_colour' => strtoupper($validated['document_accent_colour']),
            'document_font' => $validated['document_font'],
            'document_footer' => $validated['document_footer'] ?? null,
        ])->save();

        if ($oldLogo && $oldLogo !== $business->document_logo_path) {
            Storage::disk(DocumentDesign::DISK)->delete($oldLogo);
        }

        return to_route('invoice-design.edit');
    }

    /**
     * Render a sample estimate or invoice with the design on the form, saved or not, as HTML for
     * the live preview or as a PDF.
     */
    public function preview(DocumentDesignRequest $request): HttpResponse
    {
        $business = $request->user()->currentBusiness;
        $validated = $request->validated();

        $request->validate([
            'type' => ['sometimes', Rule::enum(DocumentType::class)],
            'format' => ['sometimes', 'in:html,pdf'],
        ]);

        $design = new DocumentDesign(
            layout: DocumentLayout::from($validated['document_layout']),
            colour: strtoupper($validated['document_colour']),
            accent: strtoupper($validated['document_accent_colour']),
            font: DocumentFont::from($validated['document_font']),
            footer: ($validated['document_footer'] ?? null) ?: null,
            logo: $this->previewLogo($request, $business),
        );

        $document = SampleDocument::make($business, DocumentType::tryFrom((string) $request->input('type')) ?? DocumentType::Invoice);

        if ($request->input('format') === 'pdf') {
            return response(DocumentPdf::render($document, $design), 200, [
                'Content-Type' => 'application/pdf',
                'Content-Disposition' => 'inline; filename="Sample '.strtolower($document->type->label()).'.pdf"',
            ]);
        }

        return response(DocumentPdf::html($document, $design, preview: true), 200, [
            'Content-Type' => 'text/html; charset=UTF-8',
        ]);
    }

    /**
     * The saved logo, for the settings page.
     */
    public function logo(Request $request): HttpResponse
    {
        $path = $request->user()->currentBusiness->document_logo_path;

        abort_unless($path && Storage::disk(DocumentDesign::DISK)->exists($path), 404);

        return Storage::disk(DocumentDesign::DISK)->response($path, headers: ['Cache-Control' => 'private, max-age=86400']);
    }

    private function previewLogo(Request $request, Business $business): ?string
    {
        if ($request->hasFile('logo')) {
            $file = $request->file('logo');

            return DocumentDesign::dataUri($file->getContent(), $file->getMimeType() ?: 'image/png');
        }

        if ($request->boolean('remove_logo')) {
            return null;
        }

        return DocumentDesign::logoDataUri($business->document_logo_path);
    }
}
