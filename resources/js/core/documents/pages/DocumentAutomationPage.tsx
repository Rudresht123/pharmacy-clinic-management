import { useEffect, useMemo, useState } from 'react';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useConfirm } from '@/shared/hooks/useConfirm';
import { useDocumentRules, useSaveDocumentRules, type AutomatableEvent, type DocumentRule } from '../api';

/**
 * Settings › Automatic documents — "a receipt on every payment".
 *
 * ONE SCOPE AT A TIME. The selector at the top says whose rules are on the
 * screen: every branch, or one. Looking at a branch shows the every-branch
 * rules as ticked and fixed — they apply there too, and unticking them is
 * done where they were set — beside the branch's own, which it may change.
 *
 * What may be ticked is the server's list, not this file's: an event appears
 * only where its module runs, and offers only the documents it carries the
 * record for. Nothing here can make a rule that would never fire.
 */
const MODULE_TITLES: Record<string, { title: string; icon: string; note?: string }> = {
    billing: {
        title: 'Billing',
        icon: 'ti ti-receipt',
        note: 'The payment that settles a bill counts as both “Payment received” and “Invoice settled in full” — a receipt ticked on both is still made once.',
    },
    prescriptions: { title: 'Prescriptions', icon: 'ti ti-prescription' },
    pharmacy: { title: 'Pharmacy', icon: 'ti ti-pill' },
};

const ALL = 'all';

const tick = (eventKey: string, documentType: string) => `${eventKey}|${documentType}`;

