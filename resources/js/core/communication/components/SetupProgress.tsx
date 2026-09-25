import { Card } from '@/shared/components/ui/Card';
import { Button } from '@/shared/components/ui/Button';
import type { SetupStep } from '../types';

/**
 * How far the connection got, and the one thing still outstanding.
 *
 * Every step is DERIVED on the server from the thing it describes — the
 * template step asks whether an approved template exists, the test step
 * whether anything has ever been sent. A stored checklist can say "done"
 * about a number that has since been disconnected; this cannot.
 */
export function SetupProgress({
    steps,
    onFinish,
    finishLabel = 'Complete setup',
}: {
    steps: SetupStep[];
    /** Absent where the person may read the checklist but not act on it. */
    onFinish?: () => void;
    finishLabel?: string;
}) {
    const done = steps.filter((step) => step.done).length;
    const complete = done === steps.length;

    return (
        <Card
            className="comm-card"
            title="Setup progress"
            icon="ti ti-list-check"
            actions={
                <span className="comm-count">
                    {done}/{steps.length}
                </span>
            }
        >
            <ol className="comm-steps">
                {steps.map((step, index) => (
                    <li key={step.key} className={step.done ? 'is-done' : undefined}>
                        <i
                            className={step.done ? 'ti ti-circle-check-filled' : 'ti ti-circle'}
                            aria-hidden="true"
                        />

                        <span>
                            {index + 1}. {step.label}
                        </span>

                        <b>{step.state}</b>
                    </li>
                ))}
            </ol>

            {/* Nothing left to do is not a call to action. */}
            {!complete && onFinish && (
                <Button className="w-100 mt-3" icon="ti ti-arrow-right" onClick={onFinish}>
                    {finishLabel}
                </Button>
            )}
        </Card>
    );
}
