import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useState,
    type ReactNode,
} from 'react';
import { hexToRgb, lighten, readableOn, toTriplet } from '@/shared/utils/color';

const STORAGE_KEY = 'app.settings';

/** Quick-pick swatches. The accent itself can be any colour. */
export const ACCENT_PRESETS = [
    { label: 'Indigo', hex: '#2e37a4' },
    { label: 'Violet', hex: '#6d28d9' },
    { label: 'Blue', hex: '#026abb' },
    { label: 'Teal', hex: '#0d9488' },
    { label: 'Emerald', hex: '#05825a' },
    { label: 'Amber', hex: '#bf6906' },
    { label: 'Rose', hex: '#c81a40' },
    { label: 'Slate', hex: '#33415c' },
] as const;

export type Density = 'comfortable' | 'compact';

/**
 * Day, night, or whatever the operating system is set to.
 *
 * 'system' is the default: someone who has already told their OS they prefer
 * dark should not have to tell us as well.
 */
export type Theme = 'light' | 'dark' | 'system';

/** Background treatment shared by the sidebar and the topbar. */
export type Surface = 'light' | 'dark' | 'brand';

/** Background treatment for the page header / breadcrumb card. */
export type PageHead = 'light' | 'brand' | 'plain';

export interface AppSettings {
    theme: Theme;
    /** Any hex colour, not just a preset. */
    accent: string;
    density: Density;
    sidebar: Surface;
    topbar: Surface;
    pageHead: PageHead;
    /** Start the sidebar collapsed on desktop. */
    compactSidebar: boolean;
}

const DEFAULTS: AppSettings = {
    theme: 'system',
    accent: '#2e37a4',
    density: 'comfortable',
    sidebar: 'light',
    topbar: 'light',
    pageHead: 'light',
    compactSidebar: false,
};

const LEGACY_NAMES: Record<string, string> = {
    indigo: '#2e37a4',
    violet: '#6d28d9',
    blue: '#026abb',
    teal: '#0d9488',
    emerald: '#05825a',
    amber: '#bf6906',
    rose: '#c81a40',
    slate: '#33415c',
};

/** Accepts a hex string, an old preset name, or nothing. */
function normaliseAccent(value: unknown): string {
    if (typeof value !== 'string') {
        return DEFAULTS.accent;
    }

    if (LEGACY_NAMES[value]) {
        return LEGACY_NAMES[value];
    }

    // [0-9a-f], not [da-f]: the digits were lost with the backslash of an
    // intended \d, so every preset containing one — which is all of them but
    // Indigo — failed validation on read and snapped back to the default.
    return /^#[0-9a-f]{6}$/i.test(value) ? value : DEFAULTS.accent;
}

const SURFACES: Surface[] = ['light', 'dark', 'brand'];
const PAGE_HEADS: PageHead[] = ['light', 'brand', 'plain'];
const THEMES: Theme[] = ['light', 'dark', 'system'];

const DARK_QUERY = '(prefers-color-scheme: dark)';

/** What 'system' currently means on this device. */
function systemPrefersDark(): boolean {
    return typeof window !== 'undefined' && window.matchMedia?.(DARK_QUERY).matches === true;
}

interface SettingsContextValue {
    settings: AppSettings;
    /** What 'system' resolves to right now — for icons and labels. */
    resolvedTheme: 'light' | 'dark';
    update<K extends keyof AppSettings>(key: K, value: AppSettings[K]): void;
    /** Flips between light and dark, leaving 'system' behind. */
    toggleTheme(): void;
    reset(): void;
    isDefault: boolean;
}

const SettingsContext = createContext<SettingsContextValue | null>(null);

