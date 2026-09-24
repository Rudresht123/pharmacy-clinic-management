export type MessagingChannel = 'whatsapp' | 'email';

/**
 * One credential a provider needs.
 *
 * A secret never carries a `value` — the server sends `is_set` instead, so the
 * form can say "a token is saved" without the token itself ever reaching the
 * browser. `from_env` marks one that is coming from the deployment's config
 * rather than this screen, which is why it can read as set while its box is
 * empty.
 */
export interface ProviderField {
    name: string;
    label: string;
    required: boolean;
    secret: boolean;
    placeholder: string | null;
    hint: string | null;
    /** Present means a fixed set of choices — render a dropdown, not a box. */
    options: { value: string; label: string }[] | null;
    value: string | null;
    is_set: boolean;
    from_env: boolean;
}

export interface ProviderOption {
    key: string;
    label: string;
    fields: ProviderField[];
}

export interface ProviderSettings {
    channel: MessagingChannel;
    current: string;
    options: ProviderOption[];
    verified_at: string | null;
    verification_error: string | null;
}
