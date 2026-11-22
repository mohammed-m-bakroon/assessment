/**
 * The server sends money as exact 9-decimal USD strings ("0.381887500").
 * We never parse them into floats; we only trim and format the string.
 */
export function formatUsd(amount: string | null | undefined): string {
    if (amount === null || amount === undefined) return '—';

    const negative = amount.startsWith('-');
    const [whole, fraction = ''] = amount.replace('-', '').split('.');
    // Keep at least 2 decimals and drop trailing zeros beyond that: 0.381887500 -> 0.3818875
    const trimmed = fraction.replace(/0+$/, '').padEnd(2, '0');

    return `${negative ? '-' : ''}$${Number(whole).toLocaleString('en-US')}.${trimmed}`;
}

export function formatUsdRange(min: string, max: string): string {
    return min === max ? formatUsd(min) : `${formatUsd(min)} – ${formatUsd(max)}`;
}
