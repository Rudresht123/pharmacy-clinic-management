/**
 * An email's message as somebody at a clinic would write it.
 *
 * The seeded emails are stored as `<p>` and `<br>`, and nobody should have to type
 * tags to change a sentence. So those two read as blank lines and line breaks while
 * editing, and go back on saving. A template using anything richer stays exactly as
 * it is written, because turning it into plain text would lose what it says.
 */
const ONLY_PARAGRAPHS = /^(?:[^<]|<\/?p>|<br\s*\/?>)*$/i;

export function isPlainEnoughToEdit(html: string): boolean {
    return ONLY_PARAGRAPHS.test(html);
}

export function htmlToText(html: string): string {
    return html
        .replace(/<br\s*\/?>/gi, '\n')
        .replace(/<\/p>\s*<p>/gi, '\n\n')
        .replace(/<\/?p>/gi, '')
        .trim();
}

export function textToHtml(text: string): string {
    return text
        .trim()
        .split(/\n\s*\n/)
        .map((paragraph) => `<p>${paragraph.trim().replace(/\n/g, '<br>')}</p>`)
        .join('');
}
