import { useEffect, useState, type CSSProperties, type KeyboardEvent, type MouseEvent } from 'react';
import { useImagePreview } from '@/shared/hooks/useImagePreview';
import { cn } from '@/shared/utils/cn';

/** The drawn stand-in for "no photograph yet". */
const SILHOUETTE = '/assets/img/avatar-placeholder.svg';

/** Soft tints for initials, picked by the name so a person keeps their colour. */
const TONES = 6;

export interface AvatarProps {
    /** Whose picture: the initials, the alt text and the viewer's caption. */
    name: string | null | undefined;
    /** The photo or logo. None — or one that fails to load — falls back. */
    src?: string | null;
    /**
     * Whether `src` is a real picture. Only for a source that is never empty
     * (a URL that resolves to a "no image" asset); defaults to whether `src`
     * is set.
     */
    hasImage?: boolean;
    /** Pixels. Leave it out to size the avatar from `className` instead. */
    size?: number;
    /** Round for a person, rounded-square for an organisation's logo. */
    shape?: 'circle' | 'rounded';
    /** What stands in without a picture: initials, or a drawn silhouette. */
    fallback?: 'initials' | 'silhouette';
    /**
     * Click to see the picture full-screen. On by default — there is
     * nothing to open behind initials, so it only ever applies to a picture.
     * Turn it off where the avatar is itself a control (a photo upload).
     */
    preview?: boolean;
    /** A second line for the viewer's caption — "Female, 33 yrs". */
    caption?: string | null;
    className?: string;
}

function initialsOf(name: string): string {
    const parts = name
        .replace(/^(dr|mr|mrs|ms|miss)\.?\s+/i, '')
        .split(/\s+/)
        .filter(Boolean);

    const first = parts[0]?.[0] ?? '';
    const last = parts.length > 1 ? (parts[parts.length - 1][0] ?? '') : '';

    return (first + last).toUpperCase() || '?';
}

function toneOf(name: string): number {
    let hash = 0;

    for (const char of name) {
        hash = (hash * 31 + char.charCodeAt(0)) >>> 0;
    }

    return hash % TONES;
}

/**
 * THE avatar — a person's photograph or an organisation's logo, everywhere
 * in the software.
 *
 *     <Avatar name={patient.name} src={patient.photo_url} />
 *     <Avatar name={doctor.name} src={doctor.photo_url} fallback="silhouette" className="md-face" />
 *     <Avatar name={org.name} src={org.logo_url} shape="rounded" size={40} />
 *
 * With a picture, it shows the picture, and a click opens it full-screen
 * (the shared viewer, useImagePreview) with the name underneath. Without one
 * — or when the file has gone missing — it shows the person's initials on a
 * soft tint, or a drawn silhouette where a screen wants "no photo yet" to
 * read as exactly that.
 *
 * The click stops there: an avatar inside a clickable row opens the photo,
 * not the row. It is a focusable element with a button role rather than a
 * <button>, so it can sit inside a row that is itself a button.
 *
 * Size comes from `size`, or from `className` when a screen sizes it in CSS.
 */
export function Avatar({
    name,
    src,
    hasImage,
    size,
    shape = 'circle',
    fallback = 'initials',
    preview = true,
    caption,
    className,
}: AvatarProps) {
    const openPreview = useImagePreview();
    const [failed, setFailed] = useState(false);

    // A new picture gets a fresh chance to load.
    useEffect(() => setFailed(false), [src]);

    const label = (name ?? '').trim();
    const picture = Boolean(src) && (hasImage ?? true) && !failed;
    const clickable = picture && preview;

    const style = size
        ? ({ '--pav-size': `${size}px`, '--pav-font': `${Math.max(10, Math.round(size * 0.36))}px` } as CSSProperties)
        : undefined;

    const classes = cn(
        'pav',
        `pav--${shape}`,
        !picture && (fallback === 'silhouette' ? 'pav--silhouette' : `pav--initials pav--tone-${toneOf(label)}`),
        clickable && 'pav--clickable',
        className,
    );

    const open = (event: MouseEvent | KeyboardEvent) => {
        event.preventDefault();
        event.stopPropagation();

        openPreview({
            src: src as string,
            alt: label,
            caption: [label, caption].filter(Boolean).join(' · ') || undefined,
        });
    };

    // The wrapper carries the name for screen readers; the picture inside is decoration.
    const body = picture ? (
        <img className="pav-img" src={src as string} alt="" loading="lazy" onError={() => setFailed(true)} />
    ) : fallback === 'silhouette' ? (
        <img className="pav-img" src={SILHOUETTE} alt="" />
    ) : (
        <span className="pav-initials" aria-hidden="true">
            {initialsOf(label)}
        </span>
    );

    if (!clickable) {
        return (
            <span className={classes} style={style} role="img" aria-label={label || undefined}>
                {body}
            </span>
        );
    }

    return (
        <span
            className={classes}
            style={style}
            role="button"
            tabIndex={0}
            aria-label={label ? `View ${label}'s photo` : 'View photo'}
            title="Click to enlarge"
            onClick={open}
            onKeyDown={(event) => {
                if (event.key === 'Enter' || event.key === ' ') open(event);
            }}
        >
            {body}
            <span className="pav-zoom" aria-hidden="true">
                <i className="ti ti-zoom-in" />
            </span>
        </span>
    );
}

Avatar.silhouette = SILHOUETTE;
