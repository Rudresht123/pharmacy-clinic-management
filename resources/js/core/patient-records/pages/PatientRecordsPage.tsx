import { useCallback, useEffect, useMemo, useState } from 'react';
import { useNavigate, useSearchParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Button } from '@/shared/components/ui/Button';
import { ErrorState } from '@/shared/components/ui/Feedback';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { useEntityLabel } from '@/core/field-settings/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { usePatientList, usePatientRecord, useRecordStats } from '../api';
import { TABS, isTab } from '../labels';
import type { SectionKey, Tab } from '../types';
import { BillingTab, DocumentsTab, PharmacyTab } from '../components/AccountTabs';
import { Empty, Loading } from '../components/Bits';
import { AppointmentsTab, LabTab, PrescriptionsTab, VisitsTab } from '../components/ClinicalTabs';
import { KpiRow } from '../components/KpiRow';
import { FullTimeline, OverviewTab } from '../components/OverviewTab';
import { ExportMenu } from '../components/ExportMenu';
import { PatientList, type ListFilters } from '../components/PatientList';
import { ProfileHeader } from '../components/ProfileHeader';

/** Where the list and the profile stop sitting side by side. */
const SPLIT = '(min-width: 992px)';

function useWide(): boolean {
    const [wide, setWide] = useState(() => window.matchMedia(SPLIT).matches);

    useEffect(() => {
        const query = window.matchMedia(SPLIT);
        const update = () => setWide(query.matches);

        query.addEventListener('change', update);

        return () => query.removeEventListener('change', update);
    }, []);

    return wide;
}

/**
 * Patient Records — find a patient, then read everything about them.
 *
 * The register on the left, one patient's record on the right: who they are,
 * then a tab per module — appointments, OPD visits, prescriptions, lab
 * reports, pharmacy, billing, documents — each read from that module, at the
 * branches this reader works at. A tab whose module this reader may not open
 * is not drawn at all.
 *
 * Which patient and which tab ride the query string (?patient=12&tab=billing)
 * rather than the path, so a refresh and the back button land where the
 * reader was, a record can be linked to, and changing patient does not raise
 * the navigation loader over a screen that never left. On a narrow screen the
 * list and the record take turns; picking a patient is what switches them.
 */
