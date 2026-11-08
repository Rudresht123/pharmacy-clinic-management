import { useEffect, useLayoutEffect, useRef, useState } from 'react';
import { createPortal } from 'react-dom';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import type { InvestigationLine } from '@/core/opd/types';
import { LabStatus } from '../orders/status';
import type { LabOrder, LabOrderItem } from '../orders/types';
import { registerLabFlush, useOrderables, useSendToLab, useVisitLabOrders, type OrderLine } from './api';

/** A test the doctor is adding, not yet sent. */
interface Draft {
    key: string;
    lab_test_id: number | null;
    test_name: string;
    /** "CBC · Haematology · Whole blood", once picked from the catalogue. */
    meta: string | null;
    instructions: string;
    /** The typed consultation line this came from, so the server drops it once sent. */
    replaces: string | null;
}

let next = 0;

function draftOf(name = '', instructions = '', replaces: string | null = null): Draft {
    next += 1;

    return { key: `lab-draft-${next}`, lab_test_id: null, test_name: name, meta: null, instructions, replaces };
}

/** A sent line as the order's update sends it back, unchanged. */
const asLine = (item: LabOrderItem): OrderLine => ({
    id: item.id,
    test_name: item.test_name,
    lab_test_id: item.lab_test_id,
    instructions: item.instructions ?? null,
});

/**
 * One test's name: searched in the Test Catalogue as the doctor types, or kept
 * exactly as written when the catalogue does not have it. The same picker the
 * prescription uses for medicines, so the two read alike.
 */
function LabTestPicker({
    draft,
    invalid,
    onPick,
    onType,
    onClear,
}: {
    draft: Draft;
    invalid: boolean;
    onPick: (picked: { id: number; name: string; meta: string }) => void;
    onType: (name: string) => void;
    onClear: () => void;
}) {
    const [term, setTerm] = useState(draft.lab_test_id ? '' : draft.test_name);
    const [needle, setNeedle] = useState(term.trim());
    const [open, setOpen] = useState(false);
    const [active, setActive] = useState(0);
    const [box, setBox] = useState({ top: 0, left: 0, width: 0 });

    const input = useRef<HTMLInputElement>(null);
    const panel = useRef<HTMLUListElement>(null);

    // A request per pause in typing, not per keystroke.
    useEffect(() => {
        const timer = setTimeout(() => setNeedle(term.trim()), 200);

        return () => clearTimeout(timer);
    }, [term]);

    const searching = open && term.trim().length >= 2;
    const search = useOrderables(needle, searching && needle.length >= 2);
    const results = search.data ?? [];

    useLayoutEffect(() => {
        if (!open || !input.current) return;

        const rect = input.current.getBoundingClientRect();
        setBox({ top: rect.bottom + 4, left: rect.left, width: rect.width });
    }, [open, term]);

    useEffect(() => {
        if (!open) return;

        function away(event: MouseEvent) {
            const target = event.target as Node;

            if (!input.current?.contains(target) && !panel.current?.contains(target)) {
                setOpen(false);
            }
        }

        function shut(event: Event) {
            if (panel.current?.contains(event.target as Node)) return;

            setOpen(false);
        }

        document.addEventListener('mousedown', away);
        window.addEventListener('scroll', shut, true);
        window.addEventListener('resize', shut);

        return () => {
            document.removeEventListener('mousedown', away);
            window.removeEventListener('scroll', shut, true);
            window.removeEventListener('resize', shut);
        };
    }, [open]);

    function choose(index: number) {
        const test = results[index];

        if (test) {
            onPick({
                id: test.id,
                name: test.name,
                meta: [test.code, test.department_name, test.sample_type_name].filter(Boolean).join(' · '),
            });
            setTerm('');
        }

        // The last option keeps the typed words, which onType already holds.
        setOpen(false);
    }

    if (draft.lab_test_id) {
        return (
            <div className="lab-ord-test">
                <span className="lab-ord-name">
                    <b title={draft.test_name}>{draft.test_name}</b>
                    <button
                        type="button"
                        className="lab-ord-change"
                        aria-label="Choose a different test"
                        title="Choose a different test"
                        onClick={() => {
                            setTerm('');
                            onClear();
                        }}
                    >
                        <i className="ti ti-x" aria-hidden="true" />
                    </button>
                </span>
                {draft.meta && <small>{draft.meta}</small>}
            </div>
        );
    }

    return (
        <div className="rx-pick lab-ord-pick">
            <input
                ref={input}
                type="text"
                className={`form-control${invalid ? ' is-invalid' : ''}`}
                placeholder="Search tests, e.g. CBC"
                aria-label="Test"
                value={term}
                onFocus={() => setOpen(true)}
                onChange={(event) => {
                    setTerm(event.target.value);
                    onType(event.target.value);
                    setActive(0);
                    setOpen(true);
                }}
                onKeyDown={(event) => {
                    if (event.key === 'Escape') {
                        setOpen(false);

                        return;
                    }

                    if (!searching) return;

                    if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                        event.preventDefault();
                        const step = event.key === 'ArrowDown' ? 1 : -1;
                        setActive((was) => Math.max(0, Math.min(results.length, was + step)));
                    }

                    if (event.key === 'Enter') {
                        event.preventDefault();
                        choose(active);
                    }
                }}
            />

            {searching &&
                createPortal(
                    <ul
                        ref={panel}
                        className="rx-pop"
                        role="listbox"
                        style={{ top: box.top, left: box.left, width: Math.max(box.width, 320) }}
                    >
                        {results.length === 0 && (
                            <li className="rx-none">
                                {search.isFetching
                                    ? 'Searching the test catalogue…'
                                    : `Nothing in the test catalogue matches “${term.trim()}”.`}
                            </li>
                        )}

                        {results.map((test, index) => (
                            <li key={test.id}>
                                <button
                                    type="button"
                                    role="option"
                                    aria-selected={index === active}
                                    className={index === active ? 'is-active' : undefined}
                                    onMouseEnter={() => setActive(index)}
                                    onClick={() => choose(index)}
                                >
                                    <span>
                                        {test.name}
                                        <small>
                                            {[test.code, test.department_name, test.sample_type_name].filter(Boolean).join(' · ')}
                                        </small>
                                    </span>
                                </button>
                            </li>
                        ))}

                        <li>
                            <button
                                type="button"
                                role="option"
                                aria-selected={active === results.length}
                                className={active === results.length ? 'is-active' : undefined}
                                onMouseEnter={() => setActive(results.length)}
                                onClick={() => choose(results.length)}
                            >
                                <span>
                                    Keep “{term.trim()}” as written
                                    <small>Not in the catalogue: the lab reads it as typed, and it is not charged</small>
                                </span>
                            </button>
                        </li>
                    </ul>,
                    document.body,
                )}
        </div>
    );
}

