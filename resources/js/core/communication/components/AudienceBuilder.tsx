import { useEffect, useRef, useState } from 'react';
import { Button } from '@/shared/components/ui/Button';
import { useAudiencePreview, useAudienceSegments } from '../api';
import type { AudienceFilter, AudiencePreview, Channel } from '../types';

/**
 * The fields the server can actually filter on.
 *
 * Mirrors AudienceResolver::FIELDS exactly. Offering one it does not
 * understand would be a filter the builder counts and the dispatcher ignores —
 * a campaign reaching people the screen never showed.
 */
const FIELDS: {
    value: string;
    label: string;
    operators: { value: string; label: string }[];
    input: 'number' | 'text' | 'gender';
    unit?: string;
}[] = [
    {
        value: 'age',
        label: 'Age',
        input: 'number',
        unit: 'years',
        operators: [
            { value: 'greater_than', label: 'is over' },
            { value: 'less_than', label: 'is under' },
            { value: 'between', label: 'is between' },
        ],
    },
    {
        value: 'gender',
        label: 'Gender',
        input: 'gender',
        operators: [{ value: 'equals', label: 'is' }],
    },
    {
        value: 'city',
        label: 'City',
        input: 'text',
        operators: [{ value: 'equals', label: 'is' }],
    },
    {
        value: 'registered',
        label: 'Registered',
        input: 'number',
        unit: 'months',
        operators: [
            { value: 'within_months', label: 'within the last' },
            { value: 'older_than_months', label: 'more than' },
        ],
    },
    {
        value: 'last_visit',
        label: 'Last visit',
        input: 'number',
        unit: 'months',
        operators: [
            { value: 'within_months', label: 'within the last' },
            { value: 'older_than_months', label: 'not for' },
        ],
    },
];

function fieldFor(name: string) {
    return FIELDS.find((field) => field.value === name) ?? FIELDS[0];
}

/**
 * Who a campaign will reach, built one condition at a time.
 *
 * THE COUNT IS THE POINT. A campaign is the one thing here that cannot be
 * taken back once it goes, so the screen answers "how many people is this"
 * before anybody commits — a blind send to a register of unknown size is how a
 * clinic emails two thousand patients meaning to reach twenty.
 *
 * The count is asked for from the SERVER rather than computed here, because
 * the server is what the dispatcher will use, and a preview that agrees with a
 * different implementation is worse than no preview.
 */
