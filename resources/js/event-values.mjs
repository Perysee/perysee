// Display of event values. The currency is only a label chosen for the site: nothing is converted.
export function formatMoney(value, currency) {
    if (value === null || value === undefined) return '—';
    const amount = Number(value);
    if (!Number.isFinite(amount)) return '—';
    const options = { maximumFractionDigits: 2 };
    try {
        if (currency) return new Intl.NumberFormat(undefined, { ...options, style: 'currency', currency }).format(amount);
    } catch (_) { /* An unknown code falls back to a plain number followed by the code. */ }
    return new Intl.NumberFormat(undefined, options).format(amount) + (currency ? ' ' + currency : '');
}
