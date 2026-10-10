/**
 * A key for one attempt at an action that must happen once — a sale, a
 * payment, a refund, a return.
 *
 * Kept for as long as the form is open, so a double-tap or a retry sends the
 * same key and the server answers with the first result instead of doing it
 * twice. `crypto.randomUUID` exists only on secure origins — a phone on the
 * clinic's LAN over plain http has no such thing — so there is a fallback
 * that is unique enough for one organization's requests.
 */
export function newIdempotencyKey(): string {
    if (typeof crypto !== 'undefined' && 'randomUUID' in crypto) {
        return crypto.randomUUID();
    }

    return [Date.now().toString(36), Math.random().toString(36).slice(2), Math.random().toString(36).slice(2)].join('-');
}
