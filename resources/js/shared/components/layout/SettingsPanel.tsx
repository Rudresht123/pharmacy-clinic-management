import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import {
    ACCENT_PRESETS,
    useAppSettings,
    type Density,
    type PageHead,
    type Surface,
    type Theme,
} from '@/shared/hooks/useAppSettings';
import { cn } from '@/shared/utils/cn';

const THEMES: { value: Theme; label: string; icon: string }[] = [
    { value: 'light', label: 'Light', icon: 'ti ti-sun' },
    { value: 'dark', label: 'Dark', icon: 'ti ti-moon' },
    { value: 'system', label: 'System', icon: 'ti ti-device-laptop' },
];

const DENSITIES: { value: Density; label: string; hint: string }[] = [
    { value: 'comfortable', label: 'Comfortable', hint: 'Roomier table rows' },
    { value: 'compact', label: 'Compact', hint: 'More rows on screen' },
];

const SURFACES: { value: Surface; label: string }[] = [
    { value: 'light', label: 'Light' },
    { value: 'dark', label: 'Dark' },
    { value: 'brand', label: 'Brand' },
];

const PAGE_HEADS: { value: PageHead; label: string }[] = [
    { value: 'light', label: 'Card' },
    { value: 'brand', label: 'Tinted' },
    { value: 'plain', label: 'Plain' },
];

/** Small colour preview shown inside a surface choice. */
function SurfaceChip({ value }: { value: Surface | PageHead }) {
    const background =
        value === 'dark'
            ? '#171b34'
            : value === 'brand'
              ? 'rgb(var(--brand))'
              : value === 'plain'
                ? 'transparent'
                : '#ffffff';

    return (
        <span
            className="settings-chip"
            style={{
                background,
                borderStyle: value === 'plain' ? 'dashed' : 'solid',
            }}
            aria-hidden="true"
        />
    );
}

interface SettingsPanelProps {
    open: boolean;
    onClose: () => void;
}

/**
 * Appearance drawer: accent colour, table density and sidebar default.
 *
 * Everything here writes to CSS custom properties on <html>, so a change
 * repaints the whole app instantly without re-rendering feature pages.
 */
export function SettingsPanel({ open, onClose }: SettingsPanelProps) {
    const { settings, update, reset, isDefault } = useAppSettings();

    useEffect(() => {
        if (!open) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                onClose();
            }
        }

        document.addEventListener('keydown', onKeyDown);

        return () => document.removeEventListener('keydown', onKeyDown);
    }, [open, onClose]);

    if (!open) {
        return null;
    }

    return createPortal(
        <>
            <div className="settings-backdrop" onClick={onClose} />

            <aside
                className="settings-panel"
                role="dialog"
                aria-modal="true"
                aria-label="Appearance settings"
            >
                <header className="settings-head">
                    <div>
                        <h5>Appearance</h5>
                        <p>Saved in this browser only.</p>
                    </div>

                    <button
                        type="button"
                        className="settings-close"
                        onClick={onClose}
                        aria-label="Close settings"
                    >
                        <i className="ti ti-x" />
                    </button>
                </header>

                <div className="settings-body">
                    <section className="settings-group">
                        <h6>Theme</h6>
                        <p className="settings-hint">
                            Follow the device, or pin one. The header button toggles this too.
                        </p>

                        <div className="settings-choices settings-choices--3">
                            {THEMES.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    className={cn(
                                        'settings-choice settings-choice--tight',
                                        settings.theme === option.value && 'is-active',
                                    )}
                                    onClick={() => update('theme', option.value)}
                                >
                                    <i className={option.icon} />
                                    <b>{option.label}</b>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="settings-group">
                        <h6>Accent colour</h6>
                        <p className="settings-hint">
                            Buttons, links, active menu items and focus rings.
                        </p>

                        <div className="settings-swatches">
                            {ACCENT_PRESETS.map((preset) => {
                                const active =
                                    settings.accent.toLowerCase() === preset.hex.toLowerCase();

                                return (
                                    <button
                                        key={preset.hex}
                                        type="button"
                                        className={cn('settings-swatch', active && 'is-active')}
                                        style={{ background: preset.hex }}
                                        onClick={() => update('accent', preset.hex)}
                                        aria-label={preset.label}
                                        aria-pressed={active}
                                        title={preset.label}
                                    >
                                        {active && <i className="ti ti-check" />}
                                    </button>
                                );
                            })}
                        </div>

                        {/* Any colour, not just the presets above */}
                        <label className="settings-custom">
                            <input
                                type="color"
                                value={settings.accent}
                                onChange={(event) => update('accent', event.target.value)}
                                aria-label="Custom accent colour"
                            />

                            <span>
                                <b>Custom colour</b>
                                <small>{settings.accent.toUpperCase()}</small>
                            </span>

                            <i className="ti ti-color-picker" />
                        </label>
                    </section>

                    <section className="settings-group">
                        <h6>Sidebar background</h6>

                        <div className="settings-choices settings-choices--3">
                            {SURFACES.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    className={cn(
                                        'settings-choice settings-choice--tight',
                                        settings.sidebar === option.value && 'is-active',
                                    )}
                                    onClick={() => update('sidebar', option.value)}
                                >
                                    <SurfaceChip value={option.value} />
                                    <b>{option.label}</b>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="settings-group">
                        <h6>Header background</h6>

                        <div className="settings-choices settings-choices--3">
                            {SURFACES.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    className={cn(
                                        'settings-choice settings-choice--tight',
                                        settings.topbar === option.value && 'is-active',
                                    )}
                                    onClick={() => update('topbar', option.value)}
                                >
                                    <SurfaceChip value={option.value} />
                                    <b>{option.label}</b>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="settings-group">
                        <h6>Page header</h6>
                        <p className="settings-hint">The title and breadcrumb strip.</p>

                        <div className="settings-choices settings-choices--3">
                            {PAGE_HEADS.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    className={cn(
                                        'settings-choice settings-choice--tight',
                                        settings.pageHead === option.value && 'is-active',
                                    )}
                                    onClick={() => update('pageHead', option.value)}
                                >
                                    <SurfaceChip value={option.value} />
                                    <b>{option.label}</b>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="settings-group">
                        <h6>Table density</h6>

                        <div className="settings-choices">
                            {DENSITIES.map((option) => (
                                <button
                                    key={option.value}
                                    type="button"
                                    className={cn(
                                        'settings-choice',
                                        settings.density === option.value && 'is-active',
                                    )}
                                    onClick={() => update('density', option.value)}
                                >
                                    <b>{option.label}</b>
                                    <small>{option.hint}</small>
                                </button>
                            ))}
                        </div>
                    </section>

                    <section className="settings-group">
                        <h6>Sidebar</h6>

                        <label className="settings-toggle">
                            <span>
                                <b>Collapsed</b>
                                <small>Show icons only, on desktop</small>
                            </span>

                            <input
                                type="checkbox"
                                className="form-check-input"
                                role="switch"
                                checked={settings.compactSidebar}
                                onChange={(event) => update('compactSidebar', event.target.checked)}
                            />
                        </label>
                    </section>
                </div>

                <footer className="settings-foot">
                    <button
                        type="button"
                        className="btn btn-light w-100"
                        onClick={reset}
                        disabled={isDefault}
                    >
                        <i className="ti ti-rotate me-1" />
                        Reset to default
                    </button>
                </footer>
            </aside>
        </>,
        document.body,
    );
}
