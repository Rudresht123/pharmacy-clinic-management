import { useEffect, useRef, useState } from 'react';
import { Link } from 'react-router-dom';
import { Avatar } from '@/shared/components/ui/Avatar';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { notify } from '@/shared/utils/notify';
import { resolveErrorMessage } from '@/shared/api/http';
import { usePatientPhoto } from '../api';
import { TABS, capitalise, day, mobile } from '../labels';
import type { PatientRecordSummary, Tab } from '../types';
import { PatientPhone } from '@/core/customers/components/PatientRecordLink';

/**
 * Who the patient is, what may be done from here, and the tab strip.
 *
 * Every action is a door into the module that owns it — editing opens the
 * patient form, booking opens the OPD desk with this patient chosen — so
 * nothing about a patient is changed from this screen itself.
 */
export function ProfileHeader({
    record,
    tab,
    tabs,
    onTab,
    onBack,
}: {
    record: PatientRecordSummary;
    tab: Tab;
    /** The tabs this reader may open. */
    tabs: Tab[];
    onTab: (tab: Tab) => void;
    /** Shown on narrow screens, where the list and the profile take turns. */
    onBack: () => void;
}) {
    const { can, modules } = useTenantAuth();
    const patient = record.patient;

    const [copied, setCopied] = useState(false);
    const [menu, setMenu] = useState(false);
    const photo = usePatientPhoto(patient.id);

    function takePhoto(file: File | undefined) {
        if (!file) return;

        photo.upload.mutate(file, {
            onSuccess: () => notify.success('Photo updated'),
            onError: (failure) => notify.error(resolveErrorMessage(failure)),
        });
    }

    function removePhoto() {
        setMenu(false);
        photo.remove.mutate(undefined, {
            onSuccess: () => notify.success('Photo removed'),
            onError: (failure) => notify.error(resolveErrorMessage(failure)),
        });
    }
    const menuRef = useRef<HTMLDivElement>(null);

    useEffect(() => {
        if (!menu) return;

        const close = (event: MouseEvent) => {
            if (!menuRef.current?.contains(event.target as Node)) setMenu(false);
        };

        document.addEventListener('mousedown', close);

        return () => document.removeEventListener('mousedown', close);
    }, [menu]);

    const copy = async () => {
        if (!patient.code) return;

        try {
            await navigator.clipboard.writeText(patient.code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard refused (an insecure origin, say). The code is on screen.
        }
    };

    const place = [patient.address, patient.city, patient.district, patient.state].filter(Boolean).join(', ');
    const canEdit = can('customers.edit');
    // The same two questions the booking route asks.
    const canBook = modules.includes('appointments') && can('appointments.book');

    // Branches besides the one they registered at — the linked history.
    const elsewhere = record.branches.filter((branch) => branch.id !== patient.registered_location_id);

    // The file picker, behind the face (no photo yet) or the camera (changing one).
    const photoInput = (
        <input
            type="file"
            accept="image/jpeg,image/png,image/webp"
            className="visually-hidden"
            aria-label={patient.photo_url ? 'Change photo' : 'Add a photo'}
            disabled={photo.upload.isPending}
            onChange={(event) => {
                takePhoto(event.target.files?.[0]);
                event.target.value = '';
            }}
        />
    );

    return (
        <section className="ptr-card ptr-profile">
            <button type="button" className="ptr-back" onClick={onBack}>
                <i className="ti ti-arrow-left" aria-hidden="true" />
                All patients
            </button>

            <div className="ptr-profile-top">
                {/*
                    The photograph, ringed in the colour of their gender — as in
                    the list. With a photo, the face opens it full-screen and the
                    camera changes it; without one, the whole face adds one —
                    for whoever may edit the patient.
                */}
                {canEdit && !patient.photo_url ? (
                    <label
                        className={cn(
                            'ptr-face is-editable',
                            patient.gender && `is-${patient.gender}`,
                            photo.upload.isPending && 'is-busy',
                        )}
                        title="Add a photo"
                    >
                        <Avatar name={patient.name} src={null} fallback="silhouette" preview={false} />
                        <span className="ptr-face-edit" aria-hidden="true">
                            <i className={photo.upload.isPending ? 'ti ti-loader-2' : 'ti ti-camera'} />
                        </span>
                        {photoInput}
                    </label>
                ) : (
                    <span className={cn('ptr-face', patient.gender && `is-${patient.gender}`, photo.upload.isPending && 'is-busy')}>
                        <Avatar name={patient.name} src={patient.photo_url} fallback="silhouette" caption={patient.code} />
                        {canEdit && (
                            <label className="ptr-face-edit" title="Change photo">
                                <i className={photo.upload.isPending ? 'ti ti-loader-2' : 'ti ti-camera'} aria-hidden="true" />
                                {photoInput}
                            </label>
                        )}
                    </span>
                )}

                <div className="ptr-id">
                    <h2>
                        <span className="ptr-id-name">{patient.name}</span>
                        {patient.gender && (
                            <em className={`ptr-gender is-${patient.gender}`}>{capitalise(patient.gender)}</em>
                        )}
                        <span className={cn('ptr-state', patient.is_active === false ? 'is-off' : 'is-on')}>
                            <i aria-hidden="true" />
                            {patient.is_active === false ? 'Inactive' : 'Active'}
                        </span>
                    </h2>

                    <div className="ptr-id-line">
                        <span className="ptr-code">
                            <span className="ptr-code-label">UHID</span>
                            {patient.code ?? '—'}
                            {patient.code && (
                                <button type="button" onClick={copy} title="Copy UHID" aria-label="Copy UHID">
                                    <i className={copied ? 'ti ti-check' : 'ti ti-copy'} aria-hidden="true" />
                                </button>
                            )}
                        </span>

                        {patient.age != null && <span>{patient.age} years old</span>}

                        {elsewhere.length > 0 && (
                            <span className="ptr-linked" title={elsewhere.map((branch) => branch.name).join(', ')}>
                                <i className="ti ti-git-merge" aria-hidden="true" />
                                Also at {elsewhere.length === 1 ? elsewhere[0].name : `${elsewhere.length} branches`}
                            </span>
                        )}
                    </div>
                </div>

                <div className="ptr-profile-side">
                    <div className="ptr-profile-actions">
                        {canEdit && (
                            <Link className="btn ptr-btn-quiet" to={`/customers/${patient.id}/edit`}>
                                <i className="ti ti-pencil me-1" aria-hidden="true" />
                                Edit
                            </Link>
                        )}
                        {canBook && (
                            // The OPD desk's booking dialog, with this patient chosen: OpdTodayPage reads ?patient=.
                            <Link className="btn btn-primary" to={`/opd?patient=${patient.id}`}>
                                <i className="ti ti-calendar-plus me-1" aria-hidden="true" />
                                Book Appointment
                            </Link>
                        )}

                        <div className="ptr-more" ref={menuRef}>
                            <button
                                type="button"
                                className="ptr-icon-btn"
                                aria-label="More actions"
                                aria-expanded={menu}
                                onClick={() => setMenu((open) => !open)}
                            >
                                <i className="ti ti-dots" aria-hidden="true" />
                            </button>

                            {menu && (
                                <div className="ptr-menu" role="menu">
                                    {can('customers.record') && (
                                        <Link role="menuitem" to={`/customers/${patient.id}`}>
                                            <i className="ti ti-id" aria-hidden="true" />
                                            Open patient file
                                        </Link>
                                    )}
                                    <button
                                        type="button"
                                        role="menuitem"
                                        onClick={() => {
                                            setMenu(false);
                                            window.print();
                                        }}
                                    >
                                        <i className="ti ti-printer" aria-hidden="true" />
                                        Print record
                                    </button>
                                    {canEdit && patient.photo_url && (
                                        <button type="button" role="menuitem" onClick={removePhoto}>
                                            <i className="ti ti-photo-off" aria-hidden="true" />
                                            Remove photo
                                        </button>
                                    )}
                                </div>
                            )}
                        </div>
                    </div>
                </div>
            </div>

            {/* The facts, in one row of labelled cells rather than scattered lines. */}
            <dl className="ptr-strip">
                <div>
                    <i className="ti ti-cake" aria-hidden="true" />
                    <dt>Date of birth</dt>
                    <dd>{patient.date_of_birth ? day(patient.date_of_birth) : 'Not recorded'}</dd>
                </div>
                <div>
                    <i className="ti ti-phone" aria-hidden="true" />
                    <dt>Mobile</dt>
                    <dd>{patient.phone ? <PatientPhone phone={patient.phone}>{mobile(patient.phone)}</PatientPhone> : 'Not recorded'}</dd>
                </div>
                <div>
                    <i className="ti ti-mail" aria-hidden="true" />
                    <dt>Email</dt>
                    <dd title={patient.email || undefined}>
                        {patient.email ? <a href={`mailto:${patient.email}`}>{patient.email}</a> : 'Not recorded'}
                    </dd>
                </div>
                <div className="is-wide">
                    <i className="ti ti-map-pin" aria-hidden="true" />
                    <dt>Address</dt>
                    <dd title={place || undefined}>{place || 'Not recorded'}</dd>
                </div>
                <div>
                    <i className="ti ti-building-hospital" aria-hidden="true" />
                    <dt>Registered at</dt>
                    <dd>{patient.registered_location?.name ?? 'No branch recorded'}</dd>
                </div>
                <div>
                    <i className="ti ti-calendar-plus" aria-hidden="true" />
                    <dt>Registered on</dt>
                    <dd>{day(patient.created_at)}</dd>
                </div>
            </dl>

            <nav className="ptr-tabs" role="tablist" aria-label="Patient record sections">
                {TABS.filter((entry) => tabs.includes(entry.value)).map((entry) => {
                    const count = entry.section ? record.sections[entry.section] : null;

                    return (
                        <button
                            key={entry.value}
                            type="button"
                            role="tab"
                            aria-selected={tab === entry.value}
                            className={cn('ptr-tab', tab === entry.value && 'is-on')}
                            onClick={() => onTab(entry.value)}
                        >
                            <i className={entry.icon} aria-hidden="true" />
                            <span>
                                {entry.label}
                                {count !== null && <em>{count}</em>}
                            </span>
                        </button>
                    );
                })}
            </nav>
        </section>
    );
}
