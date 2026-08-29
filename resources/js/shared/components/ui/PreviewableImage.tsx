import { useImagePreview } from '@/shared/hooks/useImagePreview';
import { cn } from '@/shared/utils/cn';

interface PreviewableImageProps {
    src: string;
    alt?: string;
    /** Shown under the enlarged image — usually the record's name. */
    caption?: string;
    width?: number;
    height?: number;
    className?: string;
    /** Turns the click-to-enlarge behaviour off without changing the markup. */
    previewable?: boolean;
}

/**
 * A thumbnail that opens the full-screen viewer when clicked.
 *
 *     <PreviewableImage src={url} caption={organization.name} width={110} />
 *
 * It renders a <button> rather than a bare <img> with an onClick, so the
 * keyboard reaches it and Enter and Space work the way people expect.
 */
export function PreviewableImage({
    src,
    alt = '',
    caption,
    width,
    height,
    className,
    previewable = true,
}: PreviewableImageProps) {
    const preview = useImagePreview();

    const image = (
        <img
            src={src}
            alt={alt}
            width={width}
            height={height}
            className={cn('previewable-image', className)}
            style={{ objectFit: 'cover' }}
        />
    );

    if (!previewable) {
        return image;
    }

    return (
        <button
            type="button"
            className="previewable-trigger"
            onClick={() => preview({ src, alt, caption })}
            aria-label={caption ? `View ${caption}` : 'View image'}
            title="Click to enlarge"
        >
            {image}

            <span className="previewable-hint" aria-hidden="true">
                <i className="ti ti-zoom-in" />
            </span>
        </button>
    );
}
