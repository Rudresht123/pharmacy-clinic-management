import { useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { Modal } from '@/shared/components/ui/Modal';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import {
    useBillableServices,
    useDeleteBillableService,
    useSaveBillableService,
    type BillableService,
} from '../api';

const money = (value: number) => `₹${value.toFixed(2)}`;

/**
 * What each kind of service means, in the words somebody setting one up uses.
 *
 * The KIND is not decoration — it decides when the charge lands. Registration
 * is billed once at the desk; consultation and procedure join every visit's
 * bill automatically; service and custom are picked by hand on a manual bill.
 */
const KINDS: { value: BillableService['kind']; label: string; hint: string }[] = [
    {
        value: 'registration',
        label: 'Registration',
        hint: 'Charged once, at the desk, when a patient record is opened.',
    },
    {
        value: 'consultation',
        label: 'Per visit',
        hint: 'Added to every visit’s bill automatically, alongside the doctor’s fee.',
    },
    {
        value: 'procedure',
        label: 'Procedure',
        hint: 'Added to every visit’s bill automatically, as a procedure line.',
    },
    {
        value: 'service',
        label: 'On demand',
        hint: 'Picked by hand when drawing a manual bill. Never automatic.',
    },
    { value: 'custom', label: 'Other', hint: 'Anything that fits none of the above.' },
];

const blank = (): Omit<BillableService, 'id'> => ({
    location_id: null,
    name: '',
    code: null,
    kind: 'service',
    default_price: 0,
    tax_percent: 0,
    active: true,
    position: 0,
});

/**
 * The price list: what the clinic charges for, besides the doctor's own fee.
 *
 * Organisation-wide by default, the way the medicine master is — so
 * "Injection ₹100" means the same at every branch. A branch may hold its own
 * row for the same service at its own price, and the trigger prefers it.
 */
export default function ServicesPage() {
    const { can } = useTenantAuth();
    const canManage = can('billing.manage_services');

    const { data: services, isLoading } = useBillableServices();
    const save = useSaveBillableService();
    const remove = useDeleteBillableService();

    const [editing, setEditing] = useState<BillableService | 'new' | undefined>();
    const [error, setError] = useState<string | null>(null);

    if (isLoading) return <LoadingBlock label="Loading services…" />;

    const grouped = KINDS.map((kind) => ({
        ...kind,
        rows: (services ?? []).filter((service) => service.kind === kind.value),
    })).filter((group) => group.rows.length > 0 || canManage);

    async function submit(payload: Omit<BillableService, 'id'>) {
        setError(null);

        try {
            await save.mutateAsync({
                id: editing !== 'new' && editing ? editing.id : undefined,
                payload,
            });

            setEditing(undefined);
            notify.success('Saved');
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    async function destroy(service: BillableService) {
        setError(null);

        try {
            await remove.mutateAsync(service.id);
            notify.success(`${service.name} removed`);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    return (
        <>
            <PageHeader
                title="Services"
                subtitle="What the clinic charges for, besides the doctor's own consultation fee."
                icon="ti ti-list-details"
                tone="violet"
                crumbs={[
                    { label: 'Billing', to: '/billing' },
                    { label: 'Settings', to: '/billing/settings' },
                    { label: 'Services' },
                ]}
                actions={
                    canManage ? (
                        <Button icon="ti ti-plus" onClick={() => setEditing('new')}>
                            Add service
                        </Button>
                    ) : undefined
                }
            />

            {!canManage && (
                <div className="alert alert-info py-2">
                    You may read the price list; changing it is a head-office decision.
                </div>
            )}

            {error && <div className="alert alert-danger py-2">{error}</div>}

            {grouped.map((group) => (
                <Card className="mb-3" key={group.value}>
                    <h6 className="mb-1">{group.label}</h6>
                    <p className="dr-sub mb-3">{group.hint}</p>

                    {group.rows.length === 0 ? (
                        <p className="text-muted fs-13 mb-0">Nothing set up here yet.</p>
                    ) : (
                        <div className="pf-table-wrap">
                            <table className="table table-sm mb-0">
                                <thead>
                                    <tr>
                                        <th>Name</th>
                                        <th>Code</th>
                                        <th className="text-end">Price</th>
                                        <th className="text-end">Tax %</th>
                                        <th>Scope</th>
                                        <th>Status</th>
                                        {canManage && <th />}
                                    </tr>
                                </thead>
                                <tbody>
                                    {group.rows.map((service) => (
                                        <tr key={service.id}>
                                            <td>
                                                <b>{service.name}</b>
                                            </td>
                                            <td className="dr-sub">{service.code ?? '—'}</td>
                                            <td className="text-end tabular-nums">
                                                {money(service.default_price)}
                                            </td>
                                            <td className="text-end tabular-nums">
                                                {service.tax_percent > 0
                                                    ? `${service.tax_percent}%`
                                                    : '—'}
                                            </td>
                                            <td>
                                                <span className="badge bg-light text-dark">
                                                    {service.location_id === null
                                                        ? 'Organisation'
                                                        : 'This branch'}
                                                </span>
                                            </td>
                                            <td>
                                                <span
                                                    className={`badge bg-${service.active ? 'success' : 'secondary'}-subtle text-${service.active ? 'success' : 'secondary'}`}
                                                >
                                                    {service.active ? 'Active' : 'Off'}
                                                </span>
                                            </td>
                                            {canManage && (
                                                <td className="text-end">
                                                    <Button
                                                        size="sm"
                                                        variant="light"
                                                        icon="ti ti-pencil"
                                                        aria-label="Edit"
                                                        onClick={() => setEditing(service)}
                                                    />
                                                    <Button
                                                        size="sm"
                                                        variant="light"
                                                        icon="ti ti-trash"
                                                        aria-label="Remove"
                                                        className="ms-1 text-danger"
                                                        onClick={() => void destroy(service)}
                                                    />
                                                </td>
                                            )}
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        </div>
                    )}
                </Card>
            ))}

            {editing && (
                <ServiceDialog
                    initial={editing === 'new' ? blank() : editing}
                    saving={save.isPending}
                    onClose={() => setEditing(undefined)}
                    onSubmit={submit}
                />
            )}
        </>
    );
}

function ServiceDialog({
    initial,
    saving,
    onClose,
    onSubmit,
}: {
    initial: Omit<BillableService, 'id'>;
    saving: boolean;
    onClose: () => void;
    onSubmit: (payload: Omit<BillableService, 'id'>) => void;
}) {
    const [form, setForm] = useState(initial);

    function set<K extends keyof typeof form>(key: K, value: (typeof form)[K]) {
        setForm((was) => ({ ...was, [key]: value }));
    }

    const kind = KINDS.find((k) => k.value === form.kind);

    return (
        <Modal open onClose={onClose} title={initial.name || 'Add service'} size="md">
            <form
                onSubmit={(event) => {
                    event.preventDefault();
                    onSubmit(form);
                }}
            >
                <div className="row g-3">
                    <div className="col-8">
                        <label className="form-label fs-13">Name</label>
                        <input
                            type="text"
                            className="form-control"
                            value={form.name}
                            onChange={(e) => set('name', e.target.value)}
                            maxLength={120}
                            required
                            autoFocus
                        />
                    </div>

                    <div className="col-4">
                        <label className="form-label fs-13">Code (optional)</label>
                        <input
                            type="text"
                            className="form-control"
                            value={form.code ?? ''}
                            onChange={(e) => set('code', e.target.value || null)}
                            maxLength={24}
                        />
                    </div>

                    <div className="col-12">
                        <label className="form-label fs-13">When is it charged?</label>
                        <select
                            className="form-select"
                            value={form.kind}
                            onChange={(e) =>
                                set('kind', e.target.value as BillableService['kind'])
                            }
                        >
                            {KINDS.map((option) => (
                                <option key={option.value} value={option.value}>
                                    {option.label}
                                </option>
                            ))}
                        </select>
                        {kind && <small className="text-muted">{kind.hint}</small>}
                    </div>

                    <div className="col-6">
                        <label className="form-label fs-13">Price</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            className="form-control"
                            value={form.default_price}
                            onChange={(e) =>
                                set('default_price', parseFloat(e.target.value) || 0)
                            }
                            required
                        />
                    </div>

                    <div className="col-6">
                        <label className="form-label fs-13">Tax %</label>
                        <input
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            className="form-control"
                            value={form.tax_percent}
                            onChange={(e) => set('tax_percent', parseFloat(e.target.value) || 0)}
                        />
                    </div>

                    <div className="col-12">
                        <label className="form-check form-switch">
                            <input
                                type="checkbox"
                                className="form-check-input"
                                checked={form.active}
                                onChange={(e) => set('active', e.target.checked)}
                            />
                            <span className="form-check-label fs-13">
                                Active — switched off, it stops being charged without losing what
                                it already billed
                            </span>
                        </label>
                    </div>
                </div>

                <div className="mt-4 d-flex justify-content-end gap-2">
                    <Button variant="light" type="button" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button type="submit" icon="ti ti-check" disabled={saving}>
                        {saving ? 'Saving…' : 'Save'}
                    </Button>
                </div>
            </form>
        </Modal>
    );
}
