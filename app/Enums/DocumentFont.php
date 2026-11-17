<?php

namespace App\Enums;

/**
 * Typefaces for estimate and invoice PDFs. Only fonts bundled with the PDF renderer are offered,
 * so the PDF always matches the preview and the £ sign always prints.
 */
enum DocumentFont: string
{
    case Sans = 'sans';
    case Serif = 'serif';
    case Helvetica = 'helvetica';

    public function label(): string
    {
        return match ($this) {
            self::Sans => 'Sans serif',
            self::Serif => 'Serif',
            self::Helvetica => 'Helvetica',
        };
    }

    /**
     * The CSS font stack, with close browser fallbacks for the live preview.
     */
    public function css(): string
    {
        return match ($this) {
            self::Sans => "'DejaVu Sans', Verdana, sans-serif",
            self::Serif => "'DejaVu Serif', Georgia, serif",
            self::Helvetica => 'Helvetica, Arial, sans-serif',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $font) => ['value' => $font->value, 'label' => $font->label()], self::cases());
    }
}
