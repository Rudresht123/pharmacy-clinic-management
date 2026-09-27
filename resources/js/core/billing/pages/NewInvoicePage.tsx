import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { SearchableSelect, type SelectOption } from '@/shared/components/form/SearchableSelect';
import { resolveErrorMessage, getValidationErrors } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { locationsHooks } from '@/core/locations/api';
import { customersHooks } from '@/core/customers/api';
import {
    useBillableServices,
    useBillingSettings,
    useCreateInvoice,
    type BillableService,
    type InvoiceLineInput,
} from '../api';

interface EditableLine extends InvoiceLineInput {
    key: string;
}

let nextKey = 0;
function newLineKey() {
    nextKey += 1;
    return `line-${nextKey}`;
}

function blankLine(): EditableLine {
    return {
        key: newLineKey(),
        source_type: 'custom',
        description: '',
        quantity: 1,
        unit_price: 0,
    };
}

/**
 * Manual invoice.
 *
 * The one the counter draws by hand, for something the trigger did not
 * (or could not) — a walk-in for a certificate, a fee outside the visit, a
 * charge added after the doctor finished. An invoice for a completed visit
 * already exists on the appointment; this page raises a new one from
 * scratch.
 */
export default function NewInvoicePage() {
    const navigate = useNavigate();
    const { activeBranch } = useTenantAuth();

    const { data: settings } = useBillingSettings();
    const { data: locations } = locationsHooks.useList({ all: 1 });
    const services = useBillableServices({ active_only: true });
    const createInvoice = useCreateInvoice();

    const [locationId, setLocationId] = useState<number | null>(activeBranch ?? null);
    const [isWalkIn, setIsWalkIn] = useState(false);
    const [customerId, setCustomerId] = useState<number | null>(null);
    const [walkInName, setWalkInName] = useState('');
    const [walkInPhone, setWalkInPhone] = useState('');
    const [notes, setNotes] = useState('');
    const [lines, setLines] = useState<EditableLine[]>(() => [blankLine()]);

    const [error, setError] = useState<string | null>(null);
    const [fieldErrors, setFieldErrors] = useState<Record<string, string[]>>({});

    // Customer search — server-side.
    const [customerQuery, setCustomerQuery] = useState('');
    const { data: customers } = customersHooks.useList({ q: customerQuery || undefined, per_page: 20 });

    const customerOptions: SelectOption[] = useMemo(
        () =>
            (customers ?? []).map((customer) => ({
                value: String(customer.id),
                label: customer.name ?? `Patient #${customer.id}`,
                hint: customer.phone ?? undefined,
            })),
        [customers],
    );

    const branchOptions: SelectOption[] = useMemo(
        () =>
            (locations ?? []).map((location) => ({
                value: String(location.id),
                label: location.name ?? `Branch #${location.id}`,
            })),
        [locations],
    );

    function setLine(index: number, patch: Partial<InvoiceLineInput>) {
        setLines((prev) => prev.map((line, i) => (i === index ? { ...line, ...patch } : line)));
    }

    function addLine() {
        setLines((prev) => [...prev, blankLine()]);
    }

    function removeLine(index: number) {
        setLines((prev) => prev.filter((_, i) => i !== index));
    }

    function useService(index: number, service: BillableService) {
        setLine(index, {
            source_type: 'service',
            source_id: service.id,
            billable_service_id: service.id,
            description: service.name,
            unit_price: service.default_price,
            tax_percent: service.tax_percent,
        });
    }

    const subtotal = useMemo(
        () => lines.reduce((sum, line) => sum + (line.quantity || 0) * (line.unit_price || 0), 0),
        [lines],
    );

    async function submit(event: React.FormEvent) {
        event.preventDefault();
        setError(null);
        setFieldErrors({});

        if (locationId === null) {
            setError('Choose a branch.');
            return;
        }

        try {
            const invoice = await createInvoice.mutateAsync({
                location_id: locationId,
                customer_id: isWalkIn ? null : customerId,
                walk_in_name: isWalkIn ? walkInName : null,
                walk_in_phone: isWalkIn ? walkInPhone || null : null,
                notes: notes || null,
                items: lines.map((line) => ({
                    source_type: line.source_type,
                    source_id: line.source_id ?? null,
                    billable_service_id: line.billable_service_id ?? null,
                    description: line.description,
                    quantity: line.quantity,
                    unit_price: line.unit_price,
                    discount_percent: line.discount_percent,
                    tax_percent: line.tax_percent,
                })),
            });

            navigate(`/billing/invoices/${invoice.id}`);
        } catch (failure) {
            const errors = getValidationErrors(failure);

            if (errors) setFieldErrors(errors);

            setError(resolveErrorMessage(failure));
        }
    }

    const currency = settings?.currency_symbol ?? '₹';

    return (
        <form onSubmit={submit}>
            <PageHeader
                title="New invoice"
                subtitle="Raise a bill by hand — for something the automatic trigger did not cover."
                icon="ti ti-plus"
                tone="teal"
                crumbs={[
                    { label: 'Billing', to: '/billing' },
                    { label: 'New invoice' },
                ]}
                actions={
                    <div className="d-flex gap-2">
                        <Button variant="light" icon="ti ti-x" onClick={() => navigate('/billing')} type="button">
                            Cancel
                        </Button>
                        <Button type="submit" icon="ti ti-check" disabled={createInvoice.isPending}>
                            {createInvoice.isPending ? 'Raising…' : 'Raise invoice'}
                        </Button>
                    </div>
                }
            />

            {error && <div className="alert alert-danger py-2">{error}</div>}

            <div className="row g-3">
                <div className="col-lg-8">
                    <Card>
                        <h6 className="mb-3">Patient</h6>

                        <div className="d-flex gap-3 mb-3">
                            <label className="form-check">
                                <input
                                    type="radio"
                                    className="form-check-input"
                                    checked={!isWalkIn}
                                    onChange={() => setIsWalkIn(false)}
                                />
                                <span className="form-check-label">Registered patient</span>
                            </label>
                            <label className="form-check">
                                <input
                                    type="radio"
                                    className="form-check-input"
                                    checked={isWalkIn}
                                    onChange={() => setIsWalkIn(true)}
                                />
                                <span className="form-check-label">Walk-in</span>
                            </label>
                        </div>

                        {isWalkIn ? (
                            <div className="row g-2">
                                <div className="col-md-8">
                                    <label className="form-label fs-13">Name</label>
                                    <input
                                        type="text"
                                        className={`form-control ${fieldErrors.walk_in_name ? 'is-invalid' : ''}`}
                                        value={walkInName}
                                        onChange={(event) => setWalkInName(event.target.value)}
                                        maxLength={120}
                                        required
                                    />
                                </div>
                                <div className="col-md-4">
                                    <label className="form-label fs-13">Phone (optional)</label>
                                    <input
                                        type="tel"
                                        className="form-control"
                                        value={walkInPhone}
                                        onChange={(event) => setWalkInPhone(event.target.value)}
                                        maxLength={20}
                                    />
                                </div>
                            </div>
                        ) : (
                            <div>
                                <label className="form-label fs-13">Patient</label>
                                <SearchableSelect
                                    value={customerId ? String(customerId) : ''}
                                    onChange={(value) => setCustomerId(value ? Number(value) : null)}
                                    options={customerOptions}
                                    placeholder="Search by name or phone…"
                                    ariaLabel="Patient"
                                    clearable
                                />
                                <small className="text-muted">
                                    Type to search. The list is capped at 20; refine the query if the
                                    patient does not appear.
                                </small>
                                <input
                                    type="hidden"
                                    value={customerQuery}
                                    onChange={(event) => setCustomerQuery(event.target.value)}
                                />
                            </div>
                        )}

                        <div className="mt-3">
                            <label className="form-label fs-13">Branch</label>
                            <SearchableSelect
                                value={locationId ? String(locationId) : ''}
                                onChange={(value) => setLocationId(value ? Number(value) : null)}
                                options={branchOptions}
                                placeholder="Which branch is this invoice from?"
                                ariaLabel="Branch"
                                invalid={Boolean(fieldErrors.location_id)}
                            />
                        </div>
                    </Card>

                    <Card className="mt-3">
                        <div className="d-flex justify-content-between align-items-center mb-3">
                            <h6 className="mb-0">Lines</h6>
                            <Button size="sm" variant="light" icon="ti ti-plus" onClick={addLine} type="button">
                                Add line
                            </Button>
                        </div>

                        {lines.length === 0 && (
                            <p className="text-muted fs-13">An invoice needs at least one line.</p>
                        )}

                        {lines.map((line, index) => (
                            <div key={line.key} className="border rounded p-3 mb-2">
                                <div className="row g-2 align-items-end">
                                    <div className="col-md-5">
                                        <label className="form-label fs-13">Description</label>
                                        <input
                                            type="text"
                                            className="form-control"
                                            value={line.description}
                                            onChange={(event) =>
                                                setLine(index, { description: event.target.value })
                                            }
                                            placeholder="Consultation, injection, dressing…"
                                            required
                                        />
                                    </div>
                                    <div className="col-md-2">
                                        <label className="form-label fs-13">Qty</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0.01"
                                            className="form-control"
                                            value={line.quantity}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    quantity: parseFloat(event.target.value) || 0,
                                                })
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="col-md-2">
                                        <label className="form-label fs-13">Unit price</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            className="form-control"
                                            value={line.unit_price}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    unit_price: parseFloat(event.target.value) || 0,
                                                })
                                            }
                                            required
                                        />
                                    </div>
                                    <div className="col-md-1">
                                        <label className="form-label fs-13">Disc %</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            className="form-control"
                                            value={line.discount_percent ?? 0}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    discount_percent: parseFloat(event.target.value) || 0,
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="col-md-1">
                                        <label className="form-label fs-13">Tax %</label>
                                        <input
                                            type="number"
                                            step="0.01"
                                            min="0"
                                            max="100"
                                            className="form-control"
                                            value={line.tax_percent ?? settings?.default_tax_percent ?? 0}
                                            onChange={(event) =>
                                                setLine(index, {
                                                    tax_percent: parseFloat(event.target.value) || 0,
                                                })
                                            }
                                        />
                                    </div>
                                    <div className="col-md-1 text-end">
                                        {lines.length > 1 && (
                                            <button
                                                type="button"
                                                className="btn btn-link text-danger p-0"
                                                onClick={() => removeLine(index)}
                                                aria-label="Remove line"
                                            >
                                                <i className="ti ti-trash" />
                                            </button>
                                        )}
                                    </div>
                                </div>

                                {(services.data ?? []).length > 0 && (
                                    <div className="mt-2 d-flex gap-2 flex-wrap">
                                        <span className="fs-13 text-muted">Quick pick:</span>
                                        {(services.data ?? []).map((service) => (
                                            <button
                                                type="button"
                                                className="btn btn-sm btn-outline-secondary"
                                                key={service.id}
                                                onClick={() => useService(index, service)}
                                            >
                                                {service.name} · {currency}{service.default_price.toFixed(2)}
                                            </button>
                                        ))}
                                    </div>
                                )}
                            </div>
                        ))}
                    </Card>

                    <Card className="mt-3">
                        <label className="form-label fs-13">Note on the invoice (optional)</label>
                        <textarea
                            className="form-control"
                            rows={2}
                            value={notes}
                            onChange={(event) => setNotes(event.target.value)}
                            maxLength={2000}
                        />
                    </Card>
                </div>

                <div className="col-lg-4">
                    <Card>
                        <h6 className="mb-3">Running total</h6>
                        <dl className="row mb-0 fs-13">
                            <dt className="col-6">Lines</dt>
                            <dd className="col-6 text-end">{lines.length}</dd>
                            <dt className="col-6">Subtotal</dt>
                            <dd className="col-6 text-end tabular-nums">
                                {currency}{subtotal.toFixed(2)}
                            </dd>
                        </dl>
                        <p className="text-muted fs-13 mt-3 mb-0">
                            Discount and tax are added to each line and totalled on save. The invoice
                            number and running-total reconciliation are set once you submit.
                        </p>
                    </Card>
                </div>
            </div>
        </form>
    );
}
