import { useEffect, useMemo, useState } from 'react';
import { useLocation, useNavigate } from 'react-router-dom';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useDebounce } from '@/shared/hooks/useDebounce';
import { notify } from '@/shared/utils/notify';
import { customersHooks } from '@/core/customers/api';
import { useAvailabilityDay } from '@/core/availability/api';
import { useEntityLabel } from '@/core/field-settings/api';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import type { Customer } from '@/core/customers/types';
import { useBookAppointment, useOpenSlots } from '../api';
import type { AppointmentType } from '../types';

/** "34 years", or nothing when there is no date of birth behind it. */
function describe(dob: string | null | undefined): string | null {
    if (!dob) {
        return null;
    }

    const born = new Date(dob);
    const now = new Date();
    let years = now.getFullYear() - born.getFullYear();
    const month = now.getMonth() - born.getMonth();

    if (month < 0 || (month === 0 && now.getDate() < born.getDate())) {
        years -= 1;
    }

    return years >= 0 && years < 150 ? `${years} years` : null;
}

/** Which question the dialog is on. */
type Step = 'who' | 'when';

/**
 * Booking somebody in, or recording a walk-in.
 *
 * One dialog for both, because they differ in exactly one thing: a booking
 * names a time and a walk-in does not. Two dialogs would duplicate the patient
 * search, which is the part that actually takes any work.
 *
 * Three deliberate departures from the version this replaces:
 *
 *  1. **Registering somebody new is one click away and comes back here.** It
 *     used to say "Nobody found. Add them first." and stop, leaving the
 *     receptionist to find the patient screen, fill it in, and start the
 *     booking again from nothing. The dialog now hands over to the real
 *     registration form — carrying whatever was typed into the search box —
 *     and that form returns with the new patient already chosen.
 *
 *     A four-field version of the form briefly lived inside this dialog. It
 *     was faster and it was wrong: an organization that had marked a field
 *     mandatory got a record without it, because the mini-form knew nothing
 *     about the configured schema.
 *  2. **The doctor is chosen here**, from the ones actually sitting at this
 *     branch on this date. The queue is branch-wide now, so the dialog can no
 *     longer inherit a doctor from it — and picking from those on the day is a
 *     better question than picking from every doctor on the books.
 *  3. **Search is debounced.** The key used to be the raw input, so a
 *     ten-letter name was eight requests.
 */