export function AudienceBuilder({
    channel,
    filters,
    segmentId,
    onChange,
    onSegment,
}: {
    channel: Channel;
    filters: AudienceFilter[];
    segmentId: number | null;
    onChange: (filters: AudienceFilter[]) => void;
    onSegment: (id: number | null) => void;
}) {
    const { data: segments } = useAudienceSegments(channel);
    const preview = useAudiencePreview();

    const [count, setCount] = useState<AudiencePreview | null>(null);
    const timer = useRef<ReturnType<typeof setTimeout> | null>(null);

    /*
     * Debounced, because this runs while somebody is typing a city name and
     * each keystroke is a query across the whole patient register.
     */
    useEffect(() => {
        if (timer.current) {
            clearTimeout(timer.current);
        }

        timer.current = setTimeout(() => {
            preview
                .mutateAsync({ channel, filters })
                .then(setCount)
                .catch(() => setCount(null));
        }, 400);

        return () => {
            if (timer.current) {
                clearTimeout(timer.current);
            }
        };
        // `preview` is a stable mutation object; including it would re-run on
        // every render and defeat the debounce.
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [channel, JSON.stringify(filters)]);

    function update(index: number, patch: Partial<AudienceFilter>) {
        onChange(filters.map((filter, i) => (i === index ? { ...filter, ...patch } : filter)));
    }

    function add() {
        onChange([...filters, { field: 'age', operator: 'greater_than', value: '' }]);
        onSegment(null);
    }

    function remove(index: number) {
        onChange(filters.filter((_, i) => i !== index));
    }

    return (
        <div className="comm-audience">
            {/* A saved segment is the common case and the safe one: somebody
                has already thought about who it reaches. Filters are for the
                campaign that does not fit one. */}
            <div className="comm-audience-segments">
                <span className="comm-subhead">Start from a segment</span>

                <div className="comm-segment-grid">
                    {(segments ?? []).map((segment) => (
                        <button
                            key={segment.id}
                            type="button"
                            className={`comm-segment${segmentId === segment.id ? ' is-active' : ''}`}
                            aria-pressed={segmentId === segment.id}
                            onClick={() => {
                                onSegment(segment.id);
                                onChange(segment.filters ?? []);
                            }}
                        >
                            <span className={`comm-glyph is-${segment.tone ?? 'sky'}`}>
                                <i className={segment.icon ?? 'ti ti-users'} />
                            </span>

                            <span className="comm-segment-body">
                                <b>{segment.name}</b>
                                <small>{segment.description ?? 'No description'}</small>
                            </span>

                            <span className="comm-segment-count">
                                {segment.total.toLocaleString('en-IN')}
                            </span>
                        </button>
                    ))}
                </div>
            </div>

            <div className="comm-audience-filters">
                <div className="comm-audience-head">
                    <span className="comm-subhead">Conditions</span>

                    <Button variant="light" size="sm" icon="ti ti-plus" onClick={add} type="button">
                        Add condition
                    </Button>
                </div>

                {filters.length === 0 ? (
                    <p className="pf-soon">
                        <i className="ti ti-users" aria-hidden="true" />
                        No conditions — this reaches everyone who can be contacted on this channel.
                    </p>
                ) : (
                    filters.map((filter, index) => {
                        const field = fieldFor(filter.field);

                        return (
                            <div className="comm-filter-row" key={index}>
                                <select
                                    className="form-select"
                                    value={filter.field}
                                    onChange={(event) => {
                                        const next = fieldFor(event.target.value);

                                        // The operator belongs to the field. Keeping
                                        // the old one leaves "City is over", which
                                        // the server would silently drop.
                                        update(index, {
                                            field: next.value,
                                            operator: next.operators[0].value,
                                            value: '',
                                            value_to: undefined,
                                        });
                                        onSegment(null);
                                    }}
                                >
                                    {FIELDS.map((item) => (
                                        <option key={item.value} value={item.value}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>

                                <select
                                    className="form-select"
                                    value={filter.operator}
                                    onChange={(event) => {
                                        update(index, { operator: event.target.value });
                                        onSegment(null);
                                    }}
                                >
                                    {field.operators.map((item) => (
                                        <option key={item.value} value={item.value}>
                                            {item.label}
                                        </option>
                                    ))}
                                </select>

                                {field.input === 'gender' ? (
                                    <select
                                        className="form-select"
                                        value={String(filter.value ?? '')}
                                        onChange={(event) => {
                                            update(index, { value: event.target.value });
                                            onSegment(null);
                                        }}
                                    >
                                        <option value="">Select…</option>
                                        <option value="male">Male</option>
                                        <option value="female">Female</option>
                                        <option value="other">Other</option>
                                    </select>
                                ) : (
                                    <div className="comm-filter-value">
                                        <input
                                            className="form-control"
                                            type={field.input}
                                            value={String(filter.value ?? '')}
                                            placeholder={field.input === 'text' ? 'Gurugram' : '0'}
                                            onChange={(event) => {
                                                update(index, { value: event.target.value });
                                                onSegment(null);
                                            }}
                                        />

                                        {filter.operator === 'between' && (
                                            <>
                                                <span className="comm-filter-and">and</span>

                                                <input
                                                    className="form-control"
                                                    type="number"
                                                    value={String(filter.value_to ?? '')}
                                                    onChange={(event) => {
                                                        update(index, {
                                                            value_to: event.target.value,
                                                        });
                                                        onSegment(null);
                                                    }}
                                                />
                                            </>
                                        )}

                                        {field.unit && (
                                            <span className="comm-filter-unit">{field.unit}</span>
                                        )}
                                    </div>
                                )}

                                <button
                                    type="button"
                                    className="comm-filter-remove"
                                    aria-label="Remove condition"
                                    onClick={() => remove(index)}
                                >
                                    <i className="ti ti-x" aria-hidden="true" />
                                </button>
                            </div>
                        );
                    })
                )}
            </div>

            {/* The whole reason this screen exists. */}
            <div className={`comm-audience-count${count?.total === 0 ? ' is-empty' : ''}`}>
                <i
                    className={preview.isPending ? 'ti ti-loader-2 comm-spin' : 'ti ti-users-group'}
                    aria-hidden="true"
                />

                <div>
                    <b>
                        {preview.isPending
                            ? 'Counting…'
                            : count === null
                              ? 'Audience unavailable'
                              : `${count.total.toLocaleString('en-IN')} ${
                                    count.total === 1 ? 'patient matches' : 'patients match'
                                }`}
                    </b>

                    {count && count.total > 0 && (
                        <small>
                            {count.breakdown.male} male · {count.breakdown.female} female ·{' '}
                            {count.breakdown.other} other
                        </small>
                    )}

                    {count?.total === 0 && (
                        <small>
                            Nobody on this channel matches. A campaign to nobody cannot be
                            scheduled.
                        </small>
                    )}
                </div>
            </div>
        </div>
    );
}
