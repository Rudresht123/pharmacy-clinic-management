import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useSaveConsultation } from '../api';
import type {
    Consultation,
    InvestigationLine,
    PastConsultation,
    PrescriptionLine,
    Vitals,
} from '../types';

/** Held by the card that owns the panel, so other things can open a tab. */
export type ConsultationTab = 'clinical' | 'history' | 'vitals' | 'documents';

type Tab = ConsultationTab;

/** What was measured, and the units nobody should have to remember. */
const VITALS: [keyof Vitals, string, string][] = [
    ['bp_systolic', 'BP systolic', 'mmHg'],
    ['bp_diastolic', 'BP diastolic', 'mmHg'],
    ['pulse', 'Pulse', '/min'],
    ['temperature', 'Temperature', '°C'],
    ['spo2', 'SpO₂', '%'],
    ['weight', 'Weight', 'kg'],
    ['height', 'Height', 'cm'],
];

/**
 * Three rows so the layout can be judged, marked as examples.
 *
 * Deliberately generic — a plausible-looking result against a real patient's
 * name is the one thing a placeholder in a medical record must never be.
 */
const SAMPLE_DOCUMENTS = [
    {
        name: 'Blood report.pdf',
        size: '248 KB',
        on: '12 Aug 2026',
        by: 'Front desk',
        icon: 'ti ti-file-type-pdf',
        tone: 'rose',
    },
    {
        name: 'Chest X-ray.jpg',
        size: '1.4 MB',
        on: '12 Aug 2026',
        by: 'Radiology',
        icon: 'ti ti-photo',
        tone: 'blue',
    },
    {
        name: 'Referral letter.pdf',
        size: '96 KB',
        on: '2 Jul 2026',
        by: 'Dr. Neha Singh',
        icon: 'ti ti-file-description',
        tone: 'amber',
    },
] as const;

