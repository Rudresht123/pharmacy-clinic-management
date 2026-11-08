import { useState } from 'react';
import { Link, useParams } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Avatar } from '@/shared/components/ui/Avatar';
import { LabTabs } from '../../shared/LabTabs';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { useLabOrder } from '../api';
import { LabStatus, patientFacts, stamp } from '../status';
import type { LabOrder } from '../types';

type View = 'info' | 'results' | 'history';

/** One fact about the request: a small label over its value. */
function Fact({ label, children, wide }: { label: string; children: React.ReactNode; wide?: boolean }) {
    return (
        <div className={wide ? 'lab-fact is-wide' : 'lab-fact'}>
            <span>{label}</span>
            <b>{children || '—'}</b>
        </div>
    );
}

/** What the results add up to, in one line. */
function ResultSummary({ order }: { order: LabOrder }) {
    const items = (order.items ?? []).filter((item) => item.status !== 'cancelled');
    const withResult = items.filter((item) => item.result_value !== null);
    const outside = withResult.filter((item) => item.is_abnormal).length;

    if (withResult.length === 0) {
        return (
            <div className="lab-summary is-waiting">
                <i className="ti ti-hourglass" aria-hidden="true" />
                <span>
                    <b>Results not in yet</b>
                    <small>Nothing has been recorded for this request.</small>
                </span>
            </div>
        );
    }

    if (outside > 0) {
        return (
            <div className="lab-summary is-abnormal">
                <i className="ti ti-alert-triangle" aria-hidden="true" />
                <span>
                    <b>
                        {outside} result{outside === 1 ? '' : 's'} outside the range
                    </b>
                    <small>Marked by the technician who entered them. See Results.</small>
                </span>
            </div>
        );
    }

    return (
        <div className="lab-summary is-normal">
            <i className="ti ti-circle-check" aria-hidden="true" />
            <span>
                <b>No result marked outside the range</b>
                <small>
                    {withResult.length} of {items.length} test{items.length === 1 ? '' : 's'} reported.
                </small>
            </span>
        </div>
    );
}

/**
 * Test Details: one request — who it is for, what was asked, what came back
 * and what happened to it, on three tabs.
 *
 * Read only: entering results, verification and the printable report arrive
 * in their own phases. The patient's whole record is one click away.
 */
