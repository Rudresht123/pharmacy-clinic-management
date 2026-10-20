import type { ReactNode } from 'react';
import { Link } from 'react-router-dom';
import { useCustomerFields } from '@/core/customers/api';
import {
    ALLERGY_KEYS,
    BLOOD_GROUP_KEYS,
    DEDICATED_KEYS,
    EMERGENCY_KEYS,
    customText,
} from '@/core/customers/components/patientFile';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { usePatientTimeline } from '../api';
import { capitalise, day } from '../labels';
import type { PatientRecordSummary, SectionKey } from '../types';
import { ActivityTimeline } from './ActivityTimeline';
import { Empty, Loading, RecordCard } from './Bits';

/** Keys an organization commonly gives a "patient type" field of its own. */
const TYPE_KEYS = ['patient_type', 'patient_category', 'category'];

function Fact({ icon, label, children }: { icon: string; label: string; children: ReactNode }) {
    return (
        <div className="ptr-fact">
            <i className={icon} aria-hidden="true" />
            <div>
                <dt>{label}</dt>
                <dd>{children ?? <span className="ptr-quiet">Not recorded</span>}</dd>
            </div>
        </div>
    );
}

/**
 * Who the patient is, and what happened lately — side by side.
 *
 * Blood group, emergency contact and allergies are fields an organization
 * adds itself under Settings → Fields, read with the patient file's own
 * helpers. Allergies left blank read "Not recorded", never "No known
 * allergies": a blank field is not a clinical statement.
 */
export function OverviewTab({
    record,
    onOpen,
    onViewAll,
}: {
    record: PatientRecordSummary;
    onOpen: (section: SectionKey) => void;
    onViewAll: () => void;
}) {
    const { can } = useTenantAuth();
    const { data: fields } = useCustomerFields();
    const patient = record.patient;

    const address = [patient.address, patient.city, patient.district, patient.state, patient.pincode]
        .filter(Boolean)
        .join(', ');

    /*
     * The organization's own word for it when it records one; otherwise
     * read from the visits — a fact about this record, not a guess.
     */
    const visits = record.sections.visits;
    const type =
        customText(patient, TYPE_KEYS) ??
        (visits === null ? null : visits > 1 ? 'Returning' : 'New');

    // Whatever else this organization records, from its field settings.
    const extra = (fields ?? [])
        .filter((field) => field.is_custom && !DEDICATED_KEYS.has(field.key) && !TYPE_KEYS.includes(field.key))
        .map((field) => ({ key: field.key, label: field.label, value: (patient.custom_fields ?? {})[field.key] }))
        .filter((row) => row.value !== undefined && row.value !== null && row.value !== '');

    return (
        <div className="ptr-grid is-overview">
            <RecordCard
                title="Patient Overview"
                action={
                    can('customers.edit') ? (
                        <Link className="btn btn-sm ptr-btn-quiet" to={`/customers/${patient.id}/edit`}>
                            <i className="ti ti-pencil me-1" aria-hidden="true" />
                            Edit
                        </Link>
                    ) : undefined
                }
            >
                <dl className="ptr-facts-grid">
                    <Fact icon="ti ti-user" label="Full Name">
                        {patient.name}
                    </Fact>
                    <Fact icon="ti ti-id" label="Patient ID (UHID)">
                        {patient.code}
                    </Fact>
                    <Fact icon="ti ti-calendar" label="Date of Birth">
                        {patient.date_of_birth
                            ? `${day(patient.date_of_birth)}${patient.age != null ? ` (${patient.age} years)` : ''}`
                            : null}
                    </Fact>
                    <Fact icon="ti ti-gender-bigender" label="Gender">
                        {capitalise(patient.gender)}
                    </Fact>
                    <Fact icon="ti ti-phone" label="Mobile Number">
                        {patient.phone}
                    </Fact>
                    <Fact icon="ti ti-mail" label="Email">
                        {patient.email}
                    </Fact>
                    <Fact icon="ti ti-map-pin" label="Address">
                        {address || null}
                    </Fact>
                    <Fact icon="ti ti-phone-call" label="Emergency Contact">
                        {customText(patient, EMERGENCY_KEYS)}
                    </Fact>
                    <Fact icon="ti ti-droplet" label="Blood Group">
                        {customText(patient, BLOOD_GROUP_KEYS)}
                    </Fact>
                    <Fact icon="ti ti-alert-triangle" label="Allergies">
                        {customText(patient, ALLERGY_KEYS)}
                    </Fact>
                    <Fact icon="ti ti-building-hospital" label="Registered Branch">
                        {patient.registered_location?.name}
                    </Fact>
                    <Fact icon="ti ti-calendar-plus" label="Registration Date">
                        {patient.created_at ? day(patient.created_at) : null}
                    </Fact>
                    <Fact icon="ti ti-tag" label="Patient Type">
                        {type}
                    </Fact>
                    <Fact icon="ti ti-notes" label="Notes">
                        {patient.notes}
                    </Fact>

                    {extra.map((row) => (
                        <Fact key={row.key} icon="ti ti-forms" label={row.label}>
                            {Array.isArray(row.value) ? row.value.join(', ') : String(row.value)}
                        </Fact>
                    ))}
                </dl>
            </RecordCard>

            <RecordCard
                title="Recent Activity"
                action={
                    record.activity.length > 0 ? (
                        <button type="button" className="ptr-link" onClick={onViewAll}>
                            View All
                        </button>
                    ) : undefined
                }
            >
                {record.activity.length === 0 ? (
                    <Empty
                        icon="ti ti-timeline-event"
                        title="Nothing yet"
                        line="Visits, prescriptions, reports, purchases and bills appear here as they happen."
                    />
                ) : (
                    <ActivityTimeline events={record.activity} onOpen={onOpen} />
                )}
            </RecordCard>
        </div>
    );
}

/** The whole history, newest first, a heading per month — "View All". */
export function FullTimeline({
    patientId,
    onOpen,
    onBack,
}: {
    patientId: number;
    onOpen: (section: SectionKey) => void;
    onBack: () => void;
}) {
    const { data, isLoading } = usePatientTimeline(patientId, true);

    return (
        <RecordCard
            title="Medical Timeline"
            icon="ti ti-timeline-event"
            action={
                <button type="button" className="ptr-link" onClick={onBack}>
                    <i className="ti ti-arrow-left me-1" aria-hidden="true" />
                    Back to overview
                </button>
            }
        >
            {isLoading ? (
                <Loading label="Loading the timeline…" />
            ) : !data || data.length === 0 ? (
                <Empty icon="ti ti-timeline-event" title="Nothing yet" />
            ) : (
                <ActivityTimeline events={data} onOpen={onOpen} grouped />
            )}
        </RecordCard>
    );
}