export default function PatientRecordsPage() {
    const navigate = useNavigate();
    const wide = useWide();
    const { can } = useTenantAuth();
    const label = useEntityLabel('customer');

    const [params, setParams] = useSearchParams();
    const selectedParam = Number(params.get('patient'));
    const selected = Number.isInteger(selectedParam) && selectedParam > 0 ? selectedParam : null;
    const timeline = params.get('view') === 'timeline';

    const [filters, setFilters] = useState<ListFilters>({
        search: '',
        registered_location_id: '',
        gender: '',
        status: '',
        page: 1,
        per_page: 10,
    });

    const search = useDebounce(filters.search.trim());

    const listParams = useMemo(
        () => ({
            search: search || undefined,
            registered_location_id: filters.registered_location_id || undefined,
            gender: filters.gender || undefined,
            status: filters.status || undefined,
            page: filters.page,
            per_page: filters.per_page,
        }),
        [search, filters.registered_location_id, filters.gender, filters.status, filters.page, filters.per_page],
    );

    const list = usePatientList(listParams);

    // What the export takes: the list's own search and filters, every page of them.
    const exportParams = useMemo(() => {
        const { page: _page, per_page: _perPage, ...rest } = listParams;

        return rest;
    }, [listParams]);
    const stats = useRecordStats();
    const record = usePatientRecord(selected);

    /*
     * The tabs this reader may open: the overview, then each section the
     * server counted. A section it returned as null is one they may not
     * read, so its tab is not offered — rather than one that opens on a
     * refusal.
     */
    const tabs: Tab[] = useMemo(
        () =>
            TABS.filter((entry) => !entry.section || (record.data?.sections[entry.section] ?? null) !== null).map(
                (entry) => entry.value,
            ),
        [record.data],
    );

    const asked = params.get('tab');
    const tab: Tab = isTab(asked) && tabs.includes(asked) ? asked : 'overview';

    const go = useCallback(
        (next: { patient?: number | null; tab?: Tab; timeline?: boolean }, replace = false) => {
            const updated = new URLSearchParams(params);

            if (next.patient !== undefined) {
                if (next.patient === null) updated.delete('patient');
                else updated.set('patient', String(next.patient));

                // A different patient opens on their overview.
                updated.delete('tab');
                updated.delete('view');
            }

            if (next.tab !== undefined) {
                if (next.tab === 'overview') updated.delete('tab');
                else updated.set('tab', next.tab);
                updated.delete('view');
            }

            if (next.timeline !== undefined) {
                if (next.timeline) updated.set('view', 'timeline');
                else updated.delete('view');
            }

            setParams(updated, { replace });
        },
        [params, setParams],
    );

    /*
     * Side by side, an empty right half is wasted on arrival: open the first
     * patient on the list. Replaced rather than pushed, so Back does not
     * walk through a selection nobody made.
     */
    const firstId = list.data?.data[0]?.id;

    useEffect(() => {
        if (wide && selected === null && firstId !== undefined) {
            go({ patient: firstId }, true);
        }
    }, [wide, selected, firstId, go]);

    const onFilter = (next: Partial<ListFilters>) => setFilters((current) => ({ ...current, ...next }));

    const openSection = (section: SectionKey) => {
        go({ tab: section });
        window.scrollTo({ top: 0, behavior: 'smooth' });
    };

    const showList = wide || selected === null;
    const showRecord = wide || selected !== null;

    return (
        <div className="ptr-page">
            <PageHeader
                title="Patient Records"
                subtitle="Manage and view complete patient information across your organization."
                icon="ti ti-users"
                tone="sky"
                crumbs={[{ label: 'Patient Records' }]}
                actions={
                    <div className="d-flex gap-2">
                        {/* The list as it stands — search and filters — as a file, for whoever may export patients. */}
                        {can('exports.patients') && (
                            <ExportMenu
                                params={exportParams}
                                count={list.data?.meta.total}
                                noun={label.plural.toLowerCase()}
                            />
                        )}

                        {can('customers.create') && (
                            <Button icon="ti ti-plus" onClick={() => navigate('/customers/create')}>
                                Register {label.singular}
                            </Button>
                        )}
                    </div>
                }
            />

            <KpiRow cards={stats.data?.cards} loading={stats.isLoading} />

            <div className={cn('ptr-split', !wide && 'is-single')}>
                {showList && (
                    <PatientList
                        page={list.data}
                        loading={list.isLoading}
                        fetching={list.isFetching}
                        filters={filters}
                        onFilter={onFilter}
                        branches={stats.data?.branches ?? []}
                        selected={selected}
                        onSelect={(id) => go({ patient: id })}
                        label={label.plural}
                    />
                )}

                {showRecord && (
                    <div className="ptr-detail">
                        {selected === null ? (
                            <section className="ptr-card">
                                <Empty
                                    icon="ti ti-user-search"
                                    title={list.isLoading ? 'Loading…' : 'Select a patient'}
                                    line="Pick somebody from the list to read their complete record."
                                />
                            </section>
                        ) : record.isLoading ? (
                            <section className="ptr-card">
                                <Loading label="Loading the record…" />
                            </section>
                        ) : record.isError || !record.data ? (
                            <section className="ptr-card">
                                <ErrorState onRetry={() => record.refetch()} />
                            </section>
                        ) : (
                            <>
                                <ProfileHeader
                                    record={record.data}
                                    tab={timeline ? 'overview' : tab}
                                    tabs={tabs}
                                    onTab={(next) => go({ tab: next })}
                                    onBack={() => go({ patient: null })}
                                />

                                {timeline ? (
                                    <FullTimeline
                                        patientId={record.data.patient.id}
                                        onOpen={openSection}
                                        onBack={() => go({ timeline: false })}
                                    />
                                ) : (
                                    <>
                                        {tab === 'overview' && (
                                            <OverviewTab
                                                record={record.data}
                                                onOpen={openSection}
                                                onViewAll={() => go({ timeline: true })}
                                            />
                                        )}
                                        {tab === 'appointments' && <AppointmentsTab patientId={selected} />}
                                        {tab === 'visits' && <VisitsTab patientId={selected} />}
                                        {tab === 'prescriptions' && <PrescriptionsTab patientId={selected} />}
                                        {tab === 'laboratory' && <LabTab patientId={selected} />}
                                        {tab === 'pharmacy' && <PharmacyTab patientId={selected} />}
                                        {tab === 'billing' && <BillingTab record={record.data} />}
                                        {tab === 'documents' && <DocumentsTab patientId={selected} />}
                                    </>
                                )}
                            </>
                        )}
                    </div>
                )}
            </div>
        </div>
    );
}