/**
 * Investigations, as real lab orders (decision D1, 2026-11-07).
 *
 * Where the laboratory runs and the doctor may order, each test is picked from
 * the Test Catalogue (or kept as typed) with a note. There is no separate send
 * button (the user's ask, 2026-11-08): "Save notes" and "Complete consultation"
 * save the new tests onto the visit's lab order — or add them to it while the
 * lab has not taken it up — through registerLabFlush. Every test stays listed
 * by name with where it stands. Tests the doctor had typed into the old note
 * box wait here and go with the next save.
 */
export function LabOrderEditor({ appointmentId, typed }: { appointmentId: number; typed: InvestigationLine[] }) {
    const { data: orders = [], isLoading } = useVisitLabOrders(appointmentId, true);
    const send = useSendToLab(appointmentId);

    const [expanded, setExpanded] = useState(false);
    const [drafts, setDrafts] = useState<Draft[]>([]);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [failure, setFailure] = useState<string | null>(null);
    const seededFor = useRef<number | null>(null);

    const live = orders.filter((order) => order.status !== 'cancelled');
    const sent = live.flatMap((order) =>
        (order.items ?? []).filter((item) => item.status !== 'cancelled').map((item) => ({ order, item })),
    );

    // The lab has not taken it up yet, so more tests can go on it.
    const openOrder: LabOrder | undefined = [...live].filter((order) => order.status === 'pending').sort((a, b) => b.id - a.id)[0];

    /*
     * Tests typed into the old note box and never sent wait here to be sent —
     * once per visit, after the visit's orders have loaded.
     */
    useEffect(() => {
        if (isLoading || seededFor.current === appointmentId) return;

        seededFor.current = appointmentId;

        const sentNames = new Set(sent.map(({ item }) => item.test_name.trim().toLowerCase()));

        setDrafts(
            typed
                .filter((line) => !line.lab_order_item_id && line.test?.trim() && !sentNames.has(line.test.trim().toLowerCase()))
                .map((line) => draftOf(line.test.trim(), line.notes ?? '', line.test.trim())),
        );
        setErrors({});
        setFailure(null);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [appointmentId, isLoading]);

    const update = (key: string, patch: Partial<Draft>) =>
        setDrafts((was) => was.map((draft) => (draft.key === key ? { ...draft, ...patch } : draft)));

    /** Send what is waiting. False when the lab refused it, with the reason on screen. */
    async function sendDrafts(): Promise<boolean> {
        const ready = drafts.filter((draft) => draft.test_name.trim() !== '');

        if (ready.length === 0) return true;

        setErrors({});
        setFailure(null);

        const kept = openOrder ? (openOrder.items ?? []).map(asLine) : [];
        const items: OrderLine[] = [
            ...kept,
            ...ready.map((draft) => ({
                test_name: draft.test_name.trim(),
                lab_test_id: draft.lab_test_id,
                instructions: draft.instructions.trim() || null,
                replaces: draft.replaces,
            })),
        ];

        try {
            const response = await send.mutateAsync({ orderId: openOrder?.id, items });
            setDrafts((was) => was.filter((draft) => draft.test_name.trim() === ''));
            notify.success(`${ready.length} test${ready.length === 1 ? '' : 's'} saved to ${response.data.order_number}`);

            return true;
        } catch (thrown) {
            const validation = getValidationErrors(thrown);

            if (validation) {
                const mapped: Record<string, string> = {};

                Object.entries(validation).forEach(([field, messages]) => {
                    const match = field.match(/^items\.(\d+)\.(\w+)$/);
                    const draft = match ? ready[Number(match[1]) - kept.length] : undefined;

                    if (draft) mapped[`${draft.key}.${match![2]}`] = messages[0];
                    else setFailure(messages[0]);
                });

                setErrors(mapped);
            } else {
                setFailure(resolveErrorMessage(thrown));
            }

            setExpanded(true);

            return false;
        }
    }

    /** Take one test back off an order the lab has not taken up. */
    async function withdraw(order: LabOrder, item: LabOrderItem) {
        setFailure(null);

        try {
            await send.mutateAsync({
                orderId: order.id,
                items: (order.items ?? []).filter((other) => other.id !== item.id).map(asLine),
            });
            notify.success(`${item.test_name} taken off ${order.order_number}`);
        } catch (thrown) {
            setFailure(resolveErrorMessage(thrown));
        }
    }

    // "Complete consultation" asks this editor to send what is waiting first.
    useEffect(() => registerLabFlush(appointmentId, sendDrafts));

    const waiting = drafts.filter((draft) => draft.test_name.trim() !== '').length;

    /*
     * What was written, by name — "CBC, Lipid profile" — so the doctor sees the
     * tests without opening the list (the user's ask). Anything not yet saved
     * says so after them.
     */
    const names = sent.map(({ item }) => item.test_name);
    const summary =
        names.length === 0 && waiting === 0
            ? 'Nothing ordered yet'
            : [names.length ? names.join(', ') : null, waiting ? `${waiting} not saved yet` : null]
                  .filter(Boolean)
                  .join(' · ');

    const empty = sent.length === 0 && drafts.length === 0;

    return (
        <>
            <div className="cn-row">
                <i className="cn-icon is-amber ti ti-flask" aria-hidden="true" />
                <span className="cn-label">Investigations</span>

                <div className="cn-value cn-summary" title={summary}>
                    {summary}
                </div>

                <button type="button" className="cn-plus" onClick={() => setExpanded(!expanded)}>
                    <i className={expanded ? 'ti ti-x' : 'ti ti-plus'} aria-hidden="true" />
                    {expanded ? 'Close' : 'Order tests'}
                </button>
            </div>

            {/*
              Laid out like the prescription above it (the user found the first
              table cramped and uneven, 2026-11-08): a quiet note, one bordered
              row per test on a shared grid, then a quiet line on saving.
            */}
            {expanded && (
                <div className="cn-open lab-order-editor">
                    <p className="rx-note">
                        <i className="ti ti-test-pipe" aria-hidden="true" />
                        Tests come from the Test Catalogue. One it does not list can be kept as written.
                    </p>

                    {failure && <p className="lab-order-error">{failure}</p>}

                    {empty ? (
                        <p className="lab-ord-empty">Nothing ordered yet. Add the first test below.</p>
                    ) : (
                        <div className="lab-ord-list">
                            <div className="lab-ord-head" aria-hidden="true">
                                <span>Test</span>
                                <span>Note for the lab</span>
                                <span>Status</span>
                                <span />
                            </div>

                            {sent.map(({ order, item }) => {
                                const removable =
                                    order.status === 'pending' &&
                                    (order.items ?? []).filter((other) => other.status !== 'cancelled').length > 1;
                                const facts = [item.test_code, item.department_name, item.sample_type_name]
                                    .filter(Boolean)
                                    .join(' · ');

                                return (
                                    <div key={`sent-${item.id}`} className="lab-ord-line">
                                        <div className="lab-ord-test">
                                            <span className="lab-ord-name">
                                                <b title={item.test_name}>{item.test_name}</b>
                                            </span>
                                            <small>{facts || 'Written as typed'}</small>
                                        </div>

                                        <div className={`lab-ord-note${item.instructions ? '' : ' is-none'}`}>
                                            {item.instructions || 'No note'}
                                        </div>

                                        <div className="lab-ord-state" title={`On ${order.order_number}`}>
                                            <LabStatus status={order.status} />
                                        </div>

                                        <div className="lab-ord-act">
                                            {removable && (
                                                <button
                                                    type="button"
                                                    className="cn-drop"
                                                    disabled={send.isPending}
                                                    aria-label={`Take ${item.test_name} off the order`}
                                                    title="Take this test off the order"
                                                    onClick={() => void withdraw(order, item)}
                                                >
                                                    <i className="ti ti-trash" aria-hidden="true" />
                                                </button>
                                            )}
                                        </div>
                                    </div>
                                );
                            })}

                            {drafts.map((draft) => {
                                const fieldError = errors[`${draft.key}.test_name`] ?? errors[`${draft.key}.lab_test_id`];

                                return (
                                    <div key={draft.key} className="lab-ord-line is-draft">
                                        <div>
                                            <LabTestPicker
                                                draft={draft}
                                                invalid={Boolean(fieldError)}
                                                onPick={(picked) =>
                                                    update(draft.key, { lab_test_id: picked.id, test_name: picked.name, meta: picked.meta })
                                                }
                                                onType={(name) => update(draft.key, { test_name: name, lab_test_id: null, meta: null })}
                                                onClear={() => update(draft.key, { test_name: '', lab_test_id: null, meta: null })}
                                            />
                                            {fieldError && <em className="lab-order-field-error">{fieldError}</em>}
                                        </div>

                                        <div>
                                            <input
                                                type="text"
                                                className="form-control"
                                                placeholder="e.g. Fasting"
                                                aria-label="Note for the lab"
                                                maxLength={191}
                                                value={draft.instructions}
                                                onChange={(event) => update(draft.key, { instructions: event.target.value })}
                                            />
                                        </div>

                                        <div className="lab-ord-state">
                                            <span className="lab-status is-draft">Not saved</span>
                                        </div>

                                        <div className="lab-ord-act">
                                            <button
                                                type="button"
                                                className="cn-drop"
                                                aria-label="Remove this test"
                                                title="Remove this test"
                                                onClick={() => setDrafts((was) => was.filter((other) => other.key !== draft.key))}
                                            >
                                                <i className="ti ti-trash" aria-hidden="true" />
                                            </button>
                                        </div>
                                    </div>
                                );
                            })}
                        </div>
                    )}

                    <button type="button" className="cn-more" onClick={() => setDrafts((was) => [...was, draftOf()])}>
                        <i className="ti ti-plus" aria-hidden="true" />
                        Add a test
                    </button>

                    {/* No button of its own (the user's ask): "Save notes" sends them. */}
                    <p className="lab-ord-foot">
                        {waiting > 0
                            ? `${waiting} test${waiting === 1 ? '' : 's'} not saved yet. Save notes sends ${waiting === 1 ? 'it' : 'them'} to the lab.`
                            : 'New tests go to the lab when you press Save notes.'}
                    </p>
                </div>
            )}
        </>
    );
}
