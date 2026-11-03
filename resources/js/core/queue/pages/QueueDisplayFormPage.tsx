import { useEffect, useMemo, useState } from 'react';
import { Link, useNavigate, useParams } from 'react-router-dom';
import { Button } from '@/shared/components/ui/Button';
import { Card } from '@/shared/components/ui/Card';
import { ErrorState, LoadingBlock } from '@/shared/components/ui/Feedback';
import { PageHeader } from '@/shared/components/ui/PageHeader';
import { SearchableSelect } from '@/shared/components/form/SearchableSelect';
import { getValidationErrors, resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useQueueDisplay, useQueueDisplayOptions, useSaveQueueDisplay } from '../api';
import type { DisplayType, QueueDisplayInput } from '../types';

const TYPES: { value: DisplayType; title: string; hint: string; icon: string }[] = [
    { value: 'reception', title: 'Reception', hint: 'The waiting room: everybody, or the doctors you choose.', icon: 'ti ti-device-tv' },
    { value: 'department', title: 'Department', hint: 'One department or floor.', icon: 'ti ti-sitemap' },
    { value: 'doctor', title: 'Doctor room', hint: 'Outside one room, for one doctor.', icon: 'ti ti-door' },
    { value: 'laboratory', title: 'Laboratory', hint: 'Samples and reports at the lab.', icon: 'ti ti-flask' },
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

/** Making a television, or changing what one shows. A new one's link comes back once. */
export default function QueueDisplayFormPage() {
    const params = useParams();
    const navigate = useNavigate();
    const id = params.id ? Number(params.id) : null;

    const { data: options, isLoading: optionsLoading, isError } = useQueueDisplayOptions();
    const { data: existing, isLoading: displayLoading } = useQueueDisplay(id);
    const save = useSaveQueueDisplay(id);

    const [values, setValues] = useState<QueueDisplayInput>(EMPTY);
    const [errors, setErrors] = useState<Record<string, string>>({});
    const [ready, setReady] = useState(false);

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

    const doctors = useMemo(
        () => options?.locations.find((location) => location.id === values.location_id)?.doctors ?? [],
        [options, values.location_id],
    );

    if (optionsLoading || (id !== null && displayLoading) || !ready) {
        return <LoadingBlock label="Loading…" />;
    }

    if (isError || !options) {
        return <ErrorState message="Unable to load this form." />;
    }

    const soloDoctor = values.display_type === 'doctor';
    const choosesDoctors = values.display_type === 'reception' || values.display_type === 'department';

    function toggleDoctor(doctorId: number) {
        set({
            doctor_ids: values.doctor_ids.includes(doctorId)
                ? values.doctor_ids.filter((chosen) => chosen !== doctorId)
                : [...values.doctor_ids, doctorId],
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
                ? values.audio_languages.filter((chosen) => chosen !== language)
                : [...values.audio_languages, language],
        });
    }

    function submit() {
        setErrors({});

        save.mutate(
            {
                ...values,
                department_id: values.display_type === 'department' ? values.department_id : null,
                doctor_ids: values.display_type === 'laboratory' ? [] : values.doctor_ids,
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
                    } else {
                        notify.error(resolveErrorMessage(failure));
                    }
                },
            },
        );
    }

    return (
        <>
            <PageHeader
                title={id ? `Edit ${existing?.display_name ?? 'display'}` : 'New TV display'}
                subtitle="What this screen shows, and how it looks."
                icon="ti ti-device-tv"
                tone="sky"
                crumbs={[
                    { label: 'Settings' },
                    { label: 'TV displays', to: '/settings/tv-displays' },
                    { label: id ? 'Edit' : 'New' },
                ]}
            />

            <div className="sq-form">
                <Card icon="ti ti-device-tv" title="The screen" description="A name your team will recognise, and where it hangs.">
                    <div className="row g-3">
                        <div className="col-md-7">
                            <label className="form-label" htmlFor="sq-display-name">Name</label>
                            <input
                                id="sq-display-name"
                                className={`form-control${errors.display_name ? ' is-invalid' : ''}`}
                                maxLength={120}
                                value={values.display_name}
                                placeholder="Reception, OPD floor 1, Room 01…"
                                onChange={(event) => set({ display_name: event.target.value })}
                            />
                            {errors.display_name && <div className="invalid-feedback d-block">{errors.display_name}</div>}
                        </div>

                        <div className="col-md-5">
                            <label className="form-label">Branch</label>
                            <SearchableSelect
                                value={String(values.location_id)}
                                ariaLabel="Branch"
                                onChange={(next) => set({ location_id: Number(next), doctor_ids: [] })}
                                options={options.locations.map((location) => ({
                                    value: String(location.id),
                                    label: location.name,
                                }))}
                            />
                            {errors.location_id && <div className="invalid-feedback d-block">{errors.location_id}</div>}
                        </div>
                    </div>

                    <label className="form-label mt-3">Type</label>
                    <div className="sq-types" role="radiogroup" aria-label="Type">
                        {TYPES.map((type) => (
                            <button
                                type="button"
                                key={type.value}
                                role="radio"
                                aria-checked={values.display_type === type.value}
                                className={`sq-type${values.display_type === type.value ? ' is-on' : ''}`}
                                onClick={() => set({ display_type: type.value, doctor_ids: [] })}
                            >
                                <i className={type.icon} aria-hidden="true" />
                                <b>{type.title}</b>
                                <span>{type.hint}</span>
                            </button>
                        ))}
                    </div>
                </Card>

                {values.display_type !== 'laboratory' && (
                    <Card
                        icon="ti ti-stethoscope"
                        title={soloDoctor ? 'Which doctor' : 'Which doctors'}
                        description={
                            soloDoctor
                                ? 'This screen shows one doctor and nobody else.'
                                : 'Choose none to show everybody who is sitting today, or pick the ones this screen is for.'
                        }
                    >
                        {values.display_type === 'department' && (
                            <div className="mb-3">
                                <label className="form-label">Department</label>
                                <SearchableSelect
                                    value={values.department_id ? String(values.department_id) : ''}
                                    ariaLabel="Department"
                                    placeholder="Choose a department"
                                    onChange={(next) => set({ department_id: next === '' ? null : Number(next) })}
                                    options={options.departments.map((department) => ({
                                        value: String(department.id),
                                        label: department.name,
                                    }))}
                                />
                                {errors.department_id && <div className="invalid-feedback d-block">{errors.department_id}</div>}
                            </div>
                        )}

                        {soloDoctor ? (
                            <SearchableSelect
                                value={values.doctor_ids[0] ? String(values.doctor_ids[0]) : ''}
                                ariaLabel="Doctor"
                                placeholder="Choose a doctor"
                                onChange={(next) => set({ doctor_ids: next === '' ? [] : [Number(next)] })}
                                options={doctors.map((doctor) => ({
                                    value: String(doctor.id),
                                    label: doctor.name ?? 'Unnamed',
                                    hint: doctor.department ?? undefined,
                                }))}
                            />
                        ) : (
                            choosesDoctors &&
                            (doctors.length === 0 ? (
                                <p className="sq-note">Nobody is posted to this branch yet.</p>
                            ) : (
                                <div className="sq-doctor-picks">
                                    {doctors
                                        .filter(
                                            (doctor) =>
                                                values.display_type !== 'department' ||
                                                !values.department_id ||
                                                doctor.department_id === values.department_id,
                                        )
                                        .map((doctor) => (
                                            <label className="sq-pick" key={doctor.id}>
                                                <input
                                                    type="checkbox"
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
                            ))
                        )}
                        {errors.doctor_ids && <div className="invalid-feedback d-block">{errors.doctor_ids}</div>}
                    </Card>
                )}

                <Card icon="ti ti-palette" title="How it looks" description="Made to be read from across a room.">
                    <div className="row g-3">
                        {values.display_type !== 'doctor' && values.display_type !== 'laboratory' && (
                            <div className="col-md-6">
                                <label className="form-label">Layout</label>
                                <div className="sq-lengths" role="radiogroup" aria-label="Layout">
                                    {(['grid', 'list'] as const).map((layout) => (
                                        <button
                                            type="button"
                                            key={layout}
                                            role="radio"
                                            aria-checked={values.layout === layout}
                                            className={`sq-length${values.layout === layout ? ' is-on' : ''}`}
                                            onClick={() => set({ layout })}
                                        >
                                            {layout === 'grid' ? 'Cards' : 'List'}
                                        </button>
                                    ))}
                                </div>
                            </div>
                        )}

                        <div className="col-md-6">
                            <label className="form-label">Theme</label>
                            <div className="sq-lengths" role="radiogroup" aria-label="Theme">
                                {(['dark', 'light'] as const).map((theme) => (
                                    <button
                                        type="button"
                                        key={theme}
                                        role="radio"
                                        aria-checked={values.theme === theme}
                                        className={`sq-length${values.theme === theme ? ' is-on' : ''}`}
                                        onClick={() => set({ theme })}
                                    >
                                        {theme === 'dark' ? 'Dark' : 'Light'}
                                    </button>
                                ))}
                            </div>
                        </div>
                    </div>

                    <div className="sq-switches">
                        <label className="form-check form-switch">
                            <input
                                className="form-check-input"
                                type="checkbox"
                                checked={values.audio_enabled}
                                onChange={(event) => set({ audio_enabled: event.target.checked })}
                            />
                            <span className="form-check-label">
                                <b>Announce tokens out loud</b>
                                <small>A chime and a voice. The television asks for one tap to allow sound.</small>
                            </span>
                        </label>
                    </div>

                    {values.audio_enabled && (
                        <div className="sq-languages">
                            <span className="form-label mb-0">Languages</span>
                            {(['en', 'hi'] as const).map((language) => (
                                <label className="sq-pick is-inline" key={language}>
                                    <input
                                        type="checkbox"
                                        className="form-check-input"
                                        checked={values.audio_languages.includes(language)}
                                        onChange={() => toggleLanguage(language)}
                                    />
                                    <span>
                                        <b>{language === 'en' ? 'English' : 'Hindi'}</b>
                                    </span>
                                </label>
                            ))}
                        </div>
                    )}
                </Card>

                <div className="sq-form-foot">
                    <Link className="btn btn-light" to="/settings/tv-displays">
                        Cancel
                    </Link>
                    <Button disabled={save.isPending} onClick={submit}>
                        {id ? 'Save display' : 'Create display'}
                    </Button>
                </div>
            </div>
        </>
    );
}