/** "18 Sep 2026" */
function longDate(value: string | null): string {
    if (!value) return '—';

    const [year, month, day] = value.split('-').map(Number);

    return new Date(year, month - 1, day).toLocaleDateString(undefined, {
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/** "Tue, 16 Sep 2026" — the date a follow-up in N days actually lands on. */
function dayAfter(days: number): string {
    const at = new Date();

    at.setDate(at.getDate() + days);

    return at.toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
    });
}

/**
 * A doctor's own recent wording, offered rather than imposed.
 *
 * Hidden entirely when there is nothing to offer — a dropdown that opens on an
 * empty list is a promise the screen cannot keep, and on a doctor's first day
 * there is nothing to suggest.
 */
function Suggestions({
    label,
    icon,
    options,
    onPick,
}: {
    label: string;
    icon: string;
    options: string[];
    onPick: (value: string) => void;
}) {
    const [open, setOpen] = useState(false);

    if (options.length === 0) {
        return <span />;
    }

    return (
        <div className="cn-sug">
            <button
                type="button"
                className="cn-sug-open"
                aria-expanded={open}
                onClick={() => setOpen((was) => !was)}
            >
                <i className={icon} aria-hidden="true" />
                {label}
                <i className="ti ti-chevron-down" aria-hidden="true" />
            </button>

            {open && (
                <>
                    {/* Click anywhere to dismiss, without a listener on document. */}
                    <button
                        type="button"
                        className="cn-sug-veil"
                        aria-label="Close"
                        onClick={() => setOpen(false)}
                    />

                    <ul className="cn-sug-list">
                        {options.map((entry) => (
                            <li key={entry}>
                                <button
                                    type="button"
                                    onClick={() => {
                                        onPick(entry);
                                        setOpen(false);
                                    }}
                                >
                                    {entry}
                                </button>
                            </li>
                        ))}
                    </ul>
                </>
            )}
        </div>
    );
}

/**
 * Writing up the visit in front of you.
 *
 * Held as local state and saved on demand rather than per keystroke: a
 * consultation is written in one sitting, and a field that saved itself
 * mid-sentence would fill the record with half-typed diagnoses.
 *
 * The whole consultation goes up on every save — it is one row, and sending
 * only what changed would mean the screen deciding what "changed" means for a
 * list of prescription lines that were reordered.
 */
export function ConsultationPanel({
    appointmentId,
    saved,
    history,
    suggestions,
    onComplete,
    completing,
    tab,
    onTab,
}: {
    appointmentId: number;
    saved: Consultation;
    history: PastConsultation[];
    /** This doctor's own recent wording, most used first. */
    suggestions: { complaints: string[]; diagnoses: string[] };
    /** Ends the visit. Lives here so it sits beside Save rather than under it. */
    onComplete: () => void;
    completing: boolean;
    /** Held by the card, so the patient panel can open History. */
    tab: Tab;
    onTab: (tab: Tab) => void;
}) {
    const save = useSaveConsultation(appointmentId);

    const [draft, setDraft] = useState<Consultation>(saved);
    const [errors, setErrors] = useState<Record<string, string[]>>({});
    const [diagnosis, setDiagnosis] = useState('');

    /*
     * Which of the two list editors is open, if either.
     *
     * Closed by default: a prescription is two or three lines and reads as one
     * fact — "2 medicines" — until somebody is actually changing it, and two
     * open tables would push the buttons off the card.
     */
    const [open, setOpen] = useState<'prescription' | 'investigations' | null>(null);

    /*
     * The server's copy wins when the patient changes.
     *
     * Keyed on the appointment rather than on the object, or every poll of the
     * day would throw away whatever the doctor was typing.
     */
    useEffect(() => {
        setDraft(saved);
        setErrors({});
        setDiagnosis('');
        setOpen(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [appointmentId]);

    function patch(next: Partial<Consultation>) {
        setDraft((was) => ({ ...was, ...next }));
    }

    function addDiagnosis() {
        const value = diagnosis.trim();

        if (!value || draft.diagnoses.includes(value)) {
            setDiagnosis('');

            return;
        }

        patch({ diagnoses: [...draft.diagnoses, value] });
        setDiagnosis('');
    }

    function submit() {
        setErrors({});

        save.mutate(draft, {
            onError: (error) => {
                const found = getValidationErrors(error);

                if (found) setErrors(found);
                else notify.error(resolveErrorMessage(error));
            },
        });
    }

    const line = (key: string) => errors[key]?.[0];

    /*
     * The underline slides between tabs rather than jumping.
     *
     * Measured from the active button rather than guessed from its index: the
     * tabs are different widths — "History (5)" is not "Vitals" — and a bar
     * positioned by arithmetic would sit under the wrong one the moment a
     * count appeared.
     */
    const tabs = useRef<HTMLElement>(null);
    const [ink, setInk] = useState({ left: 0, width: 0 });

    useLayoutEffect(() => {
        const active = tabs.current?.querySelector<HTMLElement>('.cn-tab.is-on');

        if (active) {
            setInk({ left: active.offsetLeft, width: active.offsetWidth });
        }
    }, [tab, history.length]);

    return (
        <div className="cn">
            <nav className="cn-tabs" role="tablist" ref={tabs}>
                {(
                    [
                        ['clinical', 'Clinical', 'ti ti-stethoscope'],
                        [
                            'history',
                            `History${history.length ? ` (${history.length})` : ''}`,
                            'ti ti-history',
                        ],
                        ['vitals', 'Vitals', 'ti ti-activity-heartbeat'],
                        ['documents', 'Documents', 'ti ti-file'],
                    ] as [Tab, string, string][]
                ).map(([key, label, icon]) => (
                    <button
                        type="button"
                        key={key}
                        role="tab"
                        aria-selected={tab === key}
                        className={`cn-tab${tab === key ? ' is-on' : ''}`}
                        onClick={() => onTab(key)}
                    >
                        <i className={icon} aria-hidden="true" />
                        {label}
                    </button>
                ))}

                <span
                    className="cn-ink"
                    style={{ left: ink.left, width: ink.width }}
                    aria-hidden="true"
                />
            </nav>

            {/*
                Keyed on the tab, so React replaces the subtree and the
                animation runs on the way in. Without the key it would be the
                same element with different children, and nothing would move.
            */}
            <div className="cn-swap" key={tab}>
            {tab === 'clinical' && (
                <div className="cn-rows">
                    <div className="cn-row">
                        <i className="cn-icon is-rose ti ti-file-description" aria-hidden="true" />
                        <span className="cn-label">Chief complaint</span>

                        <div className="cn-value">
                            <input
                                type="text"
                                className={`form-control${line('chief_complaint') ? ' is-invalid' : ''}`}
                                placeholder="Chest pain since 2 days"
                                value={draft.chief_complaint ?? ''}
                                onChange={(event) => patch({ chief_complaint: event.target.value })}
                            />
                            {line('chief_complaint') && <em>{line('chief_complaint')}</em>}
                        </div>

                        {/*
                            Their own recent wording, not a catalogue.
                            A complaint is typed dozens of times a week and
                            spelled differently each time, which is what makes a
                            record nobody can search later.
                        */}
                        <Suggestions
                            label="Common"
                            icon="ti ti-bolt"
                            options={suggestions.complaints}
                            onPick={(value) => patch({ chief_complaint: value })}
                        />
                    </div>

                    <div className="cn-row">
                        <i className="cn-icon is-violet ti ti-circle-plus" aria-hidden="true" />
                        <span className="cn-label">Provisional diagnosis</span>

                        {/*
                            Chips, because it is usually more than one and each
                            is a whole thing rather than a phrase in a sentence.
                            A comma-separated line reads back as one diagnosis
                            with commas in it.
                        */}
                        <div className="cn-value cn-chips">
                            {draft.diagnoses.map((entry) => (
                                <span className="cn-chip" key={entry}>
                                    {entry}
                                    <button
                                        type="button"
                                        aria-label={`Remove ${entry}`}
                                        onClick={() =>
                                            patch({
                                                diagnoses: draft.diagnoses.filter(
                                                    (other) => other !== entry,
                                                ),
                                            })
                                        }
                                    >
                                        <i className="ti ti-x" aria-hidden="true" />
                                    </button>
                                </span>
                            ))}

                            <input
                                type="text"
                                className="cn-add"
                                placeholder="Add diagnosis…"
                                value={diagnosis}
                                onChange={(event) => setDiagnosis(event.target.value)}
                                onKeyDown={(event) => {
                                    if (event.key === 'Enter' || event.key === ',') {
                                        event.preventDefault();
                                        addDiagnosis();
                                    }
                                }}
                                onBlur={addDiagnosis}
                            />
                        </div>

                        <Suggestions
                            label="Recent"
                            icon="ti ti-clock"
                            options={suggestions.diagnoses.filter(
                                (entry) => !draft.diagnoses.includes(entry),
                            )}
                            onPick={(value) =>
                                patch({ diagnoses: [...draft.diagnoses, value] })
                            }
                        />
                    </div>

                    {/*
                        Summarised until somebody is changing it. "2 medicines"
                        is what a doctor needs while reading; the table is what
                        they need while prescribing, and only then.
                    */}
                    <div className="cn-row">
                        <i className="cn-icon is-green ti ti-pill" aria-hidden="true" />
                        <span className="cn-label">Prescription</span>

                        <div className="cn-value cn-summary">
                            {draft.prescription.length === 0
                                ? 'Nothing prescribed yet'
                                : `${draft.prescription.length} ${
                                      draft.prescription.length === 1 ? 'medicine' : 'medicines'
                                  } added`}
                        </div>

                        <button
                            type="button"
                            className="cn-plus"
                            onClick={() => setOpen(open === 'prescription' ? null : 'prescription')}
                        >
                            <i
                                className={open === 'prescription' ? 'ti ti-x' : 'ti ti-plus'}
                                aria-hidden="true"
                            />
                            {open === 'prescription' ? 'Close' : 'Add prescription'}
                        </button>
                    </div>

                    {open === 'prescription' && (
                        <div className="cn-open">
                            <table className="cn-lines">
                                <thead>
                                    <tr>
                                        <th>Medicine</th>
                                        <th>Dose</th>
                                        <th>How often</th>
                                        <th>For</th>
                                        <th aria-label="Remove" />
                                    </tr>
                                </thead>

                                <tbody>
                                    {draft.prescription.length === 0 ? (
                                        <tr>
                                            <td colSpan={5} className="cn-none">
                                                Nothing prescribed yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        draft.prescription.map((row, index) => (
                                            <tr key={index}>
                                                {(
                                                    [
                                                        ['drug', 'Amoxicillin 500mg'],
                                                        ['dose', '1 tablet'],
                                                        ['frequency', 'Twice a day'],
                                                        ['duration', '5 days'],
                                                    ] as [keyof PrescriptionLine, string][]
                                                ).map(([field, hint]) => (
                                                    <td key={field}>
                                                        <input
                                                            type="text"
                                                            className={`form-control${line(`prescription.${index}.${field}`) ? ' is-invalid' : ''}`}
                                                            placeholder={hint}
                                                            value={(row[field] as string) ?? ''}
                                                            onChange={(event) =>
                                                                patch({
                                                                    prescription:
                                                                        draft.prescription.map(
                                                                            (other, at) =>
                                                                                at === index
                                                                                    ? {
                                                                                          ...other,
                                                                                          [field]:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : other,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                ))}

                                                <td>
                                                    <button
                                                        type="button"
                                                        className="cn-drop"
                                                        aria-label="Remove this medicine"
                                                        onClick={() =>
                                                            patch({
                                                                prescription:
                                                                    draft.prescription.filter(
                                                                        (_, at) => at !== index,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        <i
                                                            className="ti ti-trash"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>

                            <button
                                type="button"
                                className="cn-more"
                                onClick={() =>
                                    patch({
                                        prescription: [
                                            ...draft.prescription,
                                            { drug: '', dose: '', frequency: '', duration: '' },
                                        ],
                                    })
                                }
                            >
                                <i className="ti ti-plus" aria-hidden="true" />
                                Add a medicine
                            </button>
                        </div>
                    )}

                    <div className="cn-row">
                        <i className="cn-icon is-amber ti ti-flask" aria-hidden="true" />
                        <span className="cn-label">Investigations</span>

                        <div className="cn-value cn-summary">
                            {draft.investigations.length === 0
                                ? 'Nothing ordered yet'
                                : `${draft.investigations.length} ${
                                      draft.investigations.length === 1
                                          ? 'investigation'
                                          : 'investigations'
                                  } added`}
                        </div>

                        <button
                            type="button"
                            className="cn-plus"
                            onClick={() =>
                                setOpen(open === 'investigations' ? null : 'investigations')
                            }
                        >
                            <i
                                className={open === 'investigations' ? 'ti ti-x' : 'ti ti-plus'}
                                aria-hidden="true"
                            />
                            {open === 'investigations' ? 'Close' : 'Add investigation'}
                        </button>
                    </div>

                    {open === 'investigations' && (
                        <div className="cn-open">
                            <table className="cn-lines">
                                <thead>
                                    <tr>
                                        <th>Test</th>
                                        <th>Note</th>
                                        <th aria-label="Remove" />
                                    </tr>
                                </thead>

                                <tbody>
                                    {draft.investigations.length === 0 ? (
                                        <tr>
                                            <td colSpan={3} className="cn-none">
                                                Nothing ordered yet.
                                            </td>
                                        </tr>
                                    ) : (
                                        draft.investigations.map((row, index) => (
                                            <tr key={index}>
                                                {(
                                                    [
                                                        ['test', 'Complete blood count'],
                                                        ['notes', 'Fasting'],
                                                    ] as [keyof InvestigationLine, string][]
                                                ).map(([field, hint]) => (
                                                    <td key={field}>
                                                        <input
                                                            type="text"
                                                            className={`form-control${line(`investigations.${index}.${field}`) ? ' is-invalid' : ''}`}
                                                            placeholder={hint}
                                                            value={(row[field] as string) ?? ''}
                                                            onChange={(event) =>
                                                                patch({
                                                                    investigations:
                                                                        draft.investigations.map(
                                                                            (other, at) =>
                                                                                at === index
                                                                                    ? {
                                                                                          ...other,
                                                                                          [field]:
                                                                                              event
                                                                                                  .target
                                                                                                  .value,
                                                                                      }
                                                                                    : other,
                                                                        ),
                                                                })
                                                            }
                                                        />
                                                    </td>
                                                ))}

                                                <td>
                                                    <button
                                                        type="button"
                                                        className="cn-drop"
                                                        aria-label="Remove this test"
                                                        onClick={() =>
                                                            patch({
                                                                investigations:
                                                                    draft.investigations.filter(
                                                                        (_, at) => at !== index,
                                                                    ),
                                                            })
                                                        }
                                                    >
                                                        <i
                                                            className="ti ti-trash"
                                                            aria-hidden="true"
                                                        />
                                                    </button>
                                                </td>
                                            </tr>
                                        ))
                                    )}
                                </tbody>
                            </table>

                            <button
                                type="button"
                                className="cn-more"
                                onClick={() =>
                                    patch({
                                        investigations: [
                                            ...draft.investigations,
                                            { test: '', notes: '' },
                                        ],
                                    })
                                }
                            >
                                <i className="ti ti-plus" aria-hidden="true" />
                                Order a test
                            </button>
                        </div>
                    )}

                    <div className="cn-row">
                        <i className="cn-icon is-blue ti ti-calendar-repeat" aria-hidden="true" />
                        <span className="cn-label">Follow-up</span>

                        <div className="cn-value cn-follow">
                            <span>After</span>
                            <input
                                type="number"
                                min={1}
                                max={3650}
                                className={`form-control${line('follow_up_days') ? ' is-invalid' : ''}`}
                                placeholder="7"
                                value={draft.follow_up_days ?? ''}
                                onChange={(event) =>
                                    patch({
                                        follow_up_days: event.target.value
                                            ? Number(event.target.value)
                                            : null,
                                    })
                                }
                            />
                            <span>days</span>
                            {line('follow_up_days') && <em>{line('follow_up_days')}</em>}
                        </div>

                        {/*
                            The date the advice actually lands on. "After 7
                            days" is what a doctor says; a date is what the
                            patient writes down, and working it out in your head
                            at the end of a clinic is where it goes wrong.
                        */}
                        {draft.follow_up_days ? (
                            <span className="cn-on">
                                <i className="ti ti-calendar" aria-hidden="true" />
                                {dayAfter(draft.follow_up_days)}
                            </span>
                        ) : (
                            <span />
                        )}
                    </div>

                    <div className="cn-row">
                        <i className="cn-icon is-teal ti ti-message-2" aria-hidden="true" />
                        <span className="cn-label">Advice</span>

                        <div className="cn-value">
                            <textarea
                                rows={2}
                                className="form-control"
                                placeholder="Rest, fluids, avoid exertion…"
                                value={draft.advice ?? ''}
                                onChange={(event) => patch({ advice: event.target.value })}
                            />
                        </div>

                        <span />
                    </div>

                    <div className="cn-row cn-row-notes">
                        <i className="cn-icon is-slate ti ti-notes" aria-hidden="true" />
                        <span className="cn-label">Additional notes</span>

                        <div className="cn-value">
                            <textarea
                                rows={2}
                                className="form-control"
                                placeholder="Anything else worth keeping…"
                                value={draft.notes ?? ''}
                                onChange={(event) => patch({ notes: event.target.value })}
                            />
                        </div>

                        <span />
                    </div>
                </div>
            )}

            {tab === 'history' && (
                <div className="cn-body">
                    {history.length === 0 ? (
                        <div className="cn-none-yet">
                            <i className="ti ti-history-off" aria-hidden="true" />
                            <b>No earlier visits</b>
                            <p>
                                Nothing has been written up for this patient before today. What
                                you record now becomes the first entry here.
                            </p>
                        </div>
                    ) : (
                        <ul className="cn-history">
                            {history.map((past) => (
                                <li key={past.id}>
                                    <div className="cn-when">
                                        <b>{longDate(past.on)}</b>
                                        <small>{past.doctor_name ?? 'Unknown doctor'}</small>
                                    </div>

                                    <div className="cn-what">
                                        {past.chief_complaint && <p>{past.chief_complaint}</p>}

                                        {past.diagnoses.length > 0 && (
                                            <span className="cn-dx">
                                                {past.diagnoses.map((entry) => (
                                                    <em key={entry}>{entry}</em>
                                                ))}
                                            </span>
                                        )}

                                        {!past.chief_complaint && past.diagnoses.length === 0 && (
                                            <p className="cn-quiet">Nothing written up.</p>
                                        )}
                                    </div>
                                </li>
                            ))}
                        </ul>
                    )}
                </div>
            )}

            {tab === 'vitals' && (
                <div className="cn-body cn-vitals">
                    {VITALS.map(([key, label, unit]) => (
                        <label className="cn-vital" key={key}>
                            <span>{label}</span>

                            <div className="cn-unit">
                                <input
                                    type="number"
                                    step="any"
                                    className={`form-control${line(`vitals.${key}`) ? ' is-invalid' : ''}`}
                                    value={draft.vitals[key] ?? ''}
                                    onChange={(event) =>
                                        patch({
                                            vitals: {
                                                ...draft.vitals,
                                                [key]: event.target.value
                                                    ? Number(event.target.value)
                                                    : null,
                                            },
                                        })
                                    }
                                />
                                <em>{unit}</em>
                            </div>

                            {line(`vitals.${key}`) && <b>{line(`vitals.${key}`)}</b>}
                        </label>
                    ))}
                </div>
            )}

            {tab === 'documents' && (
                <div className="cn-body">
                    {/*
                        The layout, with sample rows — not this patient's files.

                        Said at the top and repeated on every row, because a
                        list of medical documents is exactly the thing somebody
                        would act on without reading the heading. Nothing here
                        is stored; attaching files needs a store wired to a
                        visit, which is its own piece of work.
                    */}
                    <p className="cn-preview">
                        <i className="ti ti-info-circle" aria-hidden="true" />
                        A preview of how documents will look. These are examples, not{' '}
                        {'this patient\u2019s'} files — nothing is stored yet.
                    </p>

                    <ul className="cn-docs">
                        {SAMPLE_DOCUMENTS.map((doc) => (
                            <li key={doc.name}>
                                <i className={`cn-doc-kind is-${doc.tone} ${doc.icon}`} aria-hidden="true" />

                                <span className="cn-doc-what">
                                    <b>{doc.name}</b>
                                    <small>
                                        {doc.size} · {doc.on} · {doc.by}
                                    </small>
                                </span>

                                <em className="cn-doc-tag">Example</em>

                                <span className="cn-doc-acts">
                                    <button type="button" disabled aria-label="View — not available yet">
                                        <i className="ti ti-eye" aria-hidden="true" />
                                    </button>
                                    <button
                                        type="button"
                                        disabled
                                        aria-label="Download — not available yet"
                                    >
                                        <i className="ti ti-download" aria-hidden="true" />
                                    </button>
                                </span>
                            </li>
                        ))}
                    </ul>

                    <div className="cn-attach" aria-disabled="true">
                        <i className="ti ti-cloud-upload" aria-hidden="true" />
                        <b>Attach a scan or report</b>
                        <small>Available once the documents module ships.</small>
                    </div>
                </div>
            )}

            </div>

            <div className="cn-foot">
                {/*
                    Clearing is destructive and sits alone on the left, away
                    from the two buttons somebody reaches for by habit.
                */}
                <button
                    type="button"
                    className="cn-clear"
                    onClick={() =>
                        setDraft({
                            ...draft,
                            chief_complaint: null,
                            diagnoses: [],
                            prescription: [],
                            investigations: [],
                            advice: null,
                            notes: null,
                            follow_up_days: null,
                        })
                    }
                >
                    <i className="ti ti-trash" aria-hidden="true" />
                    Clear all
                </button>

                {/*
                    Save, then finish — in that order, left to right.
                    Completing does not write the notes up, and the button that
                    ends the visit should not be the easier of the two to reach.
                */}
                <div className="cn-acts">
                    <button
                        type="button"
                        className="cn-save"
                        disabled={save.isPending}
                        onClick={submit}
                    >
                        <i className="ti ti-device-floppy" aria-hidden="true" />
                        {save.isPending ? 'Saving…' : 'Save notes'}
                    </button>

                    <button
                        type="button"
                        className="cn-finish"
                        disabled={completing}
                        onClick={onComplete}
                    >
                        <i className="ti ti-check" aria-hidden="true" />
                        Complete consultation
                    </button>
                </div>
            </div>
        </div>
    );
}