export default function LabOrderPage() {
    const { id } = useParams<{ id: string }>();
    const { can } = useTenantAuth();
    const { data: order, isLoading, isError, refetch } = useLabOrder(Number(id));
    const [view, setView] = useState<View>('info');

    if (isLoading) {
        return <LoadingBlock label="Loading test details…" />;
    }

    if (isError || !order) {
        return <ErrorState onRetry={refetch} />;
    }

    const items = order.items ?? [];
    const history = [
        { at: order.created_at, title: 'Requested', who: order.doctor_name ? `by ${order.doctor_name}` : null, icon: 'ti ti-file-plus' },
        { at: order.started_at, title: 'Taken up by the lab', who: order.started_by_name ? `by ${order.started_by_name}` : null, icon: 'ti ti-test-pipe' },
        { at: order.completed_at, title: 'Results signed off', who: order.completed_by_name ? `by ${order.completed_by_name}` : null, icon: 'ti ti-circle-check' },
        {
            at: order.cancelled_at,
            title: 'Cancelled',
            who: [order.cancelled_by_name ? `by ${order.cancelled_by_name}` : null, order.cancellation_reason].filter(Boolean).join(' — '),
            icon: 'ti ti-circle-x',
        },
    ].filter((step) => step.at);

    return (
        <>
            <PageHeader
                title="Test Details"
                subtitle={`${order.order_number} · ${order.tests_summary ?? ''}`}
                icon="ti ti-test-pipe"
                tone="violet"
                crumbs={[{ label: 'Laboratory' }, { label: 'Tests', to: '/lab/orders' }, { label: order.order_number }]}
            />

            <Card className="lab-patient">
                <Avatar name={order.patient_name ?? ''} src={order.patient_photo_url ?? undefined} size={52} />
                <div className="lab-patient-text">
                    <h5>{order.patient_name ?? '—'}</h5>
                    <p>
                        {[order.patient_code ? `ID ${order.patient_code}` : null, patientFacts(order.patient_age, order.patient_gender) || null]
                            .filter(Boolean)
                            .join(' · ')}
                    </p>
                </div>
                <div className="lab-patient-side">
                    <LabStatus status={order.status} />
                    {can('customers.records_view') && (
                        <Link className="btn btn-outline-secondary btn-sm" to={`/patient-records?patient=${order.customer_id}&tab=laboratory`}>
                            <i className="ti ti-folder-open me-1" aria-hidden="true" />
                            Patient record
                        </Link>
                    )}
                </div>
            </Card>

            <div className="inv-tabs-row lab-tabs-row">
                <LabTabs
                    label="Test details"
                    value={view}
                    onChange={setView}
                    tabs={[
                        { value: 'info', label: 'Test Information' },
                        { value: 'results', label: 'Results', count: items.length },
                        { value: 'history', label: 'History' },
                    ]}
                />
            </div>

            <Card className="lab-detail">
                {view === 'info' && (
                    <>
                        <div className="lab-facts">
                            <Fact label="Test name" wide>{order.tests_summary}</Fact>
                            <Fact label="Department">{order.departments?.join(', ')}</Fact>
                            <Fact label="Request no.">{order.order_number}</Fact>
                            <Fact label="Sample type">{order.sample_types?.join(', ')}</Fact>
                            <Fact label="Ordered on">{stamp(order.created_at)}</Fact>
                            <Fact label="Taken up">{order.started_at ? stamp(order.started_at) : null}</Fact>
                            <Fact label="Reported on">{order.completed_at ? stamp(order.completed_at) : null}</Fact>
                            <Fact label="Ordered by">{order.doctor_name}</Fact>
                            <Fact label="Technician">{order.completed_by_name ?? order.started_by_name}</Fact>
                            <Fact label="Branch">{order.location_name}</Fact>
                            <Fact label="Visit token">{order.token_label}</Fact>
                            <Fact label="Clinical notes" wide>{order.clinical_notes}</Fact>
                        </div>

                        <h6 className="lab-detail-heading">Result Summary</h6>
                        <ResultSummary order={order} />
                    </>
                )}

                {view === 'results' && (
                    <div className="lab-ranges-wrap">
                        <table className="lab-ranges lab-results">
                            <thead>
                                <tr>
                                    <th>Test</th>
                                    <th>Result</th>
                                    <th>Unit</th>
                                    <th>Reference range</th>
                                    <th>Flag</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                {items.map((item) => (
                                    <tr key={item.id}>
                                        <td>
                                            <b>{item.test_name}</b>
                                            {item.instructions && (
                                                <small className="d-block text-muted">Doctor&rsquo;s note: {item.instructions}</small>
                                            )}
                                            {item.notes && <small className="d-block text-muted">{item.notes}</small>}
                                        </td>
                                        <td className={item.is_abnormal ? 'lab-result is-abnormal' : 'lab-result'}>
                                            {item.result_value ?? '—'}
                                        </td>
                                        <td>{item.result_unit ?? '—'}</td>
                                        <td>{item.reference_range ?? '—'}</td>
                                        <td>
                                            {item.result_value === null ? (
                                                '—'
                                            ) : item.is_abnormal ? (
                                                <span className="lab-flag is-abnormal">Outside range</span>
                                            ) : (
                                                <span className="lab-flag is-normal">Normal</span>
                                            )}
                                        </td>
                                        <td>
                                            <LabStatus status={item.status} />
                                        </td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </div>
                )}

                {view === 'history' && (
                    <ol className="lab-history">
                        {history.map((step) => (
                            <li key={step.title}>
                                <span className="lab-history-icon" aria-hidden="true">
                                    <i className={step.icon} />
                                </span>
                                <div>
                                    <b>{step.title}</b>
                                    <small>
                                        {stamp(step.at)}
                                        {step.who ? ` · ${step.who}` : ''}
                                    </small>
                                </div>
                            </li>
                        ))}
                    </ol>
                )}
            </Card>
        </>
    );
}
