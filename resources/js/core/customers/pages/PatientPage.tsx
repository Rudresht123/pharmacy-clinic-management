import { useMemo, useState } from 'react';
import { Link, useParams, useSearchParams } from 'react-router-dom';
import { useQuery } from '@tanstack/react-query';
import { LoadingBlock, ErrorState } from '@/shared/components/ui/Feedback';
import { PersonPhoto } from '@/shared/components/ui/PersonPhoto';
import { StatTiles, type StatTile } from '@/shared/components/ui/StatTiles';
import { Tabs } from '@/shared/components/ui/Tabs';
import { Button } from '@/shared/components/ui/Button';
import { RecordHistory } from '@/core/tenant-history/RecordHistory';
import { http } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { customersHooks, useCustomerFields } from '../api';
import type { ApiResponse } from '@/shared/types/api';
import {
    SECTIONS,
    isSection,
    longDate,
    readFile,
    type PatientRecord,
    type Section,
} from '../components/patientFile';
import {
    AllergiesPanel,
    BillingPanel,
    DocumentsPanel,
    LabsTable,
    NotesPanel,
    Overview,
    PrescriptionsTable,
    RecordPanel,
    ServicesPanel,
    VisitsTable,
    VitalsHistory,
    VitalsLatest,
} from '../components/PatientFileSections';

function useRecord(id: string | undefined) {
    return useQuery({
        queryKey: ['tenant', 'customers', id, 'visits'],
        queryFn: async (): Promise<PatientRecord> => {
            const { data } = await http.get<ApiResponse<PatientRecord>>(
                `/tenant/customers/${id}/visits`,
            );

            return data.data;
        },
        enabled: Boolean(id),
    });
}

/**
 * A patient's file: who they are, and everything that has happened to them.
 *
 * To read rather than to edit. "Full record" used to open the edit form,
 * which is the wrong thing twice over: a form when somebody wants an answer,
 * and every field one stray keystroke from changing the record of a person's
 * health. Editing is a button away, behind its own capability; this screen
 * needs only `customers.view`, which is what a doctor holds.
 *
 * Every section of the file exists now, including the ones with nothing
 * behind them yet (billing, documents). They say plainly why they are empty,
 * and fill in as their modules arrive, without this page being redrawn.
 *
 * The section lives in the URL (?section=visits), so a link to a patient's
 * prescriptions opens on their prescriptions, and Back returns to the
 * section somebody came from.
 */
