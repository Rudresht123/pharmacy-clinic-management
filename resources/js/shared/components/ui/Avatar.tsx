import { initials } from '@/shared/utils/format';
import { useImagePreview } from '@/shared/hooks/useImagePreview';
import { cn } from '@/shared/utils/cn';

interface AvatarProps {
    name: string;
    /** Only rendered when there is a real uploaded image. */
    src?: string | null;
    /** True when the record actually has an image on file. */
    hasImage?: boolean;
    size?: number;
    className?: string;
    /**
     * Click to open the full-screen viewer. Ignored when the record has no
     * image — there is nothing to enlarge behind a set of initials.
     */
    preview?: boolean;
}

/**
 * Square avatar for table rows.
 *
 * Falls back to the record's initials rather than a placeholder graphic —
 * getFileUrl() always returns a URL (the "no image available" asset), so a
 * plain <img> would render that grey box on every row without a logo.
 */
export function Avatar({
    name,
    src,
    hasImage = false,
    size = 36,
    className,
    preview = false,
}: AvatarProps) {
    const openPreview = useImagePreview();
    const style = { width: size, height: size };

    if (hasImage && src) {
        const image = (
            <img
                src={src}
                alt=""
                style={{ ...style, objectFit: 'cover' }}
                className={cn('avatar-box', className)}
            />
        );

        if (!preview) {
            return image;
        }

        return (
            <button
                type="button"
                className="avatar-trigger"
                onClick={() => openPreview({ src, alt: name, caption: name })}
                aria-label={`View ${name}'s image`}
                title="Click to enlarge"
            >
                {image}
            </button>
        );
    }

    return (
        <span
            className={cn('avatar-box avatar-box--initials', className)}
            style={{ ...style, fontSize: Math.round(size * 0.36) }}
            aria-hidden="true"
        >
            {initials(name)}
        </span>
    );
}
