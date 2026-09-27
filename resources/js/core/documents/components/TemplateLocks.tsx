import { useEffect, useState } from 'react';
import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import { resolveErrorMessage } from '@/shared/api/http';
import { notify } from '@/shared/utils/notify';
import { useLockablePaths, useSaveLocks } from '../templates';

/**
 * What a branch may not change.
 *
 * ONLY ON THE ORGANISATION'S OWN DEFAULT, and only for somebody holding
 * `documents.template_org` — this is the whole authority model in one panel.
 * A lock on `header` covers everything beneath it, which is what "the whole
 * header" means: a branch cannot reword the registered clinic name or the
 * licence number, because those are the organisation's to state.
 *
 * Locks live on the PUBLISHED version, so locking takes effect for branches
 * as soon as it is saved — unlike a config change, which waits for a publish.
 */
export function TemplateLocks({
    templateId,
    current,
}: {
    templateId: number;
    current: string[];
}) {
    const { data: paths } = useLockablePaths();
    const save = useSaveLocks();

    const [chosen, setChosen] = useState<string[]>(current);
    const [error, setError] = useState<string | null>(null);

    useEffect(() => {
        setChosen(current);
        // eslint-disable-next-line react-hooks/exhaustive-deps
    }, [templateId, current.join(',')]);

    function toggle(path: string) {
        setChosen((was) =>
            was.includes(path) ? was.filter((p) => p !== path) : [...was, path],
        );
    }

    async function submit() {
        setError(null);

        try {
            await save.mutateAsync({ id: templateId, paths: chosen });
            notify.success('Locks saved. Branches cannot change these.');
        } catch (failure) {
            setError(resolveErrorMessage(failure));
        }
    }

    const dirty =
        chosen.length !== current.length || chosen.some((p) => !current.includes(p));

    return (
        <Card className="mt-3">
            <h6 className="mb-1">
                <i className="ti ti-lock me-2" />
                Locked for branches
            </h6>
            <p className="dr-sub mb-3">
                A branch keeps its own letterhead, but cannot change what you tick here.
            </p>

            {error && <div className="alert alert-danger py-2">{error}</div>}

            {(paths ?? []).map((entry) => (
                <label className="form-check mb-2" key={entry.path}>
                    <input
                        type="checkbox"
                        className="form-check-input"
                        checked={chosen.includes(entry.path)}
                        onChange={() => toggle(entry.path)}
                    />
                    <span className="form-check-label fs-13">{entry.label}</span>
                </label>
            ))}

            <Button
                size="sm"
                className="mt-2"
                type="button"
                onClick={() => void submit()}
                disabled={!dirty || save.isPending}
            >
                {save.isPending ? 'Saving…' : 'Save locks'}
            </Button>
        </Card>
    );
}
