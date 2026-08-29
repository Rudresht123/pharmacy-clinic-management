/**
 * Join class names, dropping falsy entries.
 *
 * The theme is plain Bootstrap, so conditional classes are common and this
 * keeps JSX free of nested ternaries and stray spaces.
 */
export function cn(...values: Array<string | false | null | undefined>): string {
    return values.filter(Boolean).join(' ');
}
