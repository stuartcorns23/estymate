<?php

namespace App\Support;

use App\Enums\DocumentFont;
use App\Enums\DocumentLayout;
use App\Models\Business;
use Illuminate\Support\Facades\Storage;

/**
 * How a business's estimates and invoices look, ready for the PDF templates.
 */
class DocumentDesign
{
    /**
     * Logos are kept on this private disk and embedded into each PDF.
     */
    public const DISK = 'local';

    public function __construct(
        public readonly DocumentLayout $layout = DocumentLayout::Classic,
        public readonly string $colour = '#1F2937',
        public readonly string $accent = '#2563EB',
        public readonly DocumentFont $font = DocumentFont::Sans,
        public readonly ?string $footer = null,
        public readonly ?string $logo = null,
        // Show the business's address, contact and bank details in the footer.
        public readonly bool $footerDetails = true,
        // The signature image as a data URI, only when it should appear on documents.
        public readonly ?string $signature = null,
        public readonly ?string $signatureName = null,
    ) {
        $this->logoDimensions = self::imageDimensions($logo);
        $this->signatureDimensions = self::imageDimensions($signature);
    }

    /** @var array{int, int} */
    private array $logoDimensions;

    /** @var array{int, int} */
    private array $signatureDimensions;

    /**
     * The logo's size in pixels when scaled down to fit the box, keeping its shape.
     *
     * @return array{int, int}
     */
    public function logoSize(int $maxWidth, int $maxHeight): array
    {
        return self::fit($this->logoDimensions, $maxWidth, $maxHeight);
    }

    /**
     * The signature's size in pixels when scaled down to fit the box, keeping its shape.
     *
     * @return array{int, int}
     */
    public function signatureSize(int $maxWidth, int $maxHeight): array
    {
        return self::fit($this->signatureDimensions, $maxWidth, $maxHeight);
    }

    /**
     * @return array{int, int}
     */
    private static function imageDimensions(?string $dataUri): array
    {
        $size = $dataUri !== null ? @getimagesize($dataUri) : false;

        return $size ? [max(1, $size[0]), max(1, $size[1])] : [1, 1];
    }

    /**
     * @param  array{int, int}  $dimensions
     * @return array{int, int}
     */
    private static function fit(array $dimensions, int $maxWidth, int $maxHeight): array
    {
        [$width, $height] = $dimensions;
        $scale = min($maxWidth / $width, $maxHeight / $height, 1);

        return [max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale))];
    }

    public static function forBusiness(Business $business): self
    {
        return new self(
            layout: DocumentLayout::tryFrom((string) $business->document_layout) ?? DocumentLayout::Classic,
            colour: self::normaliseHex($business->document_colour, '#1F2937'),
            accent: self::normaliseHex($business->document_accent_colour, '#2563EB'),
            font: DocumentFont::tryFrom((string) $business->document_font) ?? DocumentFont::Sans,
            footer: $business->document_footer ?: null,
            logo: self::logoDataUri($business->document_logo_path),
            footerDetails: (bool) ($business->document_footer_details ?? true),
            signature: $business->document_show_signature ? self::logoDataUri($business->document_signature_path) : null,
            signatureName: $business->document_signature_name ?: null,
        );
    }

    /**
     * A saved image (logo or signature) as a data URI, so the PDF renderer never has to fetch a file.
     */
    public static function logoDataUri(?string $path): ?string
    {
        if (! $path || ! Storage::disk(self::DISK)->exists($path)) {
            return null;
        }

        return self::dataUri(Storage::disk(self::DISK)->get($path), Storage::disk(self::DISK)->mimeType($path) ?: 'image/png');
    }

    public static function dataUri(string $contents, string $mimeType): string
    {
        return 'data:'.$mimeType.';base64,'.base64_encode($contents);
    }

    /**
     * Text colour that stays readable on top of the given background.
     */
    public static function textOn(string $hex): string
    {
        // WCAG contrast against white and near-black text; pick whichever is higher.
        $luminance = self::luminance($hex);
        $againstWhite = 1.05 / ($luminance + 0.05);
        $againstDark = ($luminance + 0.05) / (self::luminance('#111827') + 0.05);

        return $againstWhite >= $againstDark ? '#FFFFFF' : '#111827';
    }

    public function onColour(): string
    {
        return self::textOn($this->colour);
    }

    public function onAccent(): string
    {
        return self::textOn($this->accent);
    }

    /**
     * The colour mixed with white, for light backgrounds. 0 is white, 1 is the colour itself.
     */
    public static function tint(string $hex, float $amount): string
    {
        [$r, $g, $b] = self::rgb($hex);
        $mix = fn (int $channel) => (int) round(255 - (255 - $channel) * $amount);

        return sprintf('#%02X%02X%02X', $mix($r), $mix($g), $mix($b));
    }

    /**
     * A version of the colour dark enough to use for text on white (at least 4.5:1).
     */
    public static function readableOnWhite(string $hex): string
    {
        [$r, $g, $b] = self::rgb($hex);

        for ($step = 0; $step < 20; $step++) {
            $candidate = sprintf('#%02X%02X%02X', $r, $g, $b);

            if (1.05 / (self::luminance($candidate) + 0.05) >= 4.5) {
                return $candidate;
            }

            [$r, $g, $b] = [(int) ($r * 0.85), (int) ($g * 0.85), (int) ($b * 0.85)];
        }

        return '#111827';
    }

    public function colourText(): string
    {
        return self::readableOnWhite($this->colour);
    }

    public function accentText(): string
    {
        return self::readableOnWhite($this->accent);
    }

    public static function normaliseHex(?string $hex, string $fallback): string
    {
        return is_string($hex) && preg_match('/^#[0-9A-Fa-f]{6}$/', $hex) ? strtoupper($hex) : $fallback;
    }

    /**
     * @return array{int, int, int}
     */
    private static function rgb(string $hex): array
    {
        $hex = ltrim(self::normaliseHex($hex, '#000000'), '#');

        return [hexdec(substr($hex, 0, 2)), hexdec(substr($hex, 2, 2)), hexdec(substr($hex, 4, 2))];
    }

    private static function luminance(string $hex): float
    {
        $channels = array_map(function (int $channel) {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        }, self::rgb($hex));

        return 0.2126 * $channels[0] + 0.7152 * $channels[1] + 0.0722 * $channels[2];
    }
}
