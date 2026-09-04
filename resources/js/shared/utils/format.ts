/**
 * Display formatting. Kept in one place so a date never renders two
 * different ways in two different tables.
 */

export const DASH = '—';

export function formatDate(value: string | null | undefined): string {
    if (!value) {
        return DASH;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return DASH;
    }

    return date.toLocaleDateString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    });
}

export function formatDateTime(value: string | null | undefined): string {
    if (!value) {
        return DASH;
    }

    const date = new Date(value);

    if (Number.isNaN(date.getTime())) {
        return DASH;
    }

    return date.toLocaleString('en-GB', {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
    });
}

/** Renders empty values as an em dash rather than a blank cell. */
export function orDash(value: string | number | null | undefined): string {
    if (value === null || value === undefined || value === '') {
        return DASH;
    }

    return String(value);
}

export function initials(name: string): string {
    return name
        .split(' ')
        .filter(Boolean)
        .slice(0, 2)
        .map((part) => part[0]?.toUpperCase() ?? '')
        .join('');
}

/**
 * A file size a person can read: 964 KB, 1.4 MB.
 *
 * Uses 1024 rather than 1000 because that is what the upload limit is
 * measured in — telling someone their 2.09 MB file is over a 2 MB cap is only
 * confusing if the two are counted differently.
 */
export function formatBytes(bytes: number): string {
    if (!Number.isFinite(bytes) || bytes <= 0) {
        return '0 KB';
    }

    const units = ['B', 'KB', 'MB', 'GB'];
    const index = Math.min(Math.floor(Math.log(bytes) / Math.log(1024)), units.length - 1);
    const value = bytes / 1024 ** index;

    // Whole numbers for bytes and kilobytes, one decimal above that.
    return `${value.toFixed(index < 2 ? 0 : 1)} ${units[index]}`;
}

/**
 * How long ago, in the words somebody would use.
 *
 * "2 hours ago" rather than a timestamp: an activity feed is read for
 * recency, and a reader converting 14:32 into "about two hours" is doing
 * arithmetic the screen should have done. Falls back to a date past a week,
 * where "9 days ago" stops being more useful than the day itself.
 */
export function formatRelative(value: string | null | undefined): string {
    if (!value) {
        return '';
    }

    const then = new Date(value);

    if (Number.isNaN(then.getTime())) {
        return '';
    }

    const seconds = Math.floor((Date.now() - then.getTime()) / 1000);

    if (seconds < 60) {
        return 'just now';
    }

    const units: [number, string][] = [
        [60, 'minute'],
        [3600, 'hour'],
        [86400, 'day'],
    ];

    for (const [size, name] of units) {
        const next = size * (name === 'minute' ? 60 : name === 'hour' ? 24 : 7);

        if (seconds < next) {
            const count = Math.floor(seconds / size);

            return `${count} ${name}${count === 1 ? '' : 's'} ago`;
        }
    }

    return formatDate(value);
}
