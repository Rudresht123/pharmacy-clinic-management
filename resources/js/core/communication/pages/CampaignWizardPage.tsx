import { useEffect, useMemo, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { resolveErrorMessage } from '@/shared/api/http';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { TemplatePreview } from '../components/TemplatePreview';
import { AudienceBuilder } from '../components/AudienceBuilder';
import {
    useAudiencePreview,
    useCampaigns,
    useChannelOverview,
    useSaveCampaign,
    useScheduleCampaign,
} from '../api';
import type { AudienceFilter, Channel } from '../types';

/**
 * Five decisions, each of which changes what the next one means.
 *
 * The description under each label is not decoration: a rail of five bare
 * nouns tells somebody where they are but not what is wanted, and "Audience"
 * alone is the step people skip past and then wonder why the send reached
 * everybody.
 */
const STEPS = [
    {
        key: 'basics',
        label: 'Campaign',
        hint: 'What to call it',
        icon: 'ti ti-file-text',
    },
    {
        key: 'template',
        label: 'Message',
        hint: 'What it says',
        icon: 'ti ti-message',
    },
    {
        key: 'audience',
        label: 'Audience',
        hint: 'Who receives it',
        icon: 'ti ti-users',
    },
    {
        key: 'schedule',
        label: 'Schedule',
        hint: 'When it goes',
        icon: 'ti ti-clock',
    },
    {
        key: 'review',
        label: 'Review',
        hint: 'Check before saving',
        icon: 'ti ti-checks',
    },
] as const;

interface Draft {
    name: string;
    goal: string;
    campaign_type: string;
    message_template_id: number | null;
    subject: string;
    content: string;
    audience_segment_id: number | null;
    audience_filters: AudienceFilter[];
    scheduled_at: string;
}

const EMPTY: Draft = {
    name: '',
    goal: '',
    campaign_type: 'Marketing',
    message_template_id: null,
    subject: '',
    content: '',
    audience_segment_id: null,
    audience_filters: [],
    scheduled_at: '',
};

/** `datetime-local` wants the local wall clock, not an ISO instant. */
function toLocalInput(value: string | null): string {
    if (!value) {
        return '';
    }

    const date = new Date(value);

    return new Date(date.getTime() - date.getTimezoneOffset() * 60_000).toISOString().slice(0, 16);
}

/**
 * Build a campaign, on its own screen.
 *
 * A PAGE rather than a dialog. Five steps with an audience builder inside one
 * of them is not a question to answer in passing — it is a piece of work with
 * a preview beside it, and cramming it into a modal left a 1400px box with
 * three fields in the top corner and nothing anywhere else.
 *
 * NOTHING IS SENT FROM HERE. This saves a draft and optionally sets a time;
 * the list is where a campaign is actually let go. Making the last button of a
 * wizard also mean "send to two thousand people" is how an irreversible action
 * gets taken by somebody who thought they were saving.
 */
export default function CampaignWizardPage({ channel = 'email' }: { channel?: Channel }) {
    const navigate = useNavigate();
    const { id } = useParams<{ id?: string }>();
    const editingId = id ? Number(id) : null;

    const back = `/communication/${channel}?view=campaigns`;

    const overview = useChannelOverview(channel);
    const list = useCampaigns(channel);
    const save = useSaveCampaign();
    const schedule = useScheduleCampaign();
    const preview = useAudiencePreview();

    const [step, setStep] = useState(0);
    const [draft, setDraft] = useState<Draft>(EMPTY);
    const [error, setError] = useState<string | null>(null);
    const [reach, setReach] = useState<number | null>(null);
    const [loaded, setLoaded] = useState(false);

    const templates = overview.data?.templates ?? [];

    const campaign = useMemo(
        () => (list.data?.data ?? []).find((item) => item.id === editingId) ?? null,
        [list.data, editingId],
    );

    /* Loaded once. Re-running on every list refetch would throw away whatever
       the person had typed since. */
    useEffect(() => {
        if (loaded || editingId === null) {
            return;
        }

        if (campaign) {
            setDraft({
                name: campaign.name,
                goal: campaign.goal ?? '',
                campaign_type: campaign.campaign_type ?? 'Marketing',
                message_template_id: campaign.message_template_id,
                subject: campaign.subject ?? '',
                content: campaign.content ?? '',
                audience_segment_id: campaign.audience_segment_id,
                audience_filters: campaign.audience_filters ?? [],
                scheduled_at: toLocalInput(campaign.scheduled_at),
            });

            setLoaded(true);
        }
    }, [campaign, editingId, loaded]);

    const template = useMemo(
        () => templates.find((item) => item.id === draft.message_template_id) ?? null,
        [templates, draft.message_template_id],
    );

    /* The review states the audience as a number, fetched when that step is
       reached rather than trusted from the builder's own state. */
    useEffect(() => {
        if (STEPS[step].key !== 'review') {
            return;
        }

        preview
            .mutateAsync({ channel, filters: draft.audience_filters })
            .then((result) => setReach(result.total))
            .catch(() => setReach(null));
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [step]);

    function set(patch: Partial<Draft>) {
        setDraft((current) => ({ ...current, ...patch }));
        setError(null);
    }

    /**
     * What stops each step, checked on Next rather than by disabling it.
     *
     * A disabled button with no explanation is the commonest way a form
     * becomes unusable — somebody clicks, nothing happens, and there is
     * nothing on screen saying why.
     */
    function blocker(): string | null {
        switch (STEPS[step].key) {
            case 'basics':
                return draft.name.trim() === '' ? 'Give the campaign a name.' : null;

            case 'template':
                if (draft.message_template_id === null && draft.content.trim() === '') {
                    return 'Choose a template, or write the message yourself.';
                }

                if (channel === 'email' && draft.subject.trim() === '' && !template?.subject) {
                    return 'An email needs a subject line.';
                }

                return null;

            default:
                return null;
        }
    }

    function next() {
        const problem = blocker();

        if (problem) {
            setError(problem);

            return;
        }

        setStep((current) => Math.min(current + 1, STEPS.length - 1));
    }

    async function finish() {
        setError(null);

        try {
            const saved = await save.mutateAsync({
                id: editingId ?? undefined,
                channel,
                name: draft.name.trim(),
                goal: draft.goal.trim() || null,
                campaign_type: draft.campaign_type || null,
                message_template_id: draft.message_template_id,
                subject: draft.subject.trim() || template?.subject || null,
                content: draft.content.trim() || template?.content || null,
                audience_segment_id: draft.audience_segment_id,
                audience_filters: draft.audience_filters,
            });

            /*
             * Scheduling is a second call on purpose. The server refuses a time
             * in the past and an audience of nobody, and those refusals belong
             * to the schedule — a campaign whose time was rejected should still
             * exist as a draft to correct.
             */
            const savedId = saved.data?.id ?? editingId;

            if (draft.scheduled_at && savedId) {
                await schedule.mutateAsync({
                    id: savedId,
                    at: new Date(draft.scheduled_at).toISOString(),
                });
            }

            navigate(back);
        } catch (failure) {
            /*
             * The SERVER'S OWN WORDS, not a generic apology.
             *
             * Saving and scheduling are two calls, and the second refuses for
             * reasons the first cannot: a time in the past, or an audience of
             * nobody. "Check the fields and try again" sends somebody back
             * through five steps that were all fine — the campaign is already
             * saved as a draft at this point, and only the time was rejected.
             */
            setError(resolveErrorMessage(failure));
        }
    }

    const busy = save.isPending || schedule.isPending;
    const last = step === STEPS.length - 1;

    return (
        <>
            <PageHeader
                title={editingId ? 'Edit campaign' : 'New campaign'}
                subtitle={`${STEPS[step].label} — ${STEPS[step].hint}`}
                icon="ti ti-send"
                crumbs={[
                    { label: 'Communication', to: `/communication/${channel}` },
                    { label: channel === 'email' ? 'Email' : 'WhatsApp', to: back },
                    { label: editingId ? 'Edit' : 'New' },
                ]}
            />

            <div className="cw">
                {/* A vertical rail, not a row of pills. Five steps across the
                    top of a wide page put the labels miles from the fields
                    they describe and left the content column half empty. */}
                <aside className="cw-rail">
                    <ol>
                        {STEPS.map((item, index) => (
                            <li
                                key={item.key}
                                className={`cw-step${index === step ? ' is-active' : ''}${
                                    index < step ? ' is-done' : ''
                                }`}
                            >
                                <button
                                    type="button"
                                    // Only backwards. Jumping ahead would skip
                                    // the checks that stop an unnamed campaign.
                                    disabled={index > step}
                                    onClick={() => setStep(index)}
                                >
                                    <span className="cw-step-mark">
                                        {index < step ? (
                                            <i className="ti ti-check" aria-hidden="true" />
                                        ) : (
                                            index + 1
                                        )}
                                    </span>

                                    <span className="cw-step-text">
                                        <b>{item.label}</b>
                                        <small>{item.hint}</small>
                                    </span>
                                </button>
                            </li>
                        ))}
                    </ol>
                </aside>

                <div className="cw-main">
                    <Card className="cw-card">
                        {STEPS[step].key === 'basics' && (
                            <div className="cw-fields">
                                <div className="cw-field">
                                    <label className="form-label" htmlFor="cw-name">
                                        Campaign name <span className="text-danger">*</span>
                                    </label>

                                    <input
                                        id="cw-name"
                                        className="form-control"
                                        autoFocus
                                        value={draft.name}
                                        placeholder="September health check-up drive"
                                        onChange={(event) => set({ name: event.target.value })}
                                    />

                                    <small className="form-text">
                                        Internal only. Patients never see this.
                                    </small>
                                </div>

                                <div className="cw-field">
                                    <label className="form-label" htmlFor="cw-goal">
                                        Goal
                                    </label>

                                    <input
                                        id="cw-goal"
                                        className="form-control"
                                        value={draft.goal}
                                        placeholder="Bring lapsed patients back in"
                                        onChange={(event) => set({ goal: event.target.value })}
                                    />

                                    <small className="form-text">
                                        What this is for, so the list still makes sense in March.
                                    </small>
                                </div>

                                <div className="cw-field">
                                    <label className="form-label" htmlFor="cw-type">
                                        Type
                                    </label>

                                    <select
                                        id="cw-type"
                                        className="form-select"
                                        value={draft.campaign_type}
                                        onChange={(event) =>
                                            set({ campaign_type: event.target.value })
                                        }
                                    >
                                        <option value="Marketing">Marketing</option>
                                        <option value="Transactional">Transactional</option>
                                        <option value="Reminder">Reminder</option>
                                    </select>

                                    <small className="form-text">
                                        Marketing sends are the ones a patient may opt out of.
                                    </small>
                                </div>
                            </div>
                        )}

                        {STEPS[step].key === 'template' && (
                            <div className="cw-split">
                                <div className="cw-fields">
                                    <div className="cw-field">
                                        <label className="form-label" htmlFor="cw-template">
                                            Template
                                        </label>

                                        <select
                                            id="cw-template"
                                            className="form-select"
                                            value={draft.message_template_id ?? ''}
                                            onChange={(event) => {
                                                const picked = templates.find(
                                                    (t) => t.id === Number(event.target.value),
                                                );

                                                set({
                                                    message_template_id: picked?.id ?? null,
                                                    subject: picked?.subject ?? draft.subject,
                                                    content: picked?.content ?? draft.content,
                                                });
                                            }}
                                        >
                                            <option value="">Write it myself</option>

                                            {templates
                                                .filter((item) => item.status === 'approved')
                                                .map((item) => (
                                                    <option key={item.id} value={item.id}>
                                                        {item.name}
                                                    </option>
                                                ))}
                                        </select>

                                        <small className="form-text">
                                            {channel === 'whatsapp'
                                                ? 'Only approved templates are listed — WhatsApp refuses anything else.'
                                                : 'Only approved templates are listed.'}
                                        </small>
                                    </div>

                                    {channel === 'email' && (
                                        <div className="cw-field">
                                            <label className="form-label" htmlFor="cw-subject">
                                                Subject <span className="text-danger">*</span>
                                            </label>

                                            <input
                                                id="cw-subject"
                                                className="form-control"
                                                value={draft.subject}
                                                placeholder="Time for your check-up, {{patient_name}}"
                                                onChange={(event) =>
                                                    set({ subject: event.target.value })
                                                }
                                            />

                                            <small className="form-text">
                                                The only part most people ever read.
                                            </small>
                                        </div>
                                    )}

                                    <div className="cw-field">
                                        <label className="form-label" htmlFor="cw-content">
                                            Message
                                        </label>

                                        <textarea
                                            id="cw-content"
                                            className="form-control"
                                            rows={9}
                                            value={draft.content}
                                            placeholder="Hi {{patient_name}}, it has been a while since your last visit…"
                                            onChange={(event) =>
                                                set({ content: event.target.value })
                                            }
                                        />

                                        <small className="form-text">
                                            {'Placeholders like {{patient_name}} are filled from the patient record.'}
                                        </small>
                                    </div>
                                </div>

                                <div className="cw-preview">
                                    <span className="comm-subhead">Preview</span>

                                    <TemplatePreview
                                        channel={channel}
                                        name={draft.name}
                                        subject={draft.subject}
                                        content={draft.content}
                                        icon={template?.icon}
                                    />
                                </div>
                            </div>
                        )}

                        {STEPS[step].key === 'audience' && (
                            <AudienceBuilder
                                channel={channel}
                                filters={draft.audience_filters}
                                segmentId={draft.audience_segment_id}
                                onChange={(filters) => set({ audience_filters: filters })}
                                onSegment={(segmentId) => set({ audience_segment_id: segmentId })}
                            />
                        )}

                        {STEPS[step].key === 'schedule' && (
                            <div className="cw-fields">
                                <div className="cw-field">
                                    <label className="form-label" htmlFor="cw-when">
                                        Send at
                                    </label>

                                    <input
                                        id="cw-when"
                                        type="datetime-local"
                                        className="form-control"
                                        value={draft.scheduled_at}
                                        onChange={(event) =>
                                            set({ scheduled_at: event.target.value })
                                        }
                                    />

                                    <small className="form-text">
                                        Leave empty to save as a draft and send it by hand later.
                                    </small>
                                </div>

                                <p className="pf-soon">
                                    <i className="ti ti-info-circle" aria-hidden="true" />
                                    A scheduled campaign is picked up every minute, so it goes at
                                    the time set rather than when somebody is at a desk. The
                                    schedule can be cancelled until it starts.
                                </p>
                            </div>
                        )}

                        {STEPS[step].key === 'review' && (
                            <div className="cw-review">
                                <dl className="cw-review-list">
                                    <div>
                                        <dt>Name</dt>
                                        <dd>{draft.name || '—'}</dd>
                                    </div>

                                    <div>
                                        <dt>Channel</dt>
                                        <dd>{channel === 'email' ? 'Email' : 'WhatsApp'}</dd>
                                    </div>

                                    <div>
                                        <dt>Template</dt>
                                        <dd>{template?.name ?? 'Written by hand'}</dd>
                                    </div>

                                    {channel === 'email' && (
                                        <div>
                                            <dt>Subject</dt>
                                            <dd>{draft.subject || template?.subject || '—'}</dd>
                                        </div>
                                    )}

                                    <div>
                                        <dt>Conditions</dt>
                                        <dd>
                                            {draft.audience_filters.length === 0
                                                ? 'Everyone reachable'
                                                : `${draft.audience_filters.length} condition${
                                                      draft.audience_filters.length === 1 ? '' : 's'
                                                  }`}
                                        </dd>
                                    </div>

                                    <div>
                                        <dt>When</dt>
                                        <dd>
                                            {draft.scheduled_at
                                                ? new Date(draft.scheduled_at).toLocaleString()
                                                : 'Saved as a draft'}
                                        </dd>
                                    </div>
                                </dl>

                                {/* Last and largest: the one fact somebody must
                                    not be able to miss before committing. */}
                                <div className={`comm-audience-count${reach === 0 ? ' is-empty' : ''}`}>
                                    <i
                                        className={
                                            preview.isPending
                                                ? 'ti ti-loader-2 comm-spin'
                                                : 'ti ti-users-group'
                                        }
                                        aria-hidden="true"
                                    />

                                    <div>
                                        <b>
                                            {preview.isPending
                                                ? 'Counting…'
                                                : reach === null
                                                  ? 'Audience unavailable'
                                                  : `This will reach ${reach.toLocaleString('en-IN')} ${
                                                        reach === 1 ? 'patient' : 'patients'
                                                    }`}
                                        </b>

                                        <small>
                                            {reach === 0
                                                ? 'Nobody matches. Go back and widen the audience.'
                                                : 'Counted now. The audience is resolved again when it sends.'}
                                        </small>
                                    </div>
                                </div>
                            </div>
                        )}
                    </Card>

                    {error && (
                        <p className="comm-provider-result is-bad">
                            <i className="ti ti-alert-circle" aria-hidden="true" />
                            {error}
                        </p>
                    )}

                    <div className="cw-foot">
                        <Button
                            variant="light"
                            type="button"
                            icon="ti ti-arrow-left"
                            onClick={() => (step === 0 ? navigate(back) : setStep(step - 1))}
                            disabled={busy}
                        >
                            {step === 0 ? 'Cancel' : 'Back'}
                        </Button>

                        <span className="cw-progress">
                            Step {step + 1} of {STEPS.length}
                        </span>

                        {last ? (
                            <Button
                                type="button"
                                icon="ti ti-check"
                                onClick={finish}
                                disabled={busy}
                            >
                                {draft.scheduled_at ? 'Save and schedule' : 'Save draft'}
                            </Button>
                        ) : (
                            <Button type="button" icon="ti ti-arrow-right" onClick={next}>
                                Next
                            </Button>
                        )}
                    </div>
                </div>
            </div>
        </>
    );
}
