import { useMemo, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { resolveErrorMessage } from '@/shared/api/http';
import { medicinesHooks } from '@/core/medicines/api';
import { NoStores, StorePicker, useChosenStore } from '../components/StorePicker';
import { INWARD_TYPE_LABELS, newIdempotencyKey, suppliersHooks, useReceiveGoods } from '../inventory';

/** One line as typed — strings until it is sent. */
interface Line {
    medicine_id: string;
    batch_number: string;
    expiry_date: string;
    manufacture_date: string;
    quantity: string;
    free_quantity: string;
    purchase_price: string;
    mrp: string;
    selling_price: string;
}

const BLANK: Line = {
    medicine_id: '',
    batch_number: '',
    expiry_date: '',
    manufacture_date: '',
    quantity: '',
    free_quantity: '',
    purchase_price: '',
    mrp: '',
    selling_price: '',
};

const today = () => new Date().toLocaleDateString('en-CA');

/**
 * Receiving goods, as the invoice reads.
 *
 * Quantities and prices are per pack by default — a strip, a bottle — and the
 * server stores them per base unit, rounding to the paisa. An opening balance
 * of loose tablets switches that off and counts in tablets.
 *
 * One idempotency key for as long as the form is open: pressing Save twice,
 * or a retry after a dropped connection, receives the goods once.
 */
export default function InwardFormPage() {
    const navigate = useNavigate();

    const { stores, store, choose, isLoading: storesLoading } = useChosenStore();

    const { data: catalogue } = medicinesHooks.useList({
        per_page: 200,
        status: 'active',
        sort: 'generic_name',
        direction: 'asc',
    });
    const { data: suppliers } = suppliersHooks.useList({ all: 1 });

    const receive = useReceiveGoods(store?.id);

    const [type, setType] = useState<'purchase' | 'opening_balance' | 'return_from_patient'>('purchase');
    const [supplierId, setSupplierId] = useState('');
    const [invoiceNo, setInvoiceNo] = useState('');
    const [invoiceDate, setInvoiceDate] = useState('');
    const [receivedDate, setReceivedDate] = useState(today);
    const [notes, setNotes] = useState('');
    const [inPacks, setInPacks] = useState(true);
    const [lines, setLines] = useState<Line[]>([{ ...BLANK }]);
    const [error, setError] = useState<string | null>(null);
    const [key] = useState(newIdempotencyKey);

    const packSize = useMemo(() => {
        const sizes = new Map<string, { size: number; unit: string }>();

        (catalogue ?? []).forEach((medicine) =>
            sizes.set(String(medicine.id), { size: medicine.pack_size, unit: medicine.base_unit }),
        );

        return sizes;
    }, [catalogue]);

    function patch(index: number, change: Partial<Line>) {
        setLines((was) => was.map((line, at) => (at === index ? { ...line, ...change } : line)));
    }

    const total = lines.reduce(
        (sum, line) => sum + (Number(line.quantity) || 0) * (Number(line.purchase_price) || 0),
        0,
    );

    async function submit() {
        setError(null);

        try {
            await receive.mutateAsync({
                key,
                payload: {
                    inward_type: type,
                    supplier_id: supplierId ? Number(supplierId) : null,
                    supplier_invoice_no: invoiceNo || null,
                    supplier_invoice_date: invoiceDate || null,
                    received_date: receivedDate,
                    notes: notes || null,
                    items: lines.map((line) => ({
                        medicine_id: Number(line.medicine_id),
                        batch_number: line.batch_number,
                        expiry_date: line.expiry_date,
                        manufacture_date: line.manufacture_date || null,
                        in_packs: inPacks,
                        quantity: Number(line.quantity),
                        free_quantity: Number(line.free_quantity) || 0,
                        purchase_price: Number(line.purchase_price),
                        mrp: Number(line.mrp),
                        selling_price: line.selling_price === '' ? null : Number(line.selling_price),
                    })),
                },
            });

            navigate(`/pharmacy/inwards?store=${store?.id}`);
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    if (storesLoading) {
        return <LoadingBlock label="Loading stores…" />;
    }

    if (!store) {
        return (
            <Card>
                <NoStores />
            </Card>
        );
    }

    const per = inPacks ? 'pack' : 'unit';

    return (
        <>
            <PageHeader
                title="Receive Goods"
                subtitle="One goods received note: what came in, in which batches, at what price."
                icon="ti ti-truck-delivery"
                tone="teal"
                crumbs={[
                    { label: 'Pharmacy' },
                    { label: 'Receive Goods', to: `/pharmacy/inwards?store=${store.id}` },
                    { label: 'New' },
                ]}
            />

            <form
                noValidate
                onSubmit={(event) => {
                    event.preventDefault();
                    void submit();
                }}
            >
                {error && <div className="alert alert-danger py-2 px-3 fs-13">{error}</div>}

                <Card title="The Delivery" icon="ti ti-file-invoice" description="Where it arrived, and from whom.">
                    <div className="row g-3">
                        <div className="col-md-4">
                            <StorePicker stores={stores} value={store} onChange={choose} />
                        </div>

                        <div className="col-md-4">
                            <label className="form-label mb-1" htmlFor="inward-type">
                                What it is <span className="req">*</span>
                            </label>
                            <select
                                id="inward-type"
                                className="form-select"
                                value={type}
                                onChange={(event) => {
                                    const next = event.target.value as typeof type;
                                    setType(next);
                                    // Loose stock on the shelf is counted in units.
                                    setInPacks(next !== 'opening_balance');
                                }}
                            >
                                {Object.entries(INWARD_TYPE_LABELS).map(([value, label]) => (
                                    <option key={value} value={value}>
                                        {label}
                                    </option>
                                ))}
                            </select>
                        </div>

                        <div className="col-md-4">
                            <label className="form-label mb-1" htmlFor="inward-date">
                                Received on <span className="req">*</span>
                            </label>
                            <input
                                id="inward-date"
                                type="date"
                                className="form-control"
                                max={today()}
                                value={receivedDate}
                                onChange={(event) => setReceivedDate(event.target.value)}
                            />
                        </div>

                        <div className="col-md-4">
                            <label className="form-label mb-1" htmlFor="inward-supplier">
                                Supplier {type === 'purchase' && <span className="req">*</span>}
                            </label>
                            <SearchableSelect
                                id="inward-supplier"
                                value={supplierId}
                                onChange={setSupplierId}
                                clearable={type !== 'purchase'}
                                options={(suppliers ?? []).map((supplier) => ({
                                    value: String(supplier.id),
                                    label: supplier.name,
                                    hint: supplier.gstin ?? undefined,
                                }))}
                                placeholder="Choose a supplier"
                            />
                        </div>

                        <div className="col-md-4">
                            <label className="form-label mb-1" htmlFor="inward-invoice">
                                Invoice number
                            </label>
                            <input
                                id="inward-invoice"
                                className="form-control"
                                maxLength={60}
                                value={invoiceNo}
                                onChange={(event) => setInvoiceNo(event.target.value)}
                            />
                        </div>

                        <div className="col-md-4">
                            <label className="form-label mb-1" htmlFor="inward-invoice-date">
                                Invoice date
                            </label>
                            <input
                                id="inward-invoice-date"
                                type="date"
                                className="form-control"
                                max={today()}
                                value={invoiceDate}
                                onChange={(event) => setInvoiceDate(event.target.value)}
                            />
                        </div>
                    </div>
                </Card>

                <Card
                    title="Lines"
                    icon="ti ti-list-details"
                    description={
                        inPacks
                            ? 'Quantities and prices per pack, as invoiced. They are stored per unit.'
                            : 'Quantities and prices per unit — a tablet, a bottle.'
                    }
                    actions={
                        <div className="form-check form-switch m-0">
                            <input
                                id="in-packs"
                                type="checkbox"
                                className="form-check-input"
                                checked={inPacks}
                                onChange={(event) => setInPacks(event.target.checked)}
                            />
                            <label className="form-check-label" htmlFor="in-packs">
                                Per pack
                            </label>
                        </div>
                    }
                >
                    <div className="pf-table-wrap">
                        <table className="table table-sm align-middle mb-0 ph-lines">
                            <thead>
                                <tr>
                                    <th style={{ minWidth: '14rem' }}>Medicine</th>
                                    <th>Batch</th>
                                    <th>Expiry</th>
                                    <th>Made</th>
                                    <th>Qty ({per}s)</th>
                                    <th>Free</th>
                                    <th>Cost / {per}</th>
                                    <th>MRP / {per}</th>
                                    <th>Sell / {per}</th>
                                    <th />
                                </tr>
                            </thead>
                            <tbody>
                                {lines.map((line, index) => {
                                    const pack = packSize.get(line.medicine_id);

                                    return (
                                        <tr key={index}>
                                            <td>
                                                <SearchableSelect
                                                    compact
                                                    value={line.medicine_id}
                                                    onChange={(value) => patch(index, { medicine_id: value })}
                                                    options={(catalogue ?? []).map((medicine) => ({
                                                        value: String(medicine.id),
                                                        label: medicine.display_name,
                                                        hint: `${medicine.pack_size} ${medicine.base_unit} a pack`,
                                                    }))}
                                                    placeholder="Medicine"
                                                    ariaLabel={`Medicine, line ${index + 1}`}
                                                />
                                                {pack && inPacks && (
                                                    <small className="text-muted">
                                                        {pack.size} {pack.unit} a pack
                                                    </small>
                                                )}
                                            </td>
                                            {(
                                                [
                                                    ['batch_number', 'text', '7rem'],
                                                    ['expiry_date', 'date', '9.5rem'],
                                                    ['manufacture_date', 'date', '9.5rem'],
                                                    ['quantity', 'number', '5.5rem'],
                                                    ['free_quantity', 'number', '4.5rem'],
                                                    ['purchase_price', 'number', '6rem'],
                                                    ['mrp', 'number', '6rem'],
                                                    ['selling_price', 'number', '6rem'],
                                                ] as const
                                            ).map(([field, kind, width]) => (
                                                <td key={field}>
                                                    <input
                                                        type={kind}
                                                        className="form-control form-control-sm"
                                                        style={{ width }}
                                                        min={kind === 'number' ? 0 : undefined}
                                                        step={field.endsWith('price') || field === 'mrp' ? '0.01' : undefined}
                                                        placeholder={field === 'selling_price' ? 'MRP' : undefined}
                                                        aria-label={`${field.replace('_', ' ')}, line ${index + 1}`}
                                                        value={line[field]}
                                                        onChange={(event) => patch(index, { [field]: event.target.value })}
                                                    />
                                                </td>
                                            ))}
                                            <td>
                                                <Button
                                                    size="sm"
                                                    variant="light"
                                                    icon="ti ti-x"
                                                    aria-label={`Remove line ${index + 1}`}
                                                    disabled={lines.length === 1}
                                                    onClick={() => setLines((was) => was.filter((_, at) => at !== index))}
                                                />
                                            </td>
                                        </tr>
                                    );
                                })}
                            </tbody>
                        </table>
                    </div>

                    <div className="d-flex justify-content-between align-items-center mt-3">
                        <Button
                            variant="light"
                            icon="ti ti-plus"
                            onClick={() => setLines((was) => [...was, { ...BLANK }])}
                        >
                            Add line
                        </Button>

                        <b className="tabular-nums">Total ₹{total.toFixed(2)}</b>
                    </div>
                </Card>

                <Card title="Note" icon="ti ti-note">
                    <textarea
                        className="form-control"
                        rows={2}
                        maxLength={2000}
                        value={notes}
                        onChange={(event) => setNotes(event.target.value)}
                        placeholder="Two cartons, one damaged in transit"
                        aria-label="Note"
                    />
                </Card>

                <div className="form-actions">
                    <span className="form-actions-note">
                        Saved as posted. A mistake is corrected by cancelling the note, never by editing it.
                    </span>

                    <Button variant="light" onClick={() => navigate(`/pharmacy/inwards?store=${store.id}`)}>
                        Cancel
                    </Button>

                    <Button type="submit" loading={receive.isPending} icon="ti ti-device-floppy">
                        Receive Goods
                    </Button>
                </div>
            </form>
        </>
    );
}
