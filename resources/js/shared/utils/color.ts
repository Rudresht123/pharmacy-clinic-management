/**
 * Colour maths for the accent the user picks.
 *
 * Everything returns an "r, g, b" triplet because the CSS reads colours as
 * `rgb(var(--brand))` / `rgba(var(--brand), .1)`, which needs the channels
 * separated rather than a finished colour.
 */

export interface Rgb {
    r: number;
    g: number;
    b: number;
}

const FALLBACK: Rgb = { r: 46, g: 55, b: 164 };

export function hexToRgb(hex: string): Rgb {
    const match = /^#?([\da-f]{3}|[\da-f]{6})$/i.exec(hex.trim());

    if (!match) {
        return FALLBACK;
    }

    let value = match[1];

    if (value.length === 3) {
        value = value
            .split('')
            .map((c) => c + c)
            .join('');
    }

    const int = parseInt(value, 16);

    return {
        r: (int >> 16) & 255,
        g: (int >> 8) & 255,
        b: int & 255,
    };
}

export function rgbToHex({ r, g, b }: Rgb): string {
    const part = (n: number) => Math.round(clamp(n)).toString(16).padStart(2, '0');

    return `#${part(r)}${part(g)}${part(b)}`;
}

export function toTriplet({ r, g, b }: Rgb): string {
    return `${Math.round(clamp(r))}, ${Math.round(clamp(g))}, ${Math.round(clamp(b))}`;
}

/** Mixes the colour toward white; used for the hover / gradient shade. */
export function lighten(rgb: Rgb, amount: number): Rgb {
    return {
        r: rgb.r + (255 - rgb.r) * amount,
        g: rgb.g + (255 - rgb.g) * amount,
        b: rgb.b + (255 - rgb.b) * amount,
    };
}

/**
 * Relative luminance, per WCAG. Used to decide whether text sitting on the
 * accent should be white or near-black — a pale accent with white text is
 * unreadable, and the user is free to pick one.
 */
export function luminance({ r, g, b }: Rgb): number {
    const channel = (value: number) => {
        const c = clamp(value) / 255;

        return c <= 0.03928 ? c / 12.92 : ((c + 0.055) / 1.055) ** 2.4;
    };

    return 0.2126 * channel(r) + 0.7152 * channel(g) + 0.0722 * channel(b);
}

/** The triplet for text placed on top of the given colour. */
export function readableOn(rgb: Rgb): string {
    return luminance(rgb) > 0.55 ? '17, 22, 51' : '255, 255, 255';
}

function clamp(value: number): number {
    return Math.min(255, Math.max(0, value));
}
