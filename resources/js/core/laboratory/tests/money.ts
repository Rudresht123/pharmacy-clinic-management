/** ₹1,250 or ₹1,250.50 — rupees as the lab counter reads them. */
export function money(value: number | string | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return '—';
    }

    const amount = Number(value);

    return `₹${amount.toLocaleString('en-IN', {
        minimumFractionDigits: Number.isInteger(amount) ? 0 : 2,
        maximumFractionDigits: 2,
    })}`;
}
