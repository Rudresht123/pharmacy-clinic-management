import {
    createContext,
    useCallback,
    useContext,
    useEffect,
    useMemo,
    useRef,
    useState,
    type ReactNode,
} from 'react';
import { createPortal } from 'react-dom';
import { cn } from '@/shared/utils/cn';

export interface ImagePreview {
    src: string;
    /** Describes the picture for screen readers. */
    alt?: string;
    /** Shown under the image — usually the record's name. */
    caption?: string;
}

type PreviewFn = (image: ImagePreview) => void;

const ImagePreviewContext = createContext<PreviewFn | null>(null);

/** Matches the CSS transition on .image-viewer. */
const EXIT_MS = 200;

/**
 * Full-screen image viewer, opened from anywhere.
 *
 *     const preview = useImagePreview();
 *     preview({ src: url, caption: 'Acme Pharmacy' });
 *
 * One viewer lives at the root — the same arrangement as ConfirmProvider —
 * so a table with fifty rows does not mount fifty overlays. Pages that only
 * want a clickable picture should use <PreviewableImage> instead of calling
 * this directly.
 *
 * Replaces the theme's GLightbox, which went out with the rest of jQuery.
 */
export function ImagePreviewProvider({ children }: { children: ReactNode }) {
    const [image, setImage] = useState<ImagePreview | null>(null);

    // Kept in the DOM through the fade-out, like Modal does.
    const [mounted, setMounted] = useState(false);
    const [visible, setVisible] = useState(false);

    const [loaded, setLoaded] = useState(false);
    const [zoomed, setZoomed] = useState(false);

    const closeButton = useRef<HTMLButtonElement>(null);
    const restoreFocusTo = useRef<Element | null>(null);

    const preview = useCallback<PreviewFn>((next) => {
        // Remember what the user clicked, so focus can go back there on close.
        restoreFocusTo.current = document.activeElement;

        setImage(next);
        setLoaded(false);
        setZoomed(false);
    }, []);

    const close = useCallback(() => setImage(null), []);

    useEffect(() => {
        if (image) {
            setMounted(true);

            const frame = requestAnimationFrame(() => setVisible(true));

            return () => cancelAnimationFrame(frame);
        }

        setVisible(false);

        const timer = window.setTimeout(() => setMounted(false), EXIT_MS);

        return () => window.clearTimeout(timer);
    }, [image]);

    // Escape to dismiss, and hold the page still behind the overlay.
    useEffect(() => {
        if (!mounted) {
            return;
        }

        function onKeyDown(event: KeyboardEvent) {
            if (event.key === 'Escape') {
                close();
            }
        }

        const previousOverflow = document.body.style.overflow;

        document.addEventListener('keydown', onKeyDown);
        document.body.style.overflow = 'hidden';

        return () => {
            document.removeEventListener('keydown', onKeyDown);
            document.body.style.overflow = previousOverflow;
        };
    }, [mounted, close]);

    useEffect(() => {
        if (visible) {
            closeButton.current?.focus();

            return;
        }

        // Send focus back to the thumbnail rather than dropping it on <body>,
        // which would restart keyboard navigation from the top of the page.
        if (restoreFocusTo.current instanceof HTMLElement) {
            restoreFocusTo.current.focus();
            restoreFocusTo.current = null;
        }
    }, [visible]);

    const value = useMemo(() => preview, [preview]);

    return (
        <ImagePreviewContext.Provider value={value}>
            {children}

            {mounted &&
                image &&
                createPortal(
                    <div
                        className={cn('image-viewer', visible && 'is-visible')}
                        role="dialog"
                        aria-modal="true"
                        aria-label={image.caption ?? image.alt ?? 'Image preview'}
                        onMouseDown={(event) => {
                            // Only a press that starts on the backdrop closes;
                            // dragging off the image must not dismiss.
                            if (event.target === event.currentTarget) {
                                close();
                            }
                        }}
                    >
                        <div className="image-viewer-bar">
                            <button
                                type="button"
                                className="image-viewer-btn"
                                onClick={() => setZoomed((on) => !on)}
                                aria-pressed={zoomed}
                                title={zoomed ? 'Fit to screen' : 'Actual size'}
                                aria-label={zoomed ? 'Fit to screen' : 'Actual size'}
                            >
                                <i className={zoomed ? 'ti ti-zoom-out' : 'ti ti-zoom-in'} />
                            </button>

                            <a
                                className="image-viewer-btn"
                                href={image.src}
                                target="_blank"
                                rel="noreferrer"
                                title="Open in a new tab"
                                aria-label="Open in a new tab"
                            >
                                <i className="ti ti-external-link" />
                            </a>

                            <button
                                ref={closeButton}
                                type="button"
                                className="image-viewer-btn"
                                onClick={close}
                                title="Close"
                                aria-label="Close image preview"
                            >
                                <i className="ti ti-x" />
                            </button>
                        </div>

                        <figure
                            className={cn('image-viewer-stage', zoomed && 'is-zoomed')}
                            onMouseDown={(event) => event.stopPropagation()}
                        >
                            {!loaded && (
                                <span className="image-viewer-spinner" aria-hidden="true" />
                            )}

                            <img
                                src={image.src}
                                alt={image.alt ?? ''}
                                className={cn('image-viewer-img', loaded && 'is-loaded')}
                                onLoad={() => setLoaded(true)}
                                // A broken URL should not leave a spinner
                                // turning forever.
                                onError={() => setLoaded(true)}
                                onClick={() => setZoomed((on) => !on)}
                            />

                            {image.caption && (
                                <figcaption className="image-viewer-caption">
                                    {image.caption}
                                </figcaption>
                            )}
                        </figure>
                    </div>,
                    document.body,
                )}
        </ImagePreviewContext.Provider>
    );
}

export function useImagePreview(): PreviewFn {
    const context = useContext(ImagePreviewContext);

    if (!context) {
        throw new Error('useImagePreview must be used within an ImagePreviewProvider.');
    }

    return context;
}
