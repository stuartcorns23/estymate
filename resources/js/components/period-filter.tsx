import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import { type Option } from '@/types';
import { router } from '@inertiajs/react';

interface PeriodFilterProps {
    value: string;
    periods: Option[];
    /** The page's own default, left out of the URL so plain links keep working. */
    defaultValue: string;
    /** The other filters on the page, kept when the period changes. */
    params?: Record<string, string | undefined>;
}

/**
 * Picks this tax year, last tax year or lifetime, and reloads the page with ?period=.
 */
export default function PeriodFilter({ value, periods, defaultValue, params = {} }: PeriodFilterProps) {
    const change = (period: string) => {
        router.get(
            window.location.pathname,
            { ...params, period: period === defaultValue ? undefined : period },
            { preserveState: true, preserveScroll: true, replace: true },
        );
    };

    return (
        <Select value={value} onValueChange={change}>
            <SelectTrigger className="w-56" aria-label="Period">
                <SelectValue />
            </SelectTrigger>
            <SelectContent>
                {periods.map((period) => (
                    <SelectItem key={period.value} value={period.value}>
                        {period.label}
                    </SelectItem>
                ))}
            </SelectContent>
        </Select>
    );
}

/**
 * The ?period= value to carry into links, or undefined for the page's default.
 */
export function periodParam(value: string, defaultValue: string): string | undefined {
    return value === defaultValue ? undefined : value;
}
