<?php

namespace App\Enums;

/**
 * The layouts a business can choose for its estimate and invoice PDFs.
 */
enum DocumentLayout: string
{
    case Classic = 'classic';
    case Modern = 'modern';
    case Minimal = 'minimal';
    case Bold = 'bold';
    case Corporate = 'corporate';

    public function label(): string
    {
        return match ($this) {
            self::Classic => 'Classic',
            self::Modern => 'Modern',
            self::Minimal => 'Minimal',
            self::Bold => 'Bold',
            self::Corporate => 'Corporate',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Classic => 'Logo and details side by side, with a tidy item table.',
            self::Modern => 'A full-width colour band across the top.',
            self::Minimal => 'Lots of white space and fine lines.',
            self::Bold => 'A large title and the amount due up front.',
            self::Corporate => 'Boxed sections and striped rows.',
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $layout) => [
            'value' => $layout->value,
            'label' => $layout->label(),
            'description' => $layout->description(),
        ], self::cases());
    }
}
