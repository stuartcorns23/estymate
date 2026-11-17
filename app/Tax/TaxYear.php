<?php

namespace App\Tax;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * A UK tax year, 6 April to 5 April. "2025" is the 2025-26 tax year.
 */
final readonly class TaxYear
{
    public function __construct(public int $startYear) {}

    public static function containing(CarbonInterface $date): self
    {
        $year = (int) $date->format('Y');

        return new self($date->format('m-d') >= '04-06' ? $year : $year - 1);
    }

    public static function current(): self
    {
        return self::containing(CarbonImmutable::today());
    }

    /**
     * The last tax year that has ended, which is the one people file a return for.
     */
    public static function lastCompleted(): self
    {
        return new self(self::current()->startYear - 1);
    }

    public function start(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear, 4, 6)->startOfDay();
    }

    public function end(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear + 1, 4, 5)->startOfDay();
    }

    /**
     * Like "2025-26".
     */
    public function label(): string
    {
        return $this->startYear.'-'.substr((string) ($this->startYear + 1), -2);
    }

    /**
     * Like "6 April 2025 to 5 April 2026".
     */
    public function period(): string
    {
        return $this->start()->format('j F Y').' to '.$this->end()->format('j F Y');
    }

    /**
     * The deadline for filing this year's return online.
     */
    public function filingDeadline(): CarbonImmutable
    {
        return CarbonImmutable::create($this->startYear + 2, 1, 31);
    }

    /**
     * The standard Making Tax Digital quarterly update periods.
     *
     * @return list<array{label: string, start: CarbonImmutable, end: CarbonImmutable}>
     */
    public function quarters(): array
    {
        $y = $this->startYear;
        $periods = [[[$y, 4, 6], [$y, 7, 5]], [[$y, 7, 6], [$y, 10, 5]], [[$y, 10, 6], [$y + 1, 1, 5]], [[$y + 1, 1, 6], [$y + 1, 4, 5]]];

        return array_map(function (array $period) {
            $start = CarbonImmutable::create(...$period[0]);
            $end = CarbonImmutable::create(...$period[1]);

            return ['label' => $start->format('j M').' – '.$end->format('j M Y'), 'start' => $start, 'end' => $end];
        }, $periods);
    }
}
