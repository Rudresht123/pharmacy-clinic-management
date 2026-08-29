import { useEffect, useState } from 'react';

/**
 * Delays a rapidly changing value — used so typing in a table search box
 * does not fire a request per keystroke.
 */
export function useDebounce<T>(value: T, delayMs = 350): T {
    const [debounced, setDebounced] = useState(value);

    useEffect(() => {
        const timer = window.setTimeout(() => setDebounced(value), delayMs);

        return () => window.clearTimeout(timer);
    }, [value, delayMs]);

    return debounced;
}
