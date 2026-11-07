import { useEffect, useMemo, useState, type ReactNode } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { useTenantAuth } from '@/core/tenant-auth/TenantAuthProvider';
import { cn } from '@/shared/utils/cn';
import { notify } from '@/shared/utils/notify';
import { useDisplayPreview, useQueueDisplay, useQueueDisplayOptions, useSaveQueueDisplay } from '../api';
import { DisplayTvPreview } from '../components/DisplayTvPreview';
import type { DisplayType, QueueDisplay, QueueDisplayInput } from '../types';

const TYPES: { value: DisplayType; title: string; hint: string; icon: string }[] = [
    { value: 'reception', title: 'Reception', hint: 'Waiting room for everyone.', icon: 'ti ti-device-desktop' },
    { value: 'department', title: 'Department', hint: 'One department or floor.', icon: 'ti ti-users' },
    { value: 'doctor', title: 'Doctor Room', hint: 'For one specific doctor.', icon: 'ti ti-door' },
    { value: 'laboratory', title: 'Laboratory', hint: 'Samples and reports at the lab.', icon: 'ti ti-flask' },
];

const LANGUAGES: { value: 'en' | 'hi'; label: string }[] = [
    { value: 'en', label: 'English' },
    { value: 'hi', label: 'Hindi' },
];

const EMPTY: QueueDisplayInput = {
    display_name: '',
    display_type: 'reception',
    location_id: 0,
    department_id: null,
    doctor_ids: [],
    layout: 'list',
    theme: 'light',
    audio_enabled: false,
    audio_languages: ['en'],
    is_active: true,
    expires_at: null,
};

/** Whether the screen will actually run, as the list says it. */
function StatusPill({ display }: { display: QueueDisplay }) {
    if (display.refusal) {
        const words = { revoked: 'Revoked', expired: 'Expired', inactive: 'Switched Off' };

        return <span className="tvd-pill tvd-pill--off">{words[display.refusal]}</span>;
    }

    return (
        <span className="tvd-pill tvd-pill--ok">
            <span className="tvd-pill-dot" />
            Active
        </span>
    );
}

function Segments<T extends string>({
    label,
    value,
    options,
    onChange,
}: {
    label: string;
    value: T;
    options: { value: T; label: string; icon: string }[];
    onChange(next: T): void;
}) {
    return (
        <div className="tvd-seg" role="radiogroup" aria-label={label}>
            {options.map((option) => (
                <button
                    type="button"
                    key={option.value}
                    role="radio"
                    aria-checked={value === option.value}
                    className={cn('tvd-seg-btn', value === option.value && 'is-on')}
                    onClick={() => onChange(option.value)}
                >
                    <i className={option.icon} aria-hidden="true" />
                    {option.label}
                </button>
            ))}
        </div>
    );
}

function SummaryRow({ label, children }: { label: string; children: ReactNode }) {
    return (
        <div className="tvd-sum-row">
            <dt>{label}</dt>
            <dd>{children}</dd>
        </div>
    );
}

