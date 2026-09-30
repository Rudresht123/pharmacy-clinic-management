import type { ReactNode } from 'react';

/**
 * A small picture of what a stock line IS — a strip of tablets, a bottle, a
 * tube — so a movements list can be scanned by shape before it is read.
 *
 * Drawn, not fetched: the catalogue carries no product photos, and a grey
 * placeholder on every row says nothing. The dosage form decides the shape;
 * something that is not a medicine at all (a consumable, a device) gets a
 * shape of its own rather than being passed off as a pill.
 */
interface MedicineThumbnailProps {
    name?: string | null;
    dosageForm?: string | null;
    itemKind?: string | null;
}

type Shape = 'pill' | 'capsule' | 'bottle' | 'syringe' | 'tube' | 'dropper' | 'inhaler' | 'sachet' | 'patch' | 'box' | 'device';

const FORM_SHAPES: Record<string, Shape> = {
    tablet: 'pill',
    capsule: 'capsule',
    syrup: 'bottle',
    suspension: 'bottle',
    injection: 'syringe',
    ointment: 'tube',
    cream: 'tube',
    gel: 'tube',
    lotion: 'bottle',
    drops: 'dropper',
    inhaler: 'inhaler',
    powder: 'sachet',
    patch: 'patch',
    suppository: 'capsule',
};

function shapeFor(dosageForm?: string | null, itemKind?: string | null): Shape {
    if (itemKind === 'device') return 'device';
    if (itemKind && itemKind !== 'medicine') return 'box';

    return FORM_SHAPES[dosageForm ?? ''] ?? 'pill';
}

const ACCENT = 'rgb(var(--brand))';
const TINT = 'rgba(var(--brand), 0.14)';

const SHAPES: Record<Shape, ReactNode> = {
    pill: (
        <>
            <circle cx="19" cy="19" r="10" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <line x1="12" y1="19" x2="26" y2="19" stroke={ACCENT} strokeWidth="1.6" strokeLinecap="round" />
        </>
    ),
    capsule: (
        <g transform="rotate(-40 19 19)">
            <rect x="7" y="14" width="24" height="10" rx="5" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <path d="M19 14v10h-7a5 5 0 0 1 0-10z" fill={ACCENT} />
        </g>
    ),
    bottle: (
        <>
            <rect x="15" y="7" width="8" height="4" rx="1" fill={ACCENT} />
            <path d="M14 11h10v3l2 3v12a2 2 0 0 1-2 2H14a2 2 0 0 1-2-2V17l2-3z" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <rect x="14" y="20" width="10" height="5" fill={ACCENT} opacity="0.35" />
        </>
    ),
    syringe: (
        <g transform="rotate(-45 19 19)" stroke={ACCENT} strokeWidth="1.6" strokeLinecap="round">
            <rect x="11" y="15" width="16" height="8" rx="1.5" fill={TINT} />
            <line x1="27" y1="19" x2="32" y2="19" />
            <line x1="11" y1="19" x2="6" y2="19" />
            <line x1="6" y1="16" x2="6" y2="22" />
        </g>
    ),
    tube: (
        <g transform="rotate(-35 19 19)">
            <path d="M9 15h16l3 4-3 4H9z" fill={TINT} stroke={ACCENT} strokeWidth="1.6" strokeLinejoin="round" />
            <rect x="28" y="17" width="4" height="4" rx="1" fill={ACCENT} />
        </g>
    ),
    dropper: (
        <>
            <path d="M17 8h4v6h-4z" fill={ACCENT} />
            <path d="M13 14h12v14a3 3 0 0 1-3 3h-6a3 3 0 0 1-3-3z" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <path d="M19 19c1.6 2 2.4 3.3 2.4 4.3a2.4 2.4 0 0 1-4.8 0c0-1 .8-2.3 2.4-4.3z" fill={ACCENT} />
        </>
    ),
    inhaler: (
        <>
            <rect x="15" y="7" width="8" height="15" rx="2" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <path d="M12 21h14v5a3 3 0 0 1-3 3h-8a3 3 0 0 1-3-3z" fill={ACCENT} />
        </>
    ),
    sachet: (
        <>
            <path d="M11 9h16v20l-2-2-2 2-2-2-2 2-2-2-2 2-2-2-2 2z" fill={TINT} stroke={ACCENT} strokeWidth="1.6" strokeLinejoin="round" />
            <line x1="15" y1="15" x2="23" y2="15" stroke={ACCENT} strokeWidth="1.6" strokeLinecap="round" />
        </>
    ),
    patch: (
        <>
            <rect x="9" y="9" width="20" height="20" rx="5" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <rect x="14" y="14" width="10" height="10" rx="2" fill={ACCENT} opacity="0.5" />
        </>
    ),
    box: (
        <>
            <path d="M19 8l10 5v12l-10 5-10-5V13z" fill={TINT} stroke={ACCENT} strokeWidth="1.6" strokeLinejoin="round" />
            <path d="M9 13l10 5 10-5M19 18v12" fill="none" stroke={ACCENT} strokeWidth="1.6" strokeLinejoin="round" />
        </>
    ),
    device: (
        <>
            <rect x="12" y="8" width="14" height="22" rx="3" fill={TINT} stroke={ACCENT} strokeWidth="1.6" />
            <rect x="15" y="12" width="8" height="6" rx="1" fill={ACCENT} />
            <circle cx="19" cy="24" r="2" fill={ACCENT} />
        </>
    ),
};

export function MedicineThumbnail({ name, dosageForm, itemKind }: MedicineThumbnailProps) {
    const shape = shapeFor(dosageForm, itemKind);

    return (
        <span className="mv-thumb" title={name ?? undefined}>
            <svg className="mv-thumb-svg" viewBox="0 0 38 38" aria-hidden="true" focusable="false">
                {SHAPES[shape]}
            </svg>
        </span>
    );
}
