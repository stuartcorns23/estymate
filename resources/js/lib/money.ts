const formatters = new Map<string, Intl.NumberFormat>();

/**
 * Format an amount in pence, like 123450 → "£1,234.50".
 */
export function formatMoney(pence: number, currency = 'GBP'): string {
    if (!formatters.has(currency)) {
        formatters.set(currency, new Intl.NumberFormat('en-GB', { style: 'currency', currency }));
    }

    return formatters.get(currency)!.format(pence / 100);
}

/**
 * Turn a pounds value typed into a form, like "12.5", into pence.
 */
export function toPence(pounds: string | number): number {
    const value = typeof pounds === 'number' ? pounds : parseFloat(pounds);

    return Number.isFinite(value) ? Math.round(value * 100) : 0;
}

export function formatDate(date: string | null): string {
    if (!date) {
        return '';
    }

    return new Date(`${date}T00:00:00`).toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
}

/**
 * Show a rate like "20.00" as "20%".
 */
export function formatRate(rate: string | number): string {
    return `${Number(rate)}%`;
}
