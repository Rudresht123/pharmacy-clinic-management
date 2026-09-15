import { useEffect, useState } from 'react';
import { FormModal } from '@/shared/components/ui/FormModal';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { resolveErrorMessage } from '@/shared/api/http';
import {
    newIdempotencyKey,
    REASON_LABELS,
    useAdjustStock,
    useTransferStock,
    type MedicineBatch,
} from '../inventory';
import type { PharmacyStore } from '../types';

/**
 * Adjust one batch: damage, a write-off, a count that disagreed.
 *
 * One key for as long as the dialog is open, so pressing the button twice
 * adjusts once.
 */
export function AdjustDialog({
    batch,
    storeId,
    onClose,
}: {
    batch: MedicineBatch | undefined;
    storeId: number | undefined;
    onClose: () => void;
}) {
    const adjust = useAdjustStock(storeId);

    const [direction, setDirection] = useState<'decrease' | 'increase'>('decrease');
    const [quantity, setQuantity] = useState('');
    const [reasonCode, setReasonCode] = useState('damage');
    const [reason, setReason] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [key, setKey] = useState(newIdempotencyKey);

    useEffect(() => {
        if (batch) {
            setDirection(batch.is_past_expiry || batch.status === 'expired' ? 'decrease' : 'decrease');
            setReasonCode(batch.is_past_expiry || batch.status === 'expired' ? 'expiry_writeoff' : 'damage');
            setQuantity('');
            setReason('');
            setError(null);
            setKey(newIdempotencyKey());
        }
    }, [batch]);

    // Only a count correction (or "other") can add stock.
    const codes = direction === 'increase' ? ['count_correction', 'other'] : Object.keys(REASON_LABELS);

    async function submit() {
        if (!batch) {
            return;
        }

        try {
            await adjust.mutateAsync({
                key,
                payload: {
                    medicine_batch_id: batch.id,
                    direction,
                    quantity: Number(quantity),
                    reason_code: reasonCode,
                    reason,
                },
            });
            onClose();
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    const unit = batch?.medicine?.base_unit ?? 'units';

    return (
        <FormModal
            open={batch !== undefined}
            onClose={onClose}
            title={`Adjust batch ${batch?.batch_number ?? ''}`}
            subtitle={`${batch?.medicine?.display_name ?? ''} — ${batch?.quantity_available ?? 0} ${unit} on the books.`}
            submitLabel="Record adjustment"
            submitting={adjust.isPending}
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
        >
            <div className="d-flex gap-2 mb-3" role="radiogroup" aria-label="Direction">
                {(['decrease', 'increase'] as const).map((value) => (
                    <label key={value} className="form-check form-check-inline m-0">
                        <input
                            type="radio"
                            className="form-check-input"
                            name="adjust-direction"
                            checked={direction === value}
                            onChange={() => {
                                setDirection(value);
                                setReasonCode(value === 'increase' ? 'count_correction' : 'damage');
                            }}
                        />
                        <span className="form-check-label">
                            {value === 'decrease' ? 'Take stock off' : 'Add stock'}
                        </span>
                    </label>
                ))}
            </div>

            <div className="row g-2">
                <div className="col-5">
                    <label className="form-label" htmlFor="adjust-quantity">
                        Quantity ({unit}) <span className="req">*</span>
                    </label>
                    <input
                        id="adjust-quantity"
                        type="number"
                        min={1}
                        required
                        className="form-control"
                        value={quantity}
                        onChange={(event) => setQuantity(event.target.value)}
                    />
                </div>
                <div className="col-7">
                    <label className="form-label" htmlFor="adjust-code">
                        Why <span className="req">*</span>
                    </label>
                    <select
                        id="adjust-code"
                        className="form-select"
                        value={reasonCode}
                        onChange={(event) => setReasonCode(event.target.value)}
                    >
                        {codes.map((code) => (
                            <option key={code} value={code}>
                                {REASON_LABELS[code]}
                            </option>
                        ))}
                    </select>
                </div>
            </div>

            <label className="form-label mt-3" htmlFor="adjust-reason">
                What happened <span className="req">*</span>
            </label>
            <textarea
                id="adjust-reason"
                className="form-control"
                rows={2}
                maxLength={500}
                required
                value={reason}
                onChange={(event) => setReason(event.target.value)}
                placeholder="Strip torn in the delivery box"
            />

            {error && <div className="alert alert-danger py-2 px-3 fs-13 mt-3 mb-0">{error}</div>}
        </FormModal>
    );
}

/** Move some of one batch to another store, as the same lot. */
export function TransferDialog({
    batch,
    stores,
    onClose,
}: {
    batch: MedicineBatch | undefined;
    stores: PharmacyStore[];
    onClose: () => void;
}) {
    const transfer = useTransferStock();

    const [to, setTo] = useState('');
    const [quantity, setQuantity] = useState('');
    const [notes, setNotes] = useState('');
    const [error, setError] = useState<string | null>(null);
    const [key, setKey] = useState(newIdempotencyKey);

    useEffect(() => {
        if (batch) {
            setTo('');
            setQuantity('');
            setNotes('');
            setError(null);
            setKey(newIdempotencyKey());
        }
    }, [batch]);

    const destinations = stores.filter((store) => store.id !== batch?.pharmacy_store_id);

    async function submit() {
        if (!batch) {
            return;
        }

        try {
            await transfer.mutateAsync({
                key,
                payload: {
                    from_store_id: batch.pharmacy_store_id,
                    to_store_id: Number(to),
                    notes: notes || null,
                    items: [{ batch_id: batch.id, quantity: Number(quantity) }],
                },
            });
            onClose();
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    const unit = batch?.medicine?.base_unit ?? 'units';

    return (
        <FormModal
            open={batch !== undefined}
            onClose={onClose}
            title={`Transfer from batch ${batch?.batch_number ?? ''}`}
            subtitle={`${batch?.quantity_available ?? 0} ${unit} here. It arrives as the same lot, with the same expiry and prices.`}
            submitLabel="Transfer"
            submitting={transfer.isPending}
            onSubmit={(event) => {
                event.preventDefault();
                void submit();
            }}
        >
            <label className="form-label" htmlFor="transfer-to">
                To store <span className="req">*</span>
            </label>
            <SearchableSelect
                id="transfer-to"
                value={to}
                onChange={setTo}
                options={destinations.map((store) => ({
                    value: String(store.id),
                    label: store.name,
                    hint: store.location_name ?? undefined,
                }))}
                placeholder={destinations.length ? 'Choose a store' : 'No other store you can use'}
            />

            <label className="form-label mt-3" htmlFor="transfer-quantity">
                Quantity ({unit}) <span className="req">*</span>
            </label>
            <input
                id="transfer-quantity"
                type="number"
                min={1}
                max={batch?.quantity_available}
                required
                className="form-control"
                value={quantity}
                onChange={(event) => setQuantity(event.target.value)}
            />

            <label className="form-label mt-3" htmlFor="transfer-notes">
                Note
            </label>
            <input
                id="transfer-notes"
                className="form-control"
                maxLength={2000}
                value={notes}
                onChange={(event) => setNotes(event.target.value)}
                placeholder="For the evening counter"
            />

            {error && <div className="alert alert-danger py-2 px-3 fs-13 mt-3 mb-0">{error}</div>}
        </FormModal>
    );
}