/** Making a television, or changing what one shows. A new one's link comes back once. */
export default function QueueDisplayFormPage() {
    const params = useParams();
    const navigate = useNavigate();
    const id = params.id ? Number(params.id) : null;

    const { organization } = useTenantAuth();
    const { data: options, isLoading: optionsLoading, isError } = useQueueDisplayOptions();
    const { data: existing, isLoading: displayLoading } = useQueueDisplay(id);
    const { data: live } = useDisplayPreview(id);
    const save = useSaveQueueDisplay(id);

    const [values, setValues] = useState<QueueDisplayInput>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [ready, setReady] = useState(false);
    const [search, setSearch] = useState('');
    const [departmentFilter, setDepartmentFilter] = useState('');

    useEffect(() => {
        if (ready || !options) {
            return;
        }

        if (id === null) {
            setValues((current) => ({ ...current, location_id: options.locations[0]?.id ?? 0 }));
            setReady(true);
        } else if (existing) {
            setValues({
                display_name: existing.display_name,
                display_type: existing.display_type,
                location_id: existing.location_id,
                department_id: existing.department_id,
                doctor_ids: existing.doctor_ids,
                layout: existing.layout,
                theme: existing.theme,
                audio_enabled: existing.audio_enabled,
                audio_languages: existing.audio_languages,
                is_active: existing.is_active,
                expires_at: existing.expires_at,
            });
            setReady(true);
        }
    }, [existing, id, options, ready]);

    const set = (change: Partial<QueueDisplayInput>) => setValues((current) => ({ ...current, ...change }));

    const branchDoctors = useMemo(
        () => options?.locations.find((location) => location.id === values.location_id)?.doctors ?? [],
        [options, values.location_id],
    );

    /** Who this type of screen may show at all. A department's screen waits for its department. */
    const pickable = useMemo(() => {
        if (values.display_type !== 'department') {
            return branchDoctors;
        }

        return branchDoctors.filter((doctor) => doctor.department_id === values.department_id);
    }, [branchDoctors, values.display_type, values.department_id]);

    const visible = useMemo(() => {
        const needle = search.trim().toLowerCase();

        return pickable.filter((doctor) => {
            if (departmentFilter !== '' && doctor.department !== departmentFilter) {
                return false;
            }

            return (
                needle === '' ||
                (doctor.name ?? '').toLowerCase().includes(needle) ||
                (doctor.department ?? '').toLowerCase().includes(needle)
            );
        });
    }, [pickable, departmentFilter, search]);

    const departmentNames = useMemo(
        () =>
            [...new Set(branchDoctors.map((doctor) => doctor.department).filter((name): name is string => Boolean(name)))].sort(),
        [branchDoctors],
    );

    if (optionsLoading || (id !== null && displayLoading) || !ready) {
        return <LoadingBlock label="Loading…" />;
    }

    if (isError || !options) {
        return <ErrorState message="Unable to load this form." />;
    }

    const type = values.display_type;
    const soloDoctor = type === 'doctor';
    const isLab = type === 'laboratory';
    const showsLayout = !soloDoctor && !isLab;
    const typeTitle = TYPES.find((option) => option.value === type)?.title;
    const branchName = options.locations.find((location) => location.id === values.location_id)?.name;
    const departmentName = options.departments.find((department) => department.id === values.department_id)?.name;

    const visibleIds = visible.map((doctor) => doctor.id);
    const allVisibleOn = visibleIds.length > 0 && visibleIds.every((doctorId) => values.doctor_ids.includes(doctorId));
    const someVisibleOn = visibleIds.some((doctorId) => values.doctor_ids.includes(doctorId));

    // None chosen on a shared screen means everybody, which is what the TV will do too.
    const chosen = pickable.filter((doctor) => values.doctor_ids.includes(doctor.id));
    const onScreen = chosen.length > 0 || soloDoctor ? chosen : pickable;

    // The saved screen's queue is another branch's the moment the branch changes.
    const sameBranch = existing?.location_id === values.location_id;

    function changeType(next: DisplayType) {
        set({ display_type: next, doctor_ids: [] });
        setSearch('');
        setDepartmentFilter('');
    }

    function changeBranch(next: string) {
        set({ location_id: Number(next), doctor_ids: [] });
        setSearch('');
        setDepartmentFilter('');
    }

    function changeDepartment(next: string) {
        const departmentId = next === '' ? null : Number(next);

        // A doctor from the old department must not stay ticked out of sight.
        set({
            department_id: departmentId,
            doctor_ids: values.doctor_ids.filter(
                (doctorId) => branchDoctors.find((doctor) => doctor.id === doctorId)?.department_id === departmentId,
            ),
        });
    }

    function toggleDoctor(doctorId: number) {
        if (soloDoctor) {
            set({ doctor_ids: [doctorId] });

            return;
        }

        set({
            doctor_ids: values.doctor_ids.includes(doctorId)
                ? values.doctor_ids.filter((other) => other !== doctorId)
                : [...values.doctor_ids, doctorId],
        });
    }

    /** Only those in view: "Select All" under a search means all of what the search found. */
    function toggleAllVisible() {
        set({
            doctor_ids: allVisibleOn
                ? values.doctor_ids.filter((doctorId) => !visibleIds.includes(doctorId))
                : [...new Set([...values.doctor_ids, ...visibleIds])],
        });
    }

    function toggleLanguage(language: 'en' | 'hi') {
        const has = values.audio_languages.includes(language);

        // Sound on with no language at all would be a silent promise.
        if (has && values.audio_languages.length === 1) {
            return;
        }

        set({
            audio_languages: has
                ? values.audio_languages.filter((other) => other !== language)
                : [...values.audio_languages, language],
        });
    }

    function submit() {
        setErrors({});

        save.mutate(
            {
                ...values,
                department_id: type === 'department' ? values.department_id : null,
                doctor_ids: isLab ? [] : values.doctor_ids,
            },
            {
                onSuccess: (response) => {
                    notify.success(response.message ?? 'Saved');
                    navigate('/settings/tv-displays', { state: response.data.url ? { link: response.data.url } : null });
                },
                onError: (failure) => {
                    const fields = getValidationErrors(failure);

                    if (fields && Object.keys(fields).length > 0) {
                        setErrors(
                            Object.fromEntries(
                                Object.entries(fields).map(([field, messages]) => [field.split('.')[0], messages[0]]),
                            ),
                        );

                        // The field may be off screen; the Save button stays in view.
                        notify.error(Object.values(fields)[0][0]);
                    } else {
                        notify.error(resolveErrorMessage(failure));
                    }
                },
            },
        );
    }

    let doctorsSummary: ReactNode = chosen.length;

    if (soloDoctor) {
        doctorsSummary = chosen[0]?.name ?? 'Not chosen';
    } else if (chosen.length === 0) {
        doctorsSummary = type === 'department' ? (departmentName ? `Everyone in ${departmentName}` : 'Not chosen') : 'Everyone sitting today';
    }

    let previewEmpty = 'Nobody is posted to this branch yet.';

    if (type === 'department' && !values.department_id) {
        previewEmpty = 'Choose a department.';
    } else if (soloDoctor) {
        previewEmpty = 'Choose a doctor.';
    } else if (type === 'department') {
        previewEmpty = 'Nobody from this department is posted here.';
    }

    return (
        <div className="tvd-page">
            <PageHeader
                title={existing ? `Edit Display – ${existing.display_name}` : 'New TV Display'}
                icon="ti ti-device-desktop"
                back="/settings/tv-displays"
                home={false}
                crumbs={[
                    { label: 'Settings' },
                    { label: 'TV Displays', to: '/settings/tv-displays' },
                    { label: id ? 'Edit' : 'New' },
                ]}
                actions={existing && <StatusPill display={existing} />}
            />

            <div className="tvd-grid">
                <div className="tvd-col">
                    <Card
                        icon="ti ti-info-circle"
                        title="Basic Information"
                        description="Set a name and branch for this TV display."
                    >
                        <div className="row g-3">
                            <div className="col-md-6">
                                <label className="form-label" htmlFor="sq-display-name">
                                    Display Name<span className="text-danger ms-1">*</span>
                                </label>
                                <input
                                    id="sq-display-name"
                                    className={cn('form-control', errors.display_name && 'is-invalid')}
                                    maxLength={120}
                                    value={values.display_name}
                                    placeholder="Reception, OPD floor 1, Room 01…"
                                    onChange={(event) => set({ display_name: event.target.value })}
                                />
                                {errors.display_name && <div className="invalid-feedback d-block">{errors.display_name}</div>}
                            </div>

                            <div className="col-md-6">
                                <label className="form-label">
                                    Branch<span className="text-danger ms-1">*</span>
                                </label>
                                <div className="tvd-select-icon">
                                    <i className="ti ti-building-hospital" aria-hidden="true" />
                                    <SearchableSelect
                                        value={String(values.location_id)}
                                        ariaLabel="Branch"
                                        invalid={Boolean(errors.location_id)}
                                        onChange={changeBranch}
                                        options={options.locations.map((location) => ({
                                            value: String(location.id),
                                            label: location.name,
                                        }))}
                                    />
                                </div>
                                {errors.location_id && <div className="invalid-feedback d-block">{errors.location_id}</div>}
                            </div>
                        </div>
                    </Card>

                    <Card icon="ti ti-stack-2" title="Display Type" description="Choose where this screen will be used.">
                        <div className="tvd-types" role="radiogroup" aria-label="Display type">
                            {TYPES.map((option) => (
                                <button
                                    type="button"
                                    key={option.value}
                                    role="radio"
                                    aria-checked={type === option.value}
                                    className={cn('tvd-type', type === option.value && 'is-on')}
                                    onClick={() => changeType(option.value)}
                                >
                                    <i className={cn(option.icon, 'tvd-type-icon')} aria-hidden="true" />
                                    <b>{option.title}</b>
                                    <span>{option.hint}</span>
                                    {type === option.value && (
                                        <i className="ti ti-check tvd-type-check" aria-hidden="true" />
                                    )}
                                </button>
                            ))}
                        </div>
                    </Card>

                    {!isLab && (
                        <Card
                            icon="ti ti-stethoscope"
                            title={soloDoctor ? 'Select Doctor' : 'Select Doctors'}
                            description={
                                soloDoctor
                                    ? 'This screen shows one doctor and nobody else.'
                                    : 'Choose whose queue this display shows. Tick none to show everyone sitting today.'
                            }
                            actions={
                                !soloDoctor && visible.length > 0 ? (
                                    <label className="tvd-select-all">
                                        <input
                                            type="checkbox"
                                            className="form-check-input"
                                            checked={allVisibleOn}
                                            ref={(input) => {
                                                if (input) {
                                                    input.indeterminate = someVisibleOn && !allVisibleOn;
                                                }
                                            }}
                                            onChange={toggleAllVisible}
                                        />
                                        Select All ({visible.length})
                                    </label>
                                ) : undefined
                            }
                        >
                            <div className="tvd-filters">
                                <div className="tvd-search">
                                    <i className="ti ti-search" aria-hidden="true" />
                                    <input
                                        className="form-control"
                                        value={search}
                                        placeholder="Search doctor by name or department…"
                                        aria-label="Search doctors"
                                        onChange={(event) => setSearch(event.target.value)}
                                    />
                                </div>

                                <div className="tvd-select-icon tvd-filter-select">
                                    <i className="ti ti-filter" aria-hidden="true" />
                                    {type === 'department' ? (
                                        <SearchableSelect
                                            value={values.department_id ? String(values.department_id) : ''}
                                            ariaLabel="Department"
                                            placeholder="Choose a department"
                                            invalid={Boolean(errors.department_id)}
                                            onChange={changeDepartment}
                                            options={options.departments.map((department) => ({
                                                value: String(department.id),
                                                label: department.name,
                                            }))}
                                        />
                                    ) : (
                                        <SearchableSelect
                                            value={departmentFilter}
                                            ariaLabel="Filter by department"
                                            onChange={setDepartmentFilter}
                                            options={[
                                                { value: '', label: 'All Departments' },
                                                ...departmentNames.map((name) => ({ value: name, label: name })),
                                            ]}
                                        />
                                    )}
                                </div>
                            </div>

                            {errors.department_id && <div className="invalid-feedback d-block">{errors.department_id}</div>}

                            {visible.length === 0 ? (
                                <p className="tvd-none">
                                    {type === 'department' && !values.department_id
                                        ? 'Choose a department to see its doctors.'
                                        : pickable.length === 0
                                          ? 'Nobody is posted here yet.'
                                          : 'No doctor matches this search.'}
                                </p>
                            ) : (
                                <div className="tvd-doctors">
                                    {visible.map((doctor) => (
                                        <label
                                            className={cn('tvd-doctor', values.doctor_ids.includes(doctor.id) && 'is-on')}
                                            key={doctor.id}
                                        >
                                            <input
                                                type={soloDoctor ? 'radio' : 'checkbox'}
                                                name={soloDoctor ? 'tvd-solo-doctor' : undefined}
                                                className="form-check-input"
                                                checked={values.doctor_ids.includes(doctor.id)}
                                                onChange={() => toggleDoctor(doctor.id)}
                                            />
                                            <span>
                                                <b>{doctor.name}</b>
                                                {doctor.department && <small>{doctor.department}</small>}
                                            </span>
                                        </label>
                                    ))}
                                </div>
                            )}

                            {errors.doctor_ids && <div className="invalid-feedback d-block">{errors.doctor_ids}</div>}
                        </Card>
                    )}

                    <Card icon="ti ti-settings" title="Display Settings" description="Configure layout, theme, audio and language.">
                        <div className="tvd-settings">
                            <div className="tvd-settings-row">
                            {showsLayout && (
                                <div className="tvd-setting">
                                    <span className="tvd-setting-label">Layout</span>
                                    <Segments
                                        label="Layout"
                                        value={values.layout}
                                        onChange={(layout) => set({ layout })}
                                        options={[
                                            { value: 'grid', label: 'Cards', icon: 'ti ti-layout-grid' },
                                            { value: 'list', label: 'List', icon: 'ti ti-list' },
                                        ]}
                                    />
                                </div>
                            )}

                            <div className="tvd-setting">
                                <span className="tvd-setting-label">Theme</span>
                                <Segments
                                    label="Theme"
                                    value={values.theme}
                                    onChange={(theme) => set({ theme })}
                                    options={[
                                        { value: 'light', label: 'Light', icon: 'ti ti-sun' },
                                        { value: 'dark', label: 'Dark', icon: 'ti ti-moon' },
                                    ]}
                                />
                            </div>

                            <div className="tvd-setting">
                                <span className="tvd-setting-label">Audio Announcement</span>
                                <label className="form-check form-switch tvd-audio">
                                    <input
                                        className="form-check-input"
                                        type="checkbox"
                                        checked={values.audio_enabled}
                                        onChange={(event) => set({ audio_enabled: event.target.checked })}
                                    />
                                    <span>Announce tokens out loud (with chime and voice). The TV asks for one tap to allow sound.</span>
                                </label>
                            </div>

                            <div className={cn('tvd-setting', !values.audio_enabled && 'is-off')}>
                                <span className="tvd-setting-label">Languages</span>
                                <div className="tvd-languages">
                                    {LANGUAGES.map((language) => (
                                        <label key={language.value}>
                                            <input
                                                type="checkbox"
                                                className="form-check-input"
                                                disabled={!values.audio_enabled}
                                                checked={values.audio_languages.includes(language.value)}
                                                onChange={() => toggleLanguage(language.value)}
                                            />
                                            {language.label}
                                        </label>
                                    ))}
                                </div>
                            </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <div className="tvd-col">
                    <Card icon="ti ti-eye" title="Live Preview" description="See how this display will look on the TV.">
                        <DisplayTvPreview
                            type={type}
                            layout={values.layout}
                            theme={values.theme}
                            organization={organization ? { name: organization.name, logo_url: organization.has_logo ? organization.logo_url : null } : null}
                            doctors={onScreen}
                            cards={sameBranch ? (live?.cards ?? []) : []}
                            lab={sameBranch ? (live?.lab ?? null) : null}
                            emptyMessage={previewEmpty}
                        />
                    </Card>

                    <Card icon="ti ti-chart-bar" title="Summary" className="tvd-summary">
                        <dl className="tvd-sum">
                            <SummaryRow label="Display Name">{values.display_name.trim() || '—'}</SummaryRow>
                            <SummaryRow label="Branch">{branchName ?? '—'}</SummaryRow>
                            <SummaryRow label="Type">
                                <span className="tvd-pill tvd-pill--brand">{typeTitle}</span>
                            </SummaryRow>
                            {!isLab && (
                                <SummaryRow label={soloDoctor ? 'Doctor' : 'Doctors Selected'}>{doctorsSummary}</SummaryRow>
                            )}
                            {showsLayout && (
                                <SummaryRow label="Layout">{values.layout === 'grid' ? 'Cards' : 'List'}</SummaryRow>
                            )}
                            <SummaryRow label="Theme">
                                <span className="tvd-pill tvd-pill--brand">
                                    <i className={values.theme === 'light' ? 'ti ti-sun' : 'ti ti-moon'} aria-hidden="true" />
                                    {values.theme === 'light' ? 'Light' : 'Dark'}
                                </span>
                            </SummaryRow>
                            <SummaryRow label="Audio Announcement">
                                {values.audio_enabled ? (
                                    <span className="tvd-pill tvd-pill--ok">
                                        <span className="tvd-pill-dot" />
                                        Enabled
                                    </span>
                                ) : (
                                    <span className="tvd-pill tvd-pill--off">Off</span>
                                )}
                            </SummaryRow>
                            {values.audio_enabled && (
                                <SummaryRow label="Languages">
                                    {LANGUAGES.filter((language) => values.audio_languages.includes(language.value))
                                        .map((language) => language.label)
                                        .join(', ')}
                                </SummaryRow>
                            )}
                        </dl>
                    </Card>
                </div>
            </div>

            <div className="tvd-foot">
                <Link className="btn tvd-cancel" to="/settings/tv-displays">
                    Cancel
                </Link>
                <Button icon="ti ti-check" loading={save.isPending} onClick={submit}>
                    {id ? 'Save Display' : 'Create Display'}
                </Button>
            </div>
        </div>
    );
}
