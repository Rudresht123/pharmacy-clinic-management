import { useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { customersHooks } from '@/core/customers/api';
import { useEntityLabel } from '@/core/field-settings/api';
import { useBookAppointment, useOpenSlots } from '../api';
import type { AppointmentType } from '../types';

/**
 * Booking somebody in, or recording a walk-in.
 *
 * One dialog for both, because they differ in exactly one thing: a booking
 * names a time and a walk-in does not. Two dialogs would duplicate the
 * patient search, which is the part that actually takes any work.
 */
export function BookDialog({
    open,
    onClose,
    doctorId,
    locationId,
    date,
    doctorName,
}: {
    open: boolean;
    onClose: () => void;
    doctorId: number | '';
    locationId: number | '';
    date: string;
    doctorName: string;
}) {
    const label = useEntityLabel('customer');
    const book = useBookAppointment();

    const [type, setType] = useState<AppointmentType>('booked');
    const [customerId, setCustomerId] = useState<number | ''>('');
    const [slot, setSlot] = useState('');
    const [notes, setNotes] = useState('');
    const [search, setSearch] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    const { data: sessions } = useOpenSlots(doctorId, locationId, date);

    // Searched rather than listed: a clinic's book of patients is long, and
    // the desk already knows who is standing there.
    const { data: matches } = customersHooks.useList(
        search.length >= 2 ? { search, per_page: 10 } : undefined,
        { enabled: search.length >= 2 },
    );

    function reset() {
        setType('booked');
        setCustomerId('');
        setSlot('');
        setNotes('');
        setSearch('');
        setErrors({});
    }

    async function onSubmit() {
        setErrors({});

        try {
            await book.mutateAsync({
                customer_id: customerId,
                doctor_id: doctorId,
                location_id: locationId,
                appointment_date: date,
                type,
                // A walk-in has no promised time — sending one would be
                // claiming something untrue, and the server refuses it.
                slot_at: type === 'booked' ? slot : null,
                notes: notes.trim() || null,
            });

            reset();
            onClose();
        } catch (error) {
            const validation = getValidationErrors(error);

            if (validation) {
                setErrors(
                    Object.fromEntries(
                        Object.entries(validation).map(([key, value]) => [key, value[0]]),
                    ),
                );

                return;
            }

            // A refusal the desk can act on — the slot went, or the doctor is
            // not sitting — arrives as a plain message, not a field error.
            notify.error(resolveErrorMessage(error));
        }
    }

    const chosen = (matches ?? []).find((customer) => customer.id === customerId);

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={type === 'walk_in' ? 'Walk-in' : 'Book an appointment'}
            subtitle={`${doctorName} · ${date}`}
            size="md"
            icon={<i className="ti ti-calendar-plus" />}
            footer={
                <>
                    <Button variant="light" onClick={onClose}>
                        Cancel
                    </Button>
                    <Button
                        loading={book.isPending}
                        disabled={!customerId || (type === 'booked' && !slot)}
                        onClick={onSubmit}
                        icon="ti ti-check"
                    >
                        {type === 'walk_in' ? 'Issue a token' : 'Book'}
                    </Button>
                </>
            }
        >
            <div className="bk">
                <div className="bk-types">
                    <button
                        type="button"
                        className={`bk-type${type === 'booked' ? ' is-on' : ''}`}
                        onClick={() => setType('booked')}
                    >
                        <i className="ti ti-clock" />
                        <b>Booked</b>
                        <small>Give them a time.</small>
                    </button>

                    <button
                        type="button"
                        className={`bk-type${type === 'walk_in' ? ' is-on' : ''}`}
                        onClick={() => setType('walk_in')}
                    >
                        <i className="ti ti-walk" />
                        <b>Walk-in</b>
                        <small>They are here now — a token is issued at once.</small>
                    </button>
                </div>

                <label className="bk-field">
                    <span>{label.singular || 'Patient'}</span>

                    {chosen ? (
                        <div className="bk-chosen">
                            <b>{chosen.name}</b>
                            {chosen.phone && <code>{chosen.phone}</code>}
                            <button type="button" onClick={() => setCustomerId('')}>
                                <i className="ti ti-x" />
                            </button>
                        </div>
                    ) : (
                        <>
                            <input
                                type="search"
                                className="form-control"
                                placeholder="Search by name or phone…"
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />

                            {search.length >= 2 && (
                                <div className="bk-matches">
                                    {(matches ?? []).length === 0 ? (
                                        <p>Nobody found. Add them first.</p>
                                    ) : (
                                        (matches ?? []).map((customer) => (
                                            <button
                                                type="button"
                                                key={customer.id}
                                                onClick={() => setCustomerId(customer.id)}
                                            >
                                                <b>{customer.name}</b>
                                                {customer.phone && <code>{customer.phone}</code>}
                                            </button>
                                        ))
                                    )}
                                </div>
                            )}
                        </>
                    )}

                    {errors.customer_id && <em>{errors.customer_id}</em>}
                </label>

                {type === 'booked' && (
                    <div className="bk-field">
                        <span>Time</span>

                        {(sessions ?? []).length === 0 ? (
                            <p className="bk-none">
                                This doctor is not sitting here on this date — or the day is already
                                taken.
                            </p>
                        ) : (
                            (sessions ?? []).map((session, index) => (
                                <div className="bk-session" key={index}>
                                    <p className="bk-session-head">
                                        {session.starts_at}–{session.ends_at}
                                        {session.name && <span>{session.name}</span>}
                                        {session.changed && (
                                            <em>{session.reason || 'changed for this date'}</em>
                                        )}
                                    </p>

                                    <div className="bk-slots">
                                        {session.slots.map((time) => (
                                            <button
                                                type="button"
                                                key={time}
                                                className={`bk-slot${slot === time ? ' is-on' : ''}`}
                                                onClick={() => setSlot(time)}
                                            >
                                                {time}
                                            </button>
                                        ))}

                                        {/* Shown, not hidden: "why can't I have
                                            10:30" is answered on the spot. */}
                                        {session.taken.map((time) => (
                                            <span className="bk-slot is-taken" key={time}>
                                                {time}
                                            </span>
                                        ))}
                                    </div>
                                </div>
                            ))
                        )}

                        {errors.slot_at && <em className="bk-error">{errors.slot_at}</em>}
                    </div>
                )}

                <label className="bk-field">
                    <span>Note</span>
                    <input
                        type="text"
                        className="form-control"
                        placeholder="Anything the doctor should know"
                        maxLength={1000}
                        value={notes}
                        onChange={(event) => setNotes(event.target.value)}
                    />
                </label>
            </div>
        </Modal>
    );
}
