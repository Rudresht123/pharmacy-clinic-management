import {
    useEffect,
    useLayoutEffect,
    useRef,
    useState,
    type FocusEvent,
    type KeyboardEvent,
    type MouseEvent,
} from 'react';
import { useLocation } from 'react-router-dom';
import { useMediaQuery } from '@/shared/hooks/useMediaQuery';

/** Long enough to cross the gap between a rail icon and its card. */
const CLOSE_DELAY_MS = 140;

/**
 * The card that opens beside an icon on the icon rail.
 *
 * The rail has no room for labels or sub-menus, so this is where they live.
 * Only one is ever open. Hover opens it with a mouse and Tab or the right arrow
 * with a keyboard; a touch screen has no hover, so a tap on a group opens its
 * card and the card's title is the link to the group's own screen.
 */
export function useRailFlyout() {
    const { pathname } = useLocation();
    const canHover = useMediaQuery('(hover: hover)');

    const [target, setTarget] = useState<{ key: string; anchor: HTMLElement } | null>(null);

    const popRef = useRef<HTMLDivElement>(null);
    const timer = useRef<number | undefined>(undefined);
    const focusInside = useRef(false);
    const ignoreFocus = useRef(false);

    const cancelClose = () => window.clearTimeout(timer.current);

    const show = (key: string, anchor: HTMLElement) => {
        cancelClose();
        setTarget({ key, anchor });
    };

    const closeSoon = () => {
        cancelClose();
        timer.current = window.setTimeout(() => setTarget(null), CLOSE_DELAY_MS);
    };

    const close = () => {
        cancelClose();
        setTarget(null);
    };

    useEffect(() => close, []);

    useEffect(() => {
        close();
    }, [pathname]);

    // Beside its icon, but pushed back up if the icon is near the bottom — again
    // whenever the card grows, as it does when the branch list opens inside it.
    useLayoutEffect(() => {
        const pop = popRef.current;

        if (!target || !pop) {
            return;
        }

        const place = () => {
            const wanted = target.anchor.getBoundingClientRect().top - 6;
            const lowest = window.innerHeight - pop.offsetHeight - 8;

            pop.style.top = `${Math.max(8, Math.min(wanted, lowest))}px`;
        };

        place();

        if (focusInside.current) {
            focusInside.current = false;
            pop.querySelector<HTMLElement>('a, button')?.focus();
        }

        const observer = new ResizeObserver(place);
        observer.observe(pop);

        return () => observer.disconnect();
    }, [target]);

    useEffect(() => {
        if (!target) {
            return;
        }

        function onPointerDown(event: PointerEvent) {
            const on = event.target as Node;

            if (!popRef.current?.contains(on) && !target?.anchor.contains(on)) {
                setTarget(null);
            }
        }

        // Back to the icon, without that focus opening the card straight again.
        function onKeyDown(event: globalThis.KeyboardEvent) {
            if (event.key === 'Escape') {
                setTarget(null);
                ignoreFocus.current = true;
                target?.anchor.focus();
                ignoreFocus.current = false;
            }
        }

        const onResize = () => setTarget(null);

        document.addEventListener('pointerdown', onPointerDown);
        document.addEventListener('keydown', onKeyDown);
        window.addEventListener('resize', onResize);

        return () => {
            document.removeEventListener('pointerdown', onPointerDown);
            document.removeEventListener('keydown', onKeyDown);
            window.removeEventListener('resize', onResize);
        };
    }, [target]);

    /** What an icon on the rail needs. `tapOpens`: it has a card worth tapping for. */
    function anchorProps(key: string, tapOpens: boolean) {
        return {
            onMouseEnter: canHover
                ? (event: MouseEvent<HTMLElement>) => show(key, event.currentTarget)
                : undefined,
            onMouseLeave: canHover ? closeSoon : undefined,
            onFocus: canHover
                ? (event: FocusEvent<HTMLElement>) => {
                      // Keyboard focus only. A click already hovered, and coming
                      // back to the browser tab refocuses whatever was clicked.
                      if (!ignoreFocus.current && event.currentTarget.matches(':focus-visible')) {
                          show(key, event.currentTarget);
                      }
                  }
                : undefined,
            onBlur: canHover ? closeSoon : undefined,
            onKeyDown: (event: KeyboardEvent<HTMLElement>) => {
                if (event.key === 'ArrowRight') {
                    event.preventDefault();
                    focusInside.current = true;
                    show(key, event.currentTarget);
                }
            },
            onClick:
                tapOpens && !canHover
                    ? (event: MouseEvent<HTMLElement>) => {
                          event.preventDefault();

                          const anchor = event.currentTarget;

                          setTarget((was) => (was?.key === key ? null : { key, anchor }));
                      }
                    : undefined,
        };
    }

    return {
        openKey: target?.key ?? null,
        popRef,
        close,
        anchorProps,
        popProps: {
            onMouseEnter: cancelClose,
            onMouseLeave: canHover ? closeSoon : undefined,
            onFocus: cancelClose,
            onBlur: canHover ? closeSoon : undefined,
        },
    };
}
