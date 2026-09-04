import { useState } from 'react';
import { Modal } from '@/shared/components/ui/Modal';
import { Button } from '@/shared/components/ui/Button';
import { LoadingBlock } from '@/shared/components/ui/Feedback';
import { HistoryTimeline } from '@/shared/components/ui/HistoryTimeline';
import { useRecordHistory } from './api';

/**
 * One record's own story, on demand.
 *
 * A button and a dialog rather than a panel on the form: somebody editing a
 * customer is doing that, and the history is what they open when a value
 * looks wrong. Rendering it inline would fetch a log nobody asked for on
 * every edit, and push the form's own fields down the page.
 *
 * `entity` is the model's class name as the log stores it — "Customer",
 * "Location", "User".
 */
export function RecordHistory({
    entity,
    id,
    label,
}: {
    entity: string;
    id: number;
    /** What the record is called, for the dialog's title. */
    label?: string;
}) {
    const [open, setOpen] = useState(false);

    // Fetched only once the dialog has been opened.
    const { data, isLoading } = useRecordHistory(entity, open ? id : null);

    return (
        <>
            <Button variant="light" icon="ti ti-history" onClick={() => setOpen(true)}>
                History
            </Button>

            <Modal
                open={open}
                onClose={() => setOpen(false)}
                title={label ? `History — ${label}` : 'History'}
                subtitle="Every change to this record, field by field. These entries cannot be edited or removed."
                size="lg"
                icon={<i className="ti ti-history" />}
                footer={
                    <Button variant="secondary" onClick={() => setOpen(false)}>
                        Close
                    </Button>
                }
            >
                {isLoading ? (
                    <LoadingBlock label="Loading history…" />
                ) : (
                    <HistoryTimeline
                        entries={data?.data ?? []}
                        empty="Nothing has changed since this record was created."
                    />
                )}
            </Modal>
        </>
    );
}
