import { useEffect, useRef, useState } from 'react';

/**
 * Open/closed for a menu anchored to a button: closes on a press outside it
 * or on Escape. A press inside a date picker's calendar — which renders into
 * the page body, not inside the menu — does not count as outside.
 */
export function usePopover<T extends HTMLElement = HTMLDivElement>() {
    const [open, setOpen] = useState(false);
    const ref = useRef<T>(null);

    useEffect(() => {
        if (!open) return;

        const away = (event: MouseEvent) => {
            const target = event.target as HTMLElement;

            if (ref.current?.contains(target) || target.closest?.('.dp-pop, .ss-pop')) return;

            setOpen(false);
        };

        const escape = (event: KeyboardEvent) => {
            if (event.key === 'Escape') setOpen(false);
        };

        document.addEventListener('mousedown', away);
        document.addEventListener('keydown', escape);

        return () => {
            document.removeEventListener('mousedown', away);
            document.removeEventListener('keydown', escape);
        };
    }, [open]);

    return { open, setOpen, ref };
}
