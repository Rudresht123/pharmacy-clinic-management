/**
 * The accent colours a page or state may use.
 *
 * Each name maps to a `[data-tone]` rule in vendor/css/layout.css that sets
 * one RGB triplet; every tint (icon, background, glow, halo) is derived
 * from it, so colours stay consistent wherever a tone is applied.
 */
export type Tone =
    'indigo' | 'violet' | 'teal' | 'sky' | 'emerald' | 'amber' | 'rose' | 'danger' | 'muted';
