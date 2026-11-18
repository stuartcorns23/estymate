<?php

namespace App\Tax;

use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Http\Request;

/**
 * The date range a list or the dashboard is filtered to: this UK tax year, the last one, or everything.
 */
enum Period: string
{
    case CurrentTaxYear = 'current';
    case PreviousTaxYear = 'previous';
    case Lifetime = 'all';

    /**
     * Reads ?period= from the request, falling back to the page's default for a missing or unknown value.
     */
    public static function fromRequest(Request $request, self $default): self
    {
        return self::tryFrom($request->string('period')->value()) ?? $default;
    }

    public function taxYear(): ?TaxYear
    {
        return match ($this) {
            self::CurrentTaxYear => TaxYear::current(),
            self::PreviousTaxYear => TaxYear::lastCompleted(),
            self::Lifetime => null,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::CurrentTaxYear => 'This tax year ('.$this->taxYear()->label().')',
            self::PreviousTaxYear => 'Last tax year ('.$this->taxYear()->label().')',
            self::Lifetime => 'Lifetime',
        };
    }

    /**
     * Limits a query to rows whose date column falls inside the period.
     *
     * @template TQuery of Builder
     *
     * @param  TQuery  $query
     * @return TQuery
     */
    public function apply(Builder $query, string $column): Builder
    {
        $year = $this->taxYear();

        if ($year) {
            $query->whereDate($column, '>=', $year->start()->toDateString())
                ->whereDate($column, '<=', $year->end()->toDateString());
        }

        return $query;
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $period) => ['value' => $period->value, 'label' => $period->label()], self::cases());
    }
}
