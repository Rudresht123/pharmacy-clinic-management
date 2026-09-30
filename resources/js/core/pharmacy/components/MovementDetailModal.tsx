import { useEffect } from 'react';
import { createPortal } from 'react-dom';
import { formatDateTime } from '@/shared/utils/format';
import { MedicineThumbnail } from './MedicineThumbnail';
import { MOVEMENT_LABELS, type StockMovement } from '../inventory';

interface MovementDetailModalProps {
    /** Null keeps it closed. */
    movement: StockMovement | null;
    onClose: () => void;
}

/**
 * One ledger row, whole.
 *
 * The list has to cut things short to stay scannable — a long reason, the
 * notes, what the movement undid. This is where they are read in full,
 * without the row growing to hold them.
 *
 * Rendered into <body> so no card's overflow or stacking can clip it.
 */
export function MovementDetailModal({ movement, onClose }: MovementDetailModalProps) {
    useEffect(() => {
        if (!movement) return;

        const onKey = (event: KeyboardEvent) => {
            if (event.key === 'Escape') onClose();
        };

        window.addEventListener('keydown', onKey);

        return () => window.removeEventListener('keydown', onKey);
    }, [movement, onClose]);

    if (!movement) {
        return null;
    }

    const quantity = movement.quantity;
    const signed = `${quantity > 0 ? '+' : ''}${quantity}`;
    const reference = movement.reference_type
        ? `${movement.reference_type}${movement.reference_id ? ` #${movement.reference_id}` : ''}`
        : '—';

    return createPortal(
        <div className="mv-modal-backdrop" onClick={onClose} role="presentation">
            <div
                className="mv-modal-card"
                role="dialog"
                aria-modal="true"
                aria-labelledby="mv-modal-title"
                onClick={(event) => event.stopPropagation()}
            >
                <div className="mv-modal-header">
                    <div className="d-flex align-items-center gap-3">
                        <MedicineThumbnail
                            name={movement.medicine_name}
                            dosageForm={movement.medicine_dosage_form}
                            itemKind={movement.medicine_item_kind}
                        />
                        <div>
                            <h5 className="mv-modal-title" id="mv-modal-title">
                                {movement.medicine_name ?? 'Medicine'}
                            </h5>
                            <span className="mv-modal-sub">
                                {MOVEMENT_LABELS[movement.movement_type] ?? movement.movement_type}
                                {movement.movement_date && ` · ${formatDateTime(movement.movement_date)}`}
                            </span>
                        </div>
                    </div>
                    <button type="button" className="mv-modal-close" onClick={onClose} aria-label="Close">
                        <i className="ti ti-x" />
                    </button>
                </div>

                <div className="mv-modal-body">
                    <div className="mv-modal-stats">
                        <div className="mv-modal-stat-box">
                            <span className="mv-modal-stat-label">Quantity</span>
                            <span className={`mv-modal-stat-val ${quantity < 0 ? 'text-danger' : 'text-success'}`}>
                                {signed}
                            </span>
                        </div>
                        <div className="mv-modal-stat-box">
                            <span className="mv-modal-stat-label">Before</span>
                            <span className="mv-modal-stat-val">{movement.quantity_before}</span>
                        </div>
                        <div className="mv-modal-stat-box">
                            <span className="mv-modal-stat-label">After</span>
                            <span className="mv-modal-stat-val">{movement.quantity_after}</span>
                        </div>
                    </div>

                    <div className="mv-modal-grid">
                        <Item label="Batch" value={movement.batch_number} />
                        <Item label="Unit cost" value={movement.unit_cost ? `₹${movement.unit_cost}` : null} />
                        <Item label="Reference" value={reference} />
                        <Item label="By" value={movement.performed_by_name} />
                        {movement.reverses_movement_id && (
                            <Item label="Undoes movement" value={`#${movement.reverses_movement_id}`} />
                        )}
                    </div>

                    {(movement.reason || movement.notes) && (
                        <div className="mv-modal-grid">
                            {movement.reason && <Item label="Reason" value={movement.reason} wide />}
                            {movement.notes && <Item label="Notes" value={movement.notes} wide />}
                        </div>
                    )}
                </div>

                <div className="mv-modal-footer">
                    <button type="button" className="btn btn-light" onClick={onClose}>
                        Close
                    </button>
                </div>
            </div>
        </div>,
        document.body,
    );
}

function Item({ label, value, wide }: { label: string; value: string | null | undefined; wide?: boolean }) {
    return (
        <div className="mv-modal-item" style={wide ? { gridColumn: '1 / -1' } : undefined}>
            <span className="mv-modal-item-label">{label}</span>
            <span className="mv-modal-item-val" style={wide ? { whiteSpace: 'pre-wrap' } : undefined}>
                {value || '—'}
            </span>
        </div>
    );
}
