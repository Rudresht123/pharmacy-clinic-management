/**
 * Paise to a readable amount, and a typed rupee amount to paise — exactly.
 *
 * The API speaks integer paise everywhere; nothing here multiplies a float
 * into a price that is then sent anywhere. Reading is `Intl`; writing parses
 * the typed string digit by digit.
 */
const inr = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
});

export function formatPaise(paise: number | null | undefined, currency = 'INR'): string {
    if (paise === null || paise === undefined) {
        return '—';
    }

    if (currency !== 'INR') {
        return `${currency} ${(paise / 100).toFixed(2)}`;
    }

    return inr.format(paise / 100);
}

/** "1499", "1,499.50" → 149950. Null when it is not an amount. */
export function rupeesToPaise(value: string | number): number | null {
    const text = String(value).replace(/[,\s₹]/g, '').trim();

    if (!/^\d+(\.\d{1,2})?$/.test(text)) {
        return null;
    }

    const [whole, fraction = ''] = text.split('.');

    return Number(whole) * 100 + Number(fraction.padEnd(2, '0'));
}

/** 149950 → "1499.50", for an input field. */
export function paiseToRupees(paise: number | null | undefined): string {
    if (paise === null || paise === undefined) {
        return '';
    }

    const whole = Math.trunc(paise / 100);
    const fraction = String(Math.abs(paise % 100)).padStart(2, '0');

    return fraction === '00' ? String(whole) : `${whole}.${fraction}`;
}

/** A date, in the organization's timezone: 31 October 2026. */
export function formatDay(iso: string | null | undefined, timeZone?: string): string {
    if (!iso) {
        return '—';
    }

    return new Intl.DateTimeFormat('en-IN', {
        day: 'numeric',
        month: 'long',
        year: 'numeric',
        timeZone,
    }).format(new Date(iso));
}

export function plural(count: number, one: string, many = `${one}s`): string {
    return `${count} ${count === 1 ? one : many}`;
}