function read(): AppSettings {
    try {
        const raw = localStorage.getItem(STORAGE_KEY);

        if (!raw) {
            return DEFAULTS;
        }

        const parsed = JSON.parse(raw) as Partial<AppSettings>;

        // Merge over the defaults so a stored file from an older version,
        // or one with an accent that no longer exists, cannot break the app.
        return {
            theme: THEMES.includes(parsed.theme as Theme)
                ? (parsed.theme as Theme)
                : DEFAULTS.theme,
            accent: normaliseAccent(parsed.accent),
            density: parsed.density === 'compact' ? 'compact' : DEFAULTS.density,
            sidebar: SURFACES.includes(parsed.sidebar as Surface)
                ? (parsed.sidebar as Surface)
                : DEFAULTS.sidebar,
            topbar: SURFACES.includes(parsed.topbar as Surface)
                ? (parsed.topbar as Surface)
                : DEFAULTS.topbar,
            pageHead: PAGE_HEADS.includes(parsed.pageHead as PageHead)
                ? (parsed.pageHead as PageHead)
                : DEFAULTS.pageHead,
            compactSidebar: parsed.compactSidebar === true,
        };
    } catch {
        return DEFAULTS;
    }
}

/**
 * User-level appearance preferences, applied by writing CSS custom
 * properties and data attributes onto <html>.
 *
 * Stored in localStorage — these are per browser, not per account, and
 * never reach the server.
 */
export function SettingsProvider({ children }: { children: ReactNode }) {
    const [settings, setSettings] = useState<AppSettings>(read);

    // Tracked separately because it changes without the settings changing —
    // the user can flip their OS to dark while the app is open.
    const [systemDark, setSystemDark] = useState(systemPrefersDark);

    useEffect(() => {
        const media = window.matchMedia?.(DARK_QUERY);

        if (!media) {
            return;
        }

        const onChange = (event: MediaQueryListEvent) => setSystemDark(event.matches);

        media.addEventListener('change', onChange);

        return () => media.removeEventListener('change', onChange);
    }, []);

    const resolvedTheme: 'light' | 'dark' =
        settings.theme === 'system' ? (systemDark ? 'dark' : 'light') : settings.theme;

    // Push the settings onto the document so CSS can react to them.
    useEffect(() => {
        const root = document.documentElement;
        const rgb = hexToRgb(settings.accent);

        root.style.setProperty('--brand', toTriplet(rgb));
        root.style.setProperty('--brand-2', toTriplet(lighten(rgb, 0.2)));
        // Text placed on the accent — white on a dark accent, ink on a pale one.
        root.style.setProperty('--brand-fg', readableOn(rgb));

        // Always the resolved value, never 'system' — the stylesheet should
        // not have to know that setting exists.
        root.dataset.theme = resolvedTheme;

        root.dataset.density = settings.density;
        root.dataset.sidebar = settings.sidebar;
        root.dataset.topbar = settings.topbar;
        root.dataset.pagehead = settings.pageHead;

        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify(settings));
        } catch {
            // Preferences just will not persist.
        }
    }, [settings, resolvedTheme]);

    const update = useCallback<SettingsContextValue['update']>((key, value) => {
        setSettings((current) => ({ ...current, [key]: value }));
    }, []);

    const reset = useCallback(() => setSettings(DEFAULTS), []);

    // Whichever way it resolves now, the toggle moves to the opposite and
    // pins it — so one click always visibly changes something.
    const toggleTheme = useCallback(() => {
        setSettings((current) => ({
            ...current,
            theme:
                (current.theme === 'system'
                    ? systemPrefersDark()
                        ? 'dark'
                        : 'light'
                    : current.theme) === 'dark'
                    ? 'light'
                    : 'dark',
        }));
    }, []);

    const value = useMemo<SettingsContextValue>(
        () => ({
            settings,
            resolvedTheme,
            update,
            toggleTheme,
            reset,
            isDefault: (Object.keys(DEFAULTS) as (keyof AppSettings)[]).every(
                (key) => settings[key] === DEFAULTS[key],
            ),
        }),
        [settings, resolvedTheme, update, toggleTheme, reset],
    );

    return <SettingsContext.Provider value={value}>{children}</SettingsContext.Provider>;
}

export function useAppSettings(): SettingsContextValue {
    const context = useContext(SettingsContext);

    if (!context) {
        throw new Error('useAppSettings must be used within a SettingsProvider.');
    }

    return context;
}