export default function DocumentAutomationPage() {
    const { data, isLoading } = useDocumentRules();
    const save = useSaveDocumentRules();
    const confirm = useConfirm();

    const [scope, setScope] = useState<string>(ALL);
    const [ticked, setTicked] = useState<Set<string>>(new Set());
    const [error, setError] = useState<string | null>(null);

    const locationId = scope === ALL ? null : Number(scope);

    const inScope = (rule: DocumentRule) => rule.location_id === locationId;

    /** What is saved for this scope — the baseline the ticks are compared to. */
    const saved = useMemo(
        () => new Set((data?.rules ?? []).filter(inScope).map((rule) => tick(rule.event_key, rule.document_type))),
        // eslint-disable-next-line react-hooks/exhaustive-deps
        [data, locationId],
    );

    /** Set for every branch — shown, and fixed, when one branch is on screen. */
    const inherited = useMemo(
        () =>
            locationId === null
                ? new Set<string>()
                : new Set(
                      (data?.rules ?? [])
                          .filter((rule) => rule.location_id === null)
                          .map((rule) => tick(rule.event_key, rule.document_type)),
                  ),
        [data, locationId],
    );

    // Re-arm whenever the scope changes or a save comes back.
    useEffect(() => {
        setTicked(new Set(saved));
        setError(null);
    }, [saved]);

    const dirty = ticked.size !== saved.size || [...ticked].some((key) => !saved.has(key));

    /** Events grouped under their module, in the server's order. */
    const groups = useMemo(() => {
        const byModule = new Map<string, AutomatableEvent[]>();

        for (const event of data?.events ?? []) {
            byModule.set(event.module, [...(byModule.get(event.module) ?? []), event]);
        }

        return [...byModule.entries()];
    }, [data]);

    function toggle(key: string) {
        setTicked((current) => {
            const next = new Set(current);

            if (next.has(key)) {
                next.delete(key);
            } else {
                next.add(key);
            }

            return next;
        });
    }

    /** Ticks are per scope, so leaving one with unsaved ticks asks first. */
    async function changeScope(next: string) {
        if (
            dirty &&
            !(await confirm({
                title: 'Discard unsaved changes?',
                message: 'The ticks you changed here have not been saved.',
                confirmLabel: 'Discard',
                danger: true,
            }))
        ) {
            return;
        }

        setScope(next);
    }

    async function submit() {
        setError(null);

        try {
            await save.mutateAsync({
                location_id: locationId,
                rules: [...ticked].map((key) => {
                    const [event_key, document_type] = key.split('|');

                    return { event_key, document_type };
                }),
            });

            notify.success('Automatic documents saved');
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    if (isLoading || !data) {
        return <LoadingBlock label="Loading automatic documents…" />;
    }

    const branchName = data.branches.find((branch) => branch.id === locationId)?.name;

    return (
        <>
            <PageHeader
                title="Automatic documents"
                crumbs={[{ label: 'Settings' }, { label: 'Automatic documents' }]}
                actions={
                    <Button icon="ti ti-check" onClick={() => void submit()} disabled={!dirty || save.isPending}>
                        {save.isPending ? 'Saving…' : 'Save'}
                    </Button>
                }
            />

            {error && <div className="alert alert-danger py-2">{error}</div>}

            <div className="row g-3">
                <div className="col-lg-8">
                    {groups.length === 0 ? (
                        <Card>
                            <p className="dpt-none mb-0">
                                Nothing here can make a document on its own yet — the modules that raise these
                                events are not running for this organisation.
                            </p>
                        </Card>
                    ) : (
                        groups.map(([module, events], index) => {
                            const meta = MODULE_TITLES[module] ?? { title: module, icon: 'ti ti-bolt' };

                            return (
                                <Card key={module} className={index > 0 ? 'mt-3' : undefined}>
                                    <h6 className="mb-3">
                                        <i className={`${meta.icon} me-2`} />
                                        {meta.title}
                                    </h6>

                                    <div className="dpt-frame">
                                        <table className="dpt-table">
                                            <thead>
                                                <tr>
                                                    <th style={{ width: '42%' }}>When this happens</th>
                                                    <th>Make automatically</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                {events.map((event) => (
                                                    <tr key={event.key}>
                                                        <td>
                                                            <b>{event.label}</b>
                                                        </td>
                                                        <td>
                                                            <div className="d-flex flex-wrap gap-3">
                                                                {event.documents.map((document_) => {
                                                                    const key = tick(event.key, document_.key);
                                                                    const fixed = inherited.has(key);

                                                                    return (
                                                                        <label
                                                                            key={document_.key}
                                                                            className="form-check mb-0"
                                                                            title={
                                                                                fixed
                                                                                    ? 'Set for every branch — change it under “All branches”.'
                                                                                    : document_.description
                                                                            }
                                                                        >
                                                                            <input
                                                                                type="checkbox"
                                                                                className="form-check-input"
                                                                                checked={fixed || ticked.has(key)}
                                                                                disabled={fixed}
                                                                                onChange={() => toggle(key)}
                                                                            />
                                                                            <span className="form-check-label">
                                                                                {document_.name}
                                                                                {fixed && (
                                                                                    <span className="badge bg-light text-muted ms-2">
                                                                                        All branches
                                                                                    </span>
                                                                                )}
                                                                            </span>
                                                                        </label>
                                                                    );
                                                                })}
                                                            </div>
                                                        </td>
                                                    </tr>
                                                ))}
                                            </tbody>
                                        </table>
                                    </div>

                                    {meta.note && <p className="text-muted fs-13 mt-3 mb-0">{meta.note}</p>}
                                </Card>
                            );
                        })
                    )}
                </div>

                <div className="col-lg-4">
                    <Card>
                        <h6 className="mb-2">Applies to</h6>
                        <p className="text-muted fs-13 mb-3">
                            Rules for every branch apply everywhere. A branch can add its own on top — it cannot
                            switch off one set for every branch.
                        </p>

                        <select
                            className="form-select"
                            value={scope}
                            onChange={(event) => void changeScope(event.target.value)}
                            aria-label="Which branch these rules are for"
                        >
                            <option value={ALL}>All branches</option>
                            {data.branches.map((branch) => (
                                <option key={branch.id} value={String(branch.id)}>
                                    {branch.name}
                                    {branch.code ? ` (${branch.code})` : ''}
                                </option>
                            ))}
                        </select>

                        {dirty && (
                            <p className="fs-13 text-warning mt-3 mb-0">
                                Unsaved changes for {branchName ?? 'all branches'}.
                            </p>
                        )}
                    </Card>

                    <Card className="mt-3">
                        <h6 className="mb-2">What happens</h6>
                        <ul className="text-muted fs-13 mb-0 ps-3">
                            <li className="mb-2">
                                The document is made the moment the event is saved, on the branch&rsquo;s own
                                letterhead, and filed on the patient&rsquo;s record — ready to open or print.
                            </li>
                            <li className="mb-2">
                                Each payment, refund or prescription gets one copy, however many times it is
                                raised. A copy somebody removes is not made again.
                            </li>
                            <li className="mb-2">
                                Pressing Print on a receipt that was already made opens that copy instead of
                                filing a second one. A bill always prints as it stands now.
                            </li>
                            <li className="mb-2">
                                If a document cannot be made — no published template, say — the payment still
                                goes through. Printing by hand is unaffected.
                            </li>
                            <li>Nothing is sent to the patient by WhatsApp or email yet.</li>
                        </ul>
                    </Card>
                </div>
            </div>
        </>
    );
}
