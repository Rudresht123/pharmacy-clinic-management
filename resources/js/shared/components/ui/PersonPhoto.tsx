/**
 * A person's photograph, or the fact that there isn't one yet.
 *
 * Always an `<img>`, never initials on a coloured disc — photographs are
 * coming, and this is the same element with a different `src` on the day they
 * arrive, so nothing about the layout moves when they do.
 *
 * Separate from `Avatar`, which is the square, initials-based one the tables
 * use and which opens a full-screen preview. This is the round one that stands
 * beside a name on the OPD screens, and it takes no click.
 *
 * The stand-in is a drawn silhouette rather than a stock portrait: a
 * photograph of somebody else against a patient's name is worse than no
 * photograph at all, and it has to read immediately as "no picture yet".
 */
export function PersonPhoto({
    src,
    name,
    className,
}: {
    src?: string | null;
    /** For the alt text only — never rendered as a letter. */
    name?: string | null;
    className?: string;
}) {
    return (
        <img
            className={`pp${className ? ` ${className}` : ''}`}
            src={src || PersonPhoto.placeholder}
            alt={name ?? ''}
            loading="lazy"
            /*
             * A photograph that 404s falls back to the stand-in rather than
             * the browser's broken-image glyph: a file that has gone missing
             * should look like no photograph, not like a fault.
             */
            onError={(event) => {
                const img = event.currentTarget;

                if (!img.src.endsWith(PersonPhoto.placeholder)) {
                    img.src = PersonPhoto.placeholder;
                }
            }}
        />
    );
}

PersonPhoto.placeholder = '/assets/img/avatar-placeholder.svg';
