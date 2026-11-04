import type { ReactNode } from 'react';
import { Avatar } from '@/shared/components/ui/Avatar';
import { formatDateTime, formatRelative } from '@/shared/utils/format';
import { BannerWaves } from './ProfileArt';

export interface ProfileSummaryRow {
    icon: string;
    label: string;
    value: ReactNode;
}

interface ProfileSummaryCardProps {
    name: string;
    email: string;
    avatarUrl: string | null;
    /** The pill under the name: what they hold here. */
    roleLabel: string;
    isActive: boolean;
    lastLoginAt: string | null;
    /** Everything between the email and the last sign-in, one line each. */
    rows: ProfileSummaryRow[];
    /** Opens the photo picker from the camera badge. */
    onChangePhoto(): void;
    photoBusy: boolean;
}

/**
 * Who the signed-in person currently is: what is saved, not what is being typed
 * into the forms beside it.
 */
export function ProfileSummaryCard({
    name,
    email,
    avatarUrl,
    roleLabel,
    isActive,
    lastLoginAt,
    rows,
    onChangePhoto,
    photoBusy,
}: ProfileSummaryCardProps) {
    const lines: ProfileSummaryRow[] = [
        { icon: 'ti ti-mail', label: 'Email', value: email },
        ...rows,
        {
            icon: 'ti ti-clock',
            label: 'Last Sign-in',
            value: (
                <span className="ppf-id-signin">
                    {lastLoginAt ? (
                        <span title={formatDateTime(lastLoginAt)}>{formatRelative(lastLoginAt)}</span>
                    ) : (
                        'Not recorded yet'
                    )}

                    {/* Whoever is reading this page is, by definition, signed in right now. */}
                    <span className="ppf-pill ppf-pill--ok">Online</span>
                </span>
            ),
        },
    ];

    return (
        <section className="card ppf-id">
            <div className="ppf-id-banner">
                <BannerWaves />
            </div>

            <div className="ppf-id-photo">
                <Avatar name={name} src={avatarUrl} size={136} caption={roleLabel} />

                <button
                    type="button"
                    className="ppf-camera"
                    aria-label="Change photo"
                    title="Change photo"
                    disabled={photoBusy}
                    onClick={onChangePhoto}
                >
                    <i className="ti ti-camera" />
                </button>
            </div>

            <div className="ppf-id-who">
                <div className="ppf-id-name">
                    <h2>{name}</h2>

                    <span className={isActive ? 'ppf-pill ppf-pill--ok' : 'ppf-pill ppf-pill--off'}>
                        <span className="ppf-pill-dot" />
                        {isActive ? 'Active' : 'Inactive'}
                    </span>
                </div>

                <span className="ppf-id-role">
                    <i className="ti ti-briefcase" />
                    {roleLabel}
                </span>
            </div>

            <ul className="ppf-id-rows">
                {lines.map((row) => (
                    <li key={row.label}>
                        <span className="ppf-tile ppf-tile--sm" aria-hidden="true">
                            <i className={row.icon} />
                        </span>

                        <div className="ppf-id-row-text">
                            <span className="ppf-id-row-label">{row.label}</span>
                            <div className="ppf-id-row-value">{row.value}</div>
                        </div>
                    </li>
                ))}
            </ul>
        </section>
    );
}