export default function PatientPage() {
    const { id } = useParams();
    const { capabilities } = useTenantAuth();
    const [params, setParams] = useSearchParams();

    const asked = params.get('section');
    const section: Section = isSection(asked) ? asked : 'overview';

    const go = (next: Section) => {
        const updated = new URLSearchParams(params);

        if (next === 'overview') {
            updated.delete('section');
        } else {
            updated.set('section', next);
        }

        setParams(updated);
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const [copied, setCopied] = useState(false);

    const { data: patient, isLoading, isError, refetch } = customersHooks.useDetail(id);
    const { data: record } = useRecord(id);
    const { data: fields } = useCustomerFields();

    const visits = useMemo(() => record?.visits ?? [], [record]);
    const file = useMemo(() => readFile(visits), [visits]);

    if (isLoading) return <LoadingBlock label="Loading the record…" />;
    if (isError || !patient) return <ErrorState onRetry={() => refetch()} />;

    const canEdit = capabilities.includes('customers.edit');
    // The same capability the booking route checks (appointments.store).
    const canBook = capabilities.includes('appointments.book');

    const summary = record?.summary;
    const lastRx = file.prescriptions[0]?.date ?? null;
    const lastLab = file.labs[0]?.date ?? null;

    const tiles: StatTile[] = [
        {
            label: 'Total Visits',
            value: summary?.total_visits ?? 0,
            icon: 'ti ti-calendar-event',
            tone: 'indigo',
            hint: summary?.last_visit ? `Last visit: ${longDate(summary.last_visit.on)}` : 'No visits yet',
            onClick: () => go('visits'),
        },
        {
            label: 'Consultations',
            value: summary?.seen_count ?? 0,
            icon: 'ti ti-stethoscope',
            tone: 'emerald',
            hint: file.doctorCount === 1 ? '1 doctor' : `${file.doctorCount} different doctors`,
            onClick: () => go('visits'),
        },
        {
            label: 'Prescriptions',
            value: file.prescriptions.length,
            icon: 'ti ti-prescription',
            tone: 'rose',
            hint: lastRx ? `Last: ${longDate(lastRx)}` : 'None yet',
            onClick: () => go('prescriptions'),
        },
        {
            label: 'Lab Tests',
            value: file.labs.length,
            icon: 'ti ti-flask',
            tone: 'teal',
            hint: lastLab ? `Last: ${longDate(lastLab)}` : 'None ordered',
            onClick: () => go('labs'),
        },
        {
            label: 'Total Bills',
            // A dash, not ₹0: nothing has been billed because billing is not
            // on yet, which is different from a patient who owes nothing.
            value: '—',
            icon: 'ti ti-receipt',
            tone: 'violet',
            hint: 'Billing not set up yet',
            onClick: () => go('billing'),
        },
        {
            label: 'Files / Documents',
            value: 0,
            icon: 'ti ti-files',
            tone: 'sky',
            hint: 'None uploaded',
            onClick: () => go('documents'),
        },
    ];

    const history = <RecordHistory entity="Customer" id={patient.id} label={patient.name} />;

    const copyCode = async () => {
        if (!patient.code) return;

        try {
            await navigator.clipboard.writeText(patient.code);
            setCopied(true);
            window.setTimeout(() => setCopied(false), 1500);
        } catch {
            // Clipboard refused (an insecure origin, say). The code is on screen.
        }
    };

    const place = [patient.city, patient.state].filter(Boolean).join(', ');

    return (
        <div className="pf">
            <div className="pf-top">
                <Link className="pf-back" to="/customers">
                    <i className="ti ti-arrow-left" aria-hidden="true" />
                    Back to patients
                </Link>

                <Button variant="light" icon="ti ti-printer" onClick={() => window.print()}>
                    Print
                </Button>
            </div>

            {/* Who they are, before anything else. */}
            <header className="pf-head">
                <PersonPhoto src={null} name={patient.name} className="pf-face" />

                <div className="pf-id">
                    <h1 className="pf-name">
                        {patient.name}
                        <em className={cn('pf-status', patient.is_active === false && 'is-off')}>
                            {patient.is_active === false ? 'Inactive' : 'Active'}
                        </em>
                    </h1>

                    <div className="pf-code">
                        Patient ID: {patient.code ?? '—'}
                        {patient.code && (
                            <button
                                type="button"
                                onClick={copyCode}
                                title="Copy patient ID"
                                aria-label="Copy patient ID"
                            >
                                <i className={copied ? 'ti ti-check' : 'ti ti-copy'} aria-hidden="true" />
                            </button>
                        )}
                    </div>

                    <ul className="pf-facts">
                        {patient.age != null && (
                            <li>
                                <i className="ti ti-cake" aria-hidden="true" />
                                {patient.age} years
                                {patient.date_of_birth ? ` (${longDate(patient.date_of_birth)})` : ''}
                            </li>
                        )}

                        {patient.gender && (
                            <li>
                                <i className="ti ti-user" aria-hidden="true" />
                                {patient.gender.charAt(0).toUpperCase() + patient.gender.slice(1)}
                            </li>
                        )}

                        {patient.phone && (
                            <li>
                                <i className="ti ti-phone" aria-hidden="true" />
                                <a href={`tel:${patient.phone}`}>{patient.phone}</a>
                            </li>
                        )}

                        {place && (
                            <li>
                                <i className="ti ti-map-pin" aria-hidden="true" />
                                {place}
                            </li>
                        )}
                    </ul>
                </div>

                <div className="pf-actions">
                    {canBook && (
                        // Opens the OPD desk's booking dialog with this patient
                        // already chosen: OpdTodayPage reads ?patient=.
                        <Link className="btn btn-primary" to={`/opd?patient=${patient.id}`}>
                            <i className="ti ti-plus me-1" aria-hidden="true" />
                            New Visit
                        </Link>
                    )}

                    {canEdit && (
                        <Link className="btn btn-outline-primary" to={`/customers/${patient.id}/edit`}>
                            <i className="ti ti-pencil me-1" aria-hidden="true" />
                            Edit Patient
                        </Link>
                    )}

                    {history}
                </div>
            </header>

            <div className="pf-stats">
                <StatTiles tiles={tiles} loading={!record} />
            </div>

            <div className="pf-body">
                <aside className="pf-nav" aria-label="Patient record sections">
                    {SECTIONS.map((entry) => (
                        <button
                            key={entry.value}
                            type="button"
                            className={cn(section === entry.value && 'is-on')}
                            aria-current={section === entry.value ? 'page' : undefined}
                            onClick={() => go(entry.value)}
                        >
                            <i className={entry.icon} aria-hidden="true" />
                            {entry.label}
                        </button>
                    ))}
                </aside>

                <div className="pf-main">
                    <Tabs
                        tabs={SECTIONS.map((entry) => ({ value: entry.value, label: entry.short }))}
                        value={section}
                        onChange={go}
                        label="Patient record sections"
                    />

                    {section === 'overview' && (
                        <Overview
                            patient={patient}
                            fields={fields}
                            record={record}
                            file={file}
                            canEdit={canEdit}
                            go={go}
                        />
                    )}

                    {section === 'visits' && <VisitsTable visits={visits} go={go} />}

                    {section === 'prescriptions' && (
                        <PrescriptionsTable rows={file.prescriptions} go={go} />
                    )}

                    {section === 'labs' && <LabsTable rows={file.labs} go={go} />}

                    {section === 'billing' && <BillingPanel />}

                    {section === 'documents' && <DocumentsPanel />}

                    {section === 'notes' && <NotesPanel rows={file.notes} go={go} />}

                    {section === 'services' && <ServicesPanel file={file} />}

                    {section === 'vitals' && (
                        <div className="pf-stack">
                            <VitalsLatest record={record} />
                            <VitalsHistory rows={file.vitalsRows} />
                        </div>
                    )}

                    {section === 'allergies' && <AllergiesPanel patient={patient} file={file} />}

                    {section === 'settings' && (
                        <RecordPanel
                            patient={patient}
                            record={record}
                            canEdit={canEdit}
                            history={history}
                        />
                    )}
                </div>
            </div>
        </div>
    );
}