export function BookDialog({
    open,
    onClose,
    locationId,
    date,
    branchName,
    defaultType = 'walk_in',
    preselect,
}: {
    open: boolean;
    onClose: () => void;
    locationId: number | '';
    date: string;
    branchName: string;
    /**
     * Which kind the dialog opens on.
     *
     * The desk has two separate intentions — somebody standing there now, and
     * somebody ringing to book a time — and a screen offering both from one
     * button makes the commoner of the two cost an extra click. Still
     * switchable inside, because the intention changes mid-conversation more
     * often than anybody expects.
     */
    defaultType?: AppointmentType;
    /**
     * Somebody just registered on the patient form, coming back to be booked.
     *
     * The dialog opens straight on the time and doctor with them chosen: the
     * desk already answered "who is it for" by filling in a whole form, and
     * asking again would be the round trip this exists to remove.
     */
    preselect?: number | null;
}) {
    const label = useEntityLabel('customer');
    const { can } = useTenantAuth();
    const navigate = useNavigate();
    const { pathname } = useLocation();

    const book = useBookAppointment();

    const [step, setStep] = useState<Step>('who');
    const [type, setType] = useState<AppointmentType>(defaultType);
    const [patient, setPatient] = useState<Customer | null>(null);
    const [doctorId, setDoctorId] = useState<number | ''>('');
    const [slot, setSlot] = useState('');
    const [notes, setNotes] = useState('');
    const [search, setSearch] = useState('');
    const [errors, setErrors] = useState<Record<string, string>>({});

    // One request per pause, not one per keystroke.
    const term = useDebounce(search, 350);

    const { data: matches, isFetching: searching } = customersHooks.useList(
        term.trim().length >= 2 ? { search: term.trim(), per_page: 8 } : undefined,
        { enabled: term.trim().length >= 2 },
    );

    /*
     * Only the doctors actually sitting here on this date — leave and changed
     * hours already applied by the server. Offering somebody who is not in
     * today just moves the refusal to the submit button.
     */
    const { data: day, isLoading: dayLoading } = useAvailabilityDay(date, locationId);
    const doctors = useMemo(() => day?.doctors ?? [], [day]);

    const { data: sessions } = useOpenSlots(doctorId, locationId, date);

    // Whoever the registration form just created, on the way back.
    const { data: returned } = customersHooks.useDetail(preselect ? String(preselect) : undefined);

    useEffect(() => {
        if (open && returned) {
            setPatient(returned);
            setStep('when');
        }
    }, [open, returned]);

    function reset() {
        setStep('who');
        setType(defaultType);
        setPatient(null);
        setDoctorId('');
        setSlot('');
        setNotes('');
        setSearch('');
        setErrors({});
    }

    // A fresh dialog every time. Reusing the last booking's patient is the
    // kind of memory that books somebody in for the wrong person.
    useEffect(() => {
        if (open) {
            reset();
        }
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [open, defaultType]);

    // Only one doctor is in today: choosing is not a decision, so make it.
    useEffect(() => {
        if (doctorId === '' && doctors.length === 1) {
            setDoctorId(doctors[0].doctor_id);
        }
    }, [doctors, doctorId]);

    function choose(found: Customer) {
        setPatient(found);
        setErrors({});
        setStep('when');
    }

    /**
     * Hand over to the real registration form, and come back here.
     *
     * Whatever was typed into the search goes with it — a name or a phone
     * number, whichever it looks like — so nobody types the same thing twice.
     * The `return` tells that form where to come back to, and it appends the
     * new patient's id so this dialog can reopen with them already chosen.
     */
    function registerElsewhere() {
        const typed = search.trim();
        const isPhone = /^[\d\s+()-]{6,}$/.test(typed);

        const to = new URLSearchParams({
            return: `${pathname}${window.location.search}`,
        });

        if (isPhone) {
            to.set('phone', typed);
        } else if (typed) {
            to.set('name', typed);
        }

        onClose();
        navigate(`/customers/create?${to.toString()}`);
    }

    async function onSubmit() {
        setErrors({});

        try {
            await book.mutateAsync({
                customer_id: patient?.id ?? '',
                doctor_id: doctorId,
                location_id: locationId,
                appointment_date: date,
                type,
                // A walk-in has no promised time — sending one would be
                // claiming something untrue, and the server refuses it.
                slot_at: type === 'booked' ? slot : null,
                notes: notes.trim() || null,
            });

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

    const found = matches ?? [];
    const canRegister = can('customers.create');

    /* ---- footer, which differs per step ------------------------------- */

    const footer =
        step === 'who' ? (
            <Button variant="light" onClick={onClose}>
                Cancel
            </Button>
        ) : (
            <>
                <Button variant="light" onClick={() => setStep('who')}>
                    Back
                </Button>
                <Button
                    loading={book.isPending}
                    disabled={!doctorId || (type === 'booked' && !slot)}
                    onClick={onSubmit}
                    icon="ti ti-check"
                >
                    {type === 'walk_in' ? 'Issue a token' : 'Book'}
                </Button>
            </>
        );

    return (
        <Modal
            open={open}
            onClose={onClose}
            title={
                step === 'who'
                    ? 'Who is it for?'
                    : type === 'walk_in'
                      ? 'Walk-in'
                      : 'Book an appointment'
            }
            subtitle={`${branchName} · ${date}`}
            icon={<i className="ti ti-calendar-plus" />}
            footer={footer}
            /*
             * A side panel, not a centred box.
             *
             * Taking somebody at the desk is done alongside the queue, not
             * instead of it: the receptionist is reading the list while they
             * type — which token is next, whether this doctor is already
             * running late. A centred dialog covers the one thing being worked
             * from.
             */
            placement="side"
        >
            <div className="bk">
                {/*
                    Two steps, named and numbered.

                    Without them the dialog's Back button was the only sign
                    there was more than one screen, and somebody who reached
                    the second could not tell whether they were nearly done or
                    halfway into something long.
                */}
                <ol className="bk-steps" aria-label="Steps">
                    <li className={step === 'who' ? 'is-on' : 'is-done'}>
                        <span aria-hidden="true">{step === 'who' ? '1' : <i className="ti ti-check" />}</span>
                        {label.singular}
                    </li>
                    <li className={step === 'when' ? 'is-on' : undefined}>
                        <span aria-hidden="true">2</span>
                        Visit details
                    </li>
                </ol>

                {/* ---------------------------------------- step 1: who */}
                {step === 'who' && (
                    <>
                        <div className="bk-search">
                            <i className="ti ti-search" aria-hidden="true" />
                            <input
                                type="search"
                                placeholder={`Search by name, mobile, or ${label.singular.toLowerCase()} ID…`}
                                aria-label={`Search ${label.plural.toLowerCase()}`}
                                autoFocus
                                value={search}
                                onChange={(event) => setSearch(event.target.value)}
                            />
                            {searching && (
                                <i className="ti ti-loader-2 is-spinning" aria-hidden="true" />
                            )}
                        </div>

                        {search.trim().length < 2 ? (
                            <div className="bk-prompt">
                                <i className="ti ti-search" aria-hidden="true" />
                                <b>Start typing a name or number</b>
                                <small>
                                    Two letters is enough. A clinic keeps a long book of{' '}
                                    {label.plural.toLowerCase()}, so nothing is listed until you
                                    narrow it.
                                </small>
                            </div>
                        ) : searching && found.length === 0 ? (
                            <div className="bk-prompt">
                                <i className="ti ti-loader-2 is-spinning" aria-hidden="true" />
                                <b>Searching…</b>
                            </div>
                        ) : found.length === 0 ? (
                            <div className="bk-prompt">
                                <i className="ti ti-user-question" aria-hidden="true" />
                                <b>Nobody matches “{search.trim()}”</b>
                                <small>
                                    {canRegister
                                        ? 'Register them below — you will come back here with them already chosen.'
                                        : `Ask somebody who can add ${label.plural.toLowerCase()} to register them.`}
                                </small>
                            </div>
                        ) : (
                            <div className="bk-matches">
                                {found.map((customer) => (
                                    <button
                                        type="button"
                                        key={customer.id}
                                        className="bk-hit"
                                        onClick={() => choose(customer)}
                                    >
                                        <span className="bk-face" aria-hidden="true">
                                            {(customer.name ?? '?').charAt(0)}
                                        </span>

                                        <span className="bk-hit-text">
                                            <b>{customer.name}</b>

                                            {/* Enough to be sure it is the right
                                                person before booking somebody
                                                else's appointment. */}
                                            <small>
                                                {[
                                                    customer.code,
                                                    describe(customer.date_of_birth),
                                                    customer.gender
                                                        ? customer.gender.charAt(0).toUpperCase() +
                                                          customer.gender.slice(1)
                                                        : null,
                                                ]
                                                    .filter(Boolean)
                                                    .join(' · ')}
                                            </small>

                                            {customer.phone && (
                                                <small className="bk-hit-phone">
                                                    <i className="ti ti-phone" aria-hidden="true" />
                                                    {customer.phone}
                                                </small>
                                            )}
                                        </span>

                                        <i className="ti ti-arrow-right" aria-hidden="true" />
                                    </button>
                                ))}
                            </div>
                        )}

                        {/*
                            The way out of the dead end. Offered as soon as
                            there is something to name them by, not only after
                            a fruitless search, because the receptionist
                            usually already knows they are new.
                        */}
                        {canRegister && search.trim().length >= 2 && (
                            <button
                                type="button"
                                className="bk-register"
                                onClick={registerElsewhere}
                            >
                                <i className="ti ti-user-plus" />
                                <span>
                                    <b>Register “{search.trim()}”</b>
                                    <small>
                                        Opens the registration form, and comes back here with them
                                        chosen.
                                    </small>
                                </span>
                                <i className="ti ti-arrow-right" />
                            </button>
                        )}
                    </>
                )}

                {/* --------------------------------------- step 3: when */}
                {step === 'when' && (
                    <>
                        {patient && (
                            <div className="bk-chosen">
                                <span className="bk-face" aria-hidden="true">
                                    {(patient.name ?? '?').charAt(0)}
                                </span>

                                <span className="bk-hit-text">
                                    <b>{patient.name}</b>
                                    <small>
                                        {[
                                            patient.code,
                                            describe(patient.date_of_birth),
                                            patient.phone,
                                        ]
                                            .filter(Boolean)
                                            .join(' · ')}
                                    </small>
                                </span>

                                {/* A word, not an ×. Clearing the person you
                                    just chose is not what the button does — it
                                    goes back to the list to choose again. */}
                                <button
                                    type="button"
                                    className="bk-change"
                                    onClick={() => setStep('who')}
                                >
                                    Change
                                </button>
                            </div>
                        )}

                        <p className="bk-label">Kind of visit</p>

                        <div className="bk-types">
                            <button
                                type="button"
                                className={`bk-type${type === 'walk_in' ? ' is-on' : ''}`}
                                onClick={() => setType('walk_in')}
                            >
                                <i className="ti ti-walk" />
                                <b>Walk-in</b>
                                <small>They are here now — a token is issued at once.</small>
                            </button>

                            <button
                                type="button"
                                className={`bk-type${type === 'booked' ? ' is-on' : ''}`}
                                onClick={() => setType('booked')}
                            >
                                <i className="ti ti-clock" />
                                <b>Booked</b>
                                <small>Give them a time.</small>
                            </button>
                        </div>

                        <div className="bk-group">
                            <p className="bk-label">
                                Doctor <em>required</em>
                            </p>

                            {dayLoading ? (
                                <div className="bk-prompt">
                                    <i className="ti ti-loader-2 is-spinning" aria-hidden="true" />
                                    <b>Loading who is sitting today…</b>
                                </div>
                            ) : doctors.length === 0 ? (
                                <div className="bk-prompt">
                                    <i className="ti ti-calendar-off" aria-hidden="true" />
                                    <b>Nobody is sitting here on this date</b>
                                    <small>
                                        Set their timings under Availability, and they will appear
                                        here.
                                    </small>
                                </div>
                            ) : (
                                /*
                                 * Cards rather than a dropdown.
                                 *
                                 * A branch runs three or four doctors on a given
                                 * day, and choosing between them is the decision
                                 * this step is FOR — a select hides all of it
                                 * behind one line until you open it, and hides
                                 * the speciality that usually decides the answer.
                                 */
                                <div className="bk-docs">
                                    {doctors.map((doctor) => (
                                        <button
                                            type="button"
                                            key={doctor.doctor_id}
                                            className={`bk-doc${doctorId === doctor.doctor_id ? ' is-on' : ''}`}
                                            aria-pressed={doctorId === doctor.doctor_id}
                                            onClick={() => {
                                                setDoctorId(doctor.doctor_id);
                                                setSlot('');
                                            }}
                                        >
                                            <span className="bk-face" aria-hidden="true">
                                                {doctor.doctor_name
                                                    .replace(/^Dr\.?\s*/i, '')
                                                    .charAt(0)}
                                            </span>

                                            <span className="bk-hit-text">
                                                <b>{doctor.doctor_name}</b>
                                                <small>
                                                    {doctor.specialisation ?? 'General'}
                                                    {doctor.sessions.length > 0
                                                        ? ` · ${doctor.sessions[0].starts_at}–${doctor.sessions[doctor.sessions.length - 1].ends_at}`
                                                        : ''}
                                                </small>
                                            </span>

                                            {/*
                                                How much of their day is left.

                                                The hours alone say a doctor sits
                                                9–1, which is not the question —
                                                the desk is choosing somebody who
                                                can take this patient, and a full
                                                list and an empty one look
                                                identical without a count.
                                            */}
                                            <span
                                                className={`bk-free${
                                                    doctor.open_slots === 0 ? ' is-full' : ''
                                                }`}
                                            >
                                                {doctor.open_slots === 0 ? (
                                                    'Full'
                                                ) : (
                                                    <>
                                                        <b>{doctor.open_slots}</b> free
                                                    </>
                                                )}
                                            </span>

                                            {doctorId === doctor.doctor_id && (
                                                <i className="ti ti-circle-check-filled" aria-hidden="true" />
                                            )}
                                        </button>
                                    ))}
                                </div>
                            )}

                            {errors.doctor_id && <em className="bk-error">{errors.doctor_id}</em>}
                        </div>

                        {type === 'booked' && doctorId !== '' && (
                            <div className="bk-group">
                                <p className="bk-label">
                                    Time <em>required</em>
                                </p>

                                {(sessions ?? []).length === 0 ? (
                                    <div className="bk-prompt">
                                        <i className="ti ti-clock-off" aria-hidden="true" />
                                        <b>No times left</b>
                                        <small>
                                            This doctor is not sitting here on this date, or every
                                            slot has gone.
                                        </small>
                                    </div>
                                ) : (
                                    (sessions ?? []).map((session, index) => (
                                        <div className="bk-session" key={index}>
                                            <p className="bk-session-head">
                                                {session.starts_at}–{session.ends_at}
                                                {session.name && <span>{session.name}</span>}
                                                {session.changed && (
                                                    <em>
                                                        {session.reason || 'changed for this date'}
                                                    </em>
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

                                                {/* Shown, not hidden: "why can't
                                                    I have 10:30" is answered on
                                                    the spot. */}
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
                    </>
                )}
            </div>
        </Modal>
    );
}
