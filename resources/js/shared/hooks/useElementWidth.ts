import { useEffect, useRef, useState } from 'react';

/**
 * The rendered width of an element, kept current as it resizes.
 *
 * Charts that place their own dots and tooltips need real pixels: an SVG
 * stretched with preserveAspectRatio="none" would squash every circle into
 * an ellipse and put labels a few pixels off their marks. Measuring costs
 * one observer and makes the geometry exact.
 *
 * Returns 0 until the first measurement, which is the caller's cue that
 * there is nothing to draw yet.
 */
export function useElementWidth<T extends HTMLElement>() {
    const ref = useRef<T>(null);
    const [width, setWidth] = useState(0);

    useEffect(() => {
        const node = ref.current;

        if (!node) {
            return;
        }

        const observer = new ResizeObserver(([entry]) => {
            setWidth(entry.contentRect.width);
        });

        observer.observe(node);

        return () => observer.disconnect();
    }, []);

    return { ref, width };
}
