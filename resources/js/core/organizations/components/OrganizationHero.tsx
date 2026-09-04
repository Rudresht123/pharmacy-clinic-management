import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useMutation } from '@tanstack/react-query';
import { Avatar } from '@/shared/components/ui/Avatar';
import { Button } from '@/shared/components/ui/Button';
import { notify } from '@/shared/utils/notify';
import { OrganizationStatusBadge } from './OrganizationStatusBadge';
import { retryOrganizationProvisioning } from '../api';
import type { Organization } from '../types';

/**
 * A handle somebody will need to paste somewhere.
 *
 * Codes, subdomains and addresses exist to be used elsewhere — in a support
 * ticket, a DNS record, an email — so each one carries the way to take it.
 * Reading a value off the screen and retyping it is where the typos come
 * from.
 */
function Handle({ icon, value, label }: { icon: string; value: string | null; label: string }) {
    const [copied, setCopied] = useState(false);

    if (!value) {
        return null;
    }

    async function copy() {
        try {
            await navigator.clipboard.writeText(value as string);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1400);
        } catch {
            // Denied, or an insecure origin. Nothing useful to say — the
            // value is on screen and can still be selected by hand.
            notify.error('Could not copy. Select the text instead.');
        }
    }

    return (
        <button type="button" className="oh-handle" onClick={copy} title={`Copy ${label}`}>
            <i className={icon} aria-hidden="true" />
            <span>{value}</span>
            <i className={copied ? 'ti ti-check oh-copied' : 'ti ti-copy'} aria-hidden="true" />
        </button>
    );
}

/**
 * Who this organization is, and what can be done to it.
 *
 * One header rather than the page title and an identity card repeating the
 * same name underneath each other. The name is said once, and the room that
 * saved goes to the handles and the actions.
 */
export function OrganizationHero({ organization }: { organization: Organization }) {
    const retry = useMutation({
        mutationFn: () => retryOrganizationProvisioning(organization.uuid),
        // Invalidation is the query client's job, for every mutation.
        onSuccess: () => notify.success('Provisioning retried'),
    });

    return (
        <header className="oh">
            {/*
             * The trail lives here rather than in a PageHeader above,
             * because a page title and an identity card repeating the same
             * name underneath each other is two headings for one thing.
             * Same crumb classes, so it reads identically to every other
             * screen.
             */}
            <nav className="page-crumbs oh-crumbs" aria-label="Breadcrumb">
                <Link to="/dashboard" className="crumb-home" title="Dashboard">
                    <i className="ti ti-home" />
                </Link>
                <span className="crumb-sep">›</span>
                <Link to="/organizations">Organizations</Link>
                <span className="crumb-sep">›</span>
                <span className="crumb-current">{organization.organization_name}</span>
            </nav>

            <span className="oh-avatar">
                <Avatar
                    name={organization.organization_name}
                    src={organization.profile_image_url}
                    hasImage={organization.profile_image_id !== null}
                    size={62}
                    preview
                />
            </span>

            <div className="oh-main">
                <div className="oh-title">
                    <h1>{organization.organization_name}</h1>
                    <OrganizationStatusBadge status={organization.status} />

                    {organization.organization_type?.name && (
                        <span className="oh-type">{organization.organization_type.name}</span>
                    )}
                </div>

                <div className="oh-handles">
                    <Handle
                        icon="ti ti-hash"
                        label="the organization code"
                        value={organization.organization_code}
                    />
                    <Handle
                        icon="ti ti-world"
                        label="the subdomain"
                        value={organization.subdomain}
                    />
                    <Handle icon="ti ti-mail" label="the email" value={organization.email} />
                </div>
            </div>

            <div className="oh-actions">
                {/* Only offered when there is something to retry. */}
                {organization.status === 'failed' && (
                    <Button
                        variant="light"
                        icon="ti ti-refresh"
                        loading={retry.isPending}
                        onClick={() => retry.mutate()}
                    >
                        Retry provisioning
                    </Button>
                )}

                <Link to={`/organizations/${organization.uuid}/edit`} className="btn btn-primary">
                    <i className="ti ti-edit me-1" />
                    Edit
                </Link>
            </div>
        </header>
    );
}
