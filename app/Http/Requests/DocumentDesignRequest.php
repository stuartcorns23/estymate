<?php

namespace App\Http\Requests;

use App\Enums\DocumentFont;
use App\Enums\DocumentLayout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class DocumentDesignRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'document_layout' => ['required', Rule::enum(DocumentLayout::class)],
            'document_colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'document_accent_colour' => ['required', 'string', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'document_font' => ['required', Rule::enum(DocumentFont::class)],
            'document_footer' => ['nullable', 'string', 'max:500'],
            // PNG and JPEG only: they're what the PDF renderer embeds reliably.
            'logo' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_logo' => ['sometimes', 'boolean'],
            'document_footer_details' => ['sometimes', 'boolean'],
            // Uploaded or drawn on the page; drawn signatures arrive as PNGs.
            'signature' => ['nullable', 'file', 'mimes:png,jpg,jpeg', 'max:1024'],
            'remove_signature' => ['sometimes', 'boolean'],
            'document_show_signature' => ['sometimes', 'boolean'],
            'document_signature_name' => ['nullable', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'document_colour.regex' => 'Choose a colour like #1F2937.',
            'document_accent_colour.regex' => 'Choose a colour like #2563EB.',
            'logo.mimes' => 'Upload your logo as a PNG or JPG.',
            'logo.max' => 'Your logo must be 1 MB or smaller.',
            'signature.mimes' => 'Upload your signature as a PNG or JPG.',
            'signature.max' => 'Your signature must be 1 MB or smaller.',
        ];
    }
}
